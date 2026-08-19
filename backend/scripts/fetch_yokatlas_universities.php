<?php

declare(strict_types=1);

use DersRotasi\Config\Env;
use DersRotasi\Database\Connection;
use DersRotasi\Historical\HistoricalProgramMatcher;
use DersRotasi\Osym\OsymHistoricalValueParser;
use DersRotasi\Osym\OsymSpreadsheetParser;
use DersRotasi\Yokatlas\CurrentUniversityMapper;
use DersRotasi\Yokatlas\YokatlasClient;
use DersRotasi\Yokatlas\YokatlasStorage;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

const PAGE_SIZE = 100;
const MAX_PROGRAMS = 25000;
const CSV_HEADERS = [
    'program_code', 'university_name', 'faculty_name', 'department_name', 'city',
    'university_type', 'score_type', 'education_type', 'education_language',
    'scholarship_type', 'base_score', 'base_rank', 'quota', 'placed_count',
    'duration_years', 'year', 'source_name', 'source_url',
];
const CACHE_FIELDS = [
    'yil', 'kilavuzKodu', 'universiteAdi', 'fymkAdi', 'birimAdi', 'birimGrupAdi',
    'birimTuruAdi', 'ilAdi', 'uniIlAdi', 'fymkIlAdi', 'universiteTuru', 'puanTuru',
    'ogrenimTuruAdi', 'ogrenimDiliAdi', 'bursOraniAdi', 'ogrenimSuresi',
    'kontenjan', 'gkY', 'minPuan', 'basariSirasi', 'minPuan1', 'basariSirasi1',
];

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Bu araç yalnızca komut satırından çalıştırılabilir.\n");
    exit(1);
}
ini_set('memory_limit', '512M');

function optionValue(string $argument, string $name): ?string
{
    $prefix = '--' . $name . '=';
    return str_starts_with($argument, $prefix) ? substr($argument, strlen($prefix)) : null;
}

function integerOption(?string $value, string $name, int $minimum, int $maximum): int
{
    if ($value === null || filter_var($value, FILTER_VALIDATE_INT) === false) {
        throw new RuntimeException("--{$name} geçerli bir tam sayı olmalıdır.");
    }
    $number = (int) $value;
    if ($number < $minimum || $number > $maximum) {
        throw new RuntimeException("--{$name} {$minimum}-{$maximum} aralığında olmalıdır.");
    }
    return $number;
}

/** @return array<string, mixed> */
function sanitizedItem(array $item): array
{
    return array_intersect_key($item, array_flip(CACHE_FIELDS));
}

/** @return list<string> */
function csvValues(array $row): array
{
    return array_map(
        static fn (string $field): string => $row[$field] === null ? '' : (string) $row[$field],
        CSV_HEADERS,
    );
}

/** @return array<string, int|string> */
function osymSource(int $table): array
{
    $filename = "2026_result_table{$table}.xlsx";
    $url = $table === 3
        ? 'https://cdn.osym.gov.tr/en-kucuk-ve-en-buyuk-puanlar-tablo-3-0wptq7-18092428.xlsx'
        : 'https://cdn.osym.gov.tr/en-kucuk-ve-en-buyuk-puanlar-tablo-4-rps0lq-18092428.xlsx';
    return [
        'historical_year' => 2026,
        'kind' => 'result',
        'table' => $table,
        'filename' => $filename,
        'url' => $url,
        'label' => "ÖSYM 2026 YKS Yerleştirme Sonuçları Tablo-{$table}",
    ];
}

function sameNullableNumber(mixed $left, mixed $right, float $tolerance = 0.0): bool
{
    if ($left === null || $right === null) {
        return $left === null && $right === null;
    }
    return abs((float) $left - (float) $right) <= $tolerance;
}

function comparableText(mixed $value): string
{
    $text = mb_strtolower(trim((string) $value), 'UTF-8');
    $text = strtr($text, [
        'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'i̇' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u',
        'â' => 'a', 'î' => 'i', 'û' => 'u', 'é' => 'e', '&' => ' ve ',
    ]);
    $text = preg_replace('/[^a-z0-9]+/u', ' ', $text) ?? $text;
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

$options = [
    'year' => 2026,
    'limit' => MAX_PROGRAMS,
    'delay_ms' => 1000,
    'write' => false,
    'refresh' => false,
    'output' => 'storage/imports/universities_2026.csv',
];

try {
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--dry-run') {
            $options['write'] = false;
        } elseif ($argument === '--write') {
            $options['write'] = true;
        } elseif ($argument === '--refresh') {
            $options['refresh'] = true;
        } elseif (($value = optionValue($argument, 'year')) !== null) {
            $options['year'] = integerOption($value, 'year', 2026, 2026);
        } elseif (($value = optionValue($argument, 'limit')) !== null) {
            $options['limit'] = integerOption($value, 'limit', 1, MAX_PROGRAMS);
        } elseif (($value = optionValue($argument, 'delay-ms')) !== null) {
            $options['delay_ms'] = integerOption($value, 'delay-ms', 1000, 60000);
        } elseif (($value = optionValue($argument, 'output')) !== null) {
            if (preg_match('#^storage[\\/]imports[\\/][A-Za-z0-9_.-]+\.csv$#', $value) !== 1) {
                throw new RuntimeException('--output storage/imports altında bir CSV olmalıdır.');
            }
            $options['output'] = str_replace('\\', '/', $value);
        } else {
            throw new RuntimeException('Bilinmeyen seçenek: ' . $argument);
        }
    }

    $root = dirname(__DIR__);
    Dotenv::createImmutable($root)->safeLoad();
    $env = new Env($_ENV);
    if ($env->appEnv() !== 'local'
        || !in_array($env->dbHost(), ['127.0.0.1', 'localhost'], true)
        || $env->instanceConnectionName() !== null
        || $env->dbName() !== 'dersrotasi') {
        throw new RuntimeException('2026 exportu yalnız local dersrotasi veritabanıyla çalışabilir.');
    }

    $storage = new YokatlasStorage($root);
    $client = new YokatlasClient(
        $env->yokatlasUserAgent(),
        $options['delay_ms'],
        $env->sslCaBundle(),
    );
    $mapper = new CurrentUniversityMapper();
    $robots = $client->checkRobots();
    $pdo = Connection::make($env);
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->exec('START TRANSACTION READ ONLY');

    $parser = new OsymSpreadsheetParser(new OsymHistoricalValueParser());
    $osymTables = [];
    foreach ([3, 4] as $table) {
        $source = osymSource($table);
        $osymTables[] = $parser->parse(
            $root . '/storage/osym/cache/' . $source['filename'],
            $source,
        );
    }
    $osym = $parser->mergeTables($osymTables);

    $rowsByCode = [];
    $validationErrors = [];
    $duplicates = [];
    $counts = [
        'source_programs' => 0,
        'parsed_programs' => 0,
        'unique_program_codes' => 0,
        'base_score_filled' => 0,
        'base_rank_filled' => 0,
        'quota_filled' => 0,
        'placed_count_filled' => 0,
        'missing_program_code' => 0,
        'duplicate_program_code' => 0,
        'parse_errors' => 0,
        'source_errors' => 0,
        'validation_errors' => 0,
        'pages_requested' => 0,
        'pages_from_cache' => 0,
        'osym_score_backfilled' => 0,
        'osym_score_type_backfilled' => 0,
    ];
    $page = 0;
    $officialTotal = null;
    $officialPages = null;
    $fetchedAt = gmdate('Y-m-d H:i:s');
    while ($counts['source_programs'] < $options['limit']) {
        $pageData = $options['refresh']
            ? null
            : $storage->readPlacementPage($options['year'], $page, PAGE_SIZE);
        if ($pageData === null) {
            $response = $client->fetchPage($options['year'], $page, PAGE_SIZE);
            $counts['pages_requested']++;
            if ($response['status'] !== 200) {
                $counts['source_errors']++;
                throw new RuntimeException("YÖK Atlas sayfa {$page} HTTP {$response['status']} döndürdü.");
            }
            $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
            $pageData = [
                'number' => $decoded['number'] ?? null,
                'size' => $decoded['size'] ?? null,
                'totalElements' => $decoded['totalElements'] ?? null,
                'totalPages' => $decoded['totalPages'] ?? null,
                'last' => $decoded['last'] ?? null,
                'fetched_at' => gmdate('Y-m-d H:i:s'),
                'content' => array_map('sanitizedItem', $decoded['content'] ?? []),
            ];
            $storage->writePlacementPage($options['year'], $page, PAGE_SIZE, $pageData);
        } else {
            $counts['pages_from_cache']++;
        }

        if ((int) ($pageData['number'] ?? -1) !== $page
            || (int) ($pageData['size'] ?? 0) !== PAGE_SIZE
            || (int) ($pageData['totalElements'] ?? 0) < 1
            || (int) ($pageData['totalPages'] ?? 0) < 1
            || !is_array($pageData['content'] ?? null)
            || count($pageData['content']) > PAGE_SIZE) {
            throw new RuntimeException("YÖK Atlas sayfa {$page} metadata doğrulamasından geçmedi.");
        }
        $officialTotal ??= (int) $pageData['totalElements'];
        $officialPages ??= (int) $pageData['totalPages'];
        if ($officialTotal !== (int) $pageData['totalElements']
            || $officialPages !== (int) $pageData['totalPages']) {
            throw new RuntimeException('YÖK Atlas sayfalama toplamı işlem sırasında değişti.');
        }
        $fetchedAt = (string) ($pageData['fetched_at'] ?? $fetchedAt);

        foreach ($pageData['content'] as $item) {
            if ($counts['source_programs'] >= $options['limit']) {
                break;
            }
            $counts['source_programs']++;
            $rawCode = trim((string) ($item['kilavuzKodu'] ?? ''));
            if ($rawCode === '') {
                $counts['missing_program_code']++;
            }
            try {
                $officialOsym = $osym['rows'][$rawCode] ?? null;
                if (trim((string) ($item['puanTuru'] ?? '')) === ''
                    && trim((string) ($officialOsym['score_type'] ?? '')) !== '') {
                    $item['puanTuru'] = $officialOsym['score_type'];
                    $counts['osym_score_type_backfilled']++;
                }
                $row = $mapper->map($item, $options['year'], $fetchedAt);
                if ($row['base_score'] === null && ($officialOsym['score'] ?? null) !== null) {
                    $row['base_score'] = $officialOsym['score'];
                    $row['source_name'] = 'YÖK Atlas 2026 + ÖSYM 2026 yerleştirme tabloları';
                    $counts['osym_score_backfilled']++;
                }
                $code = (string) $row['program_code'];
                if (isset($rowsByCode[$code])) {
                    $counts['duplicate_program_code']++;
                    if (count($duplicates) < 50) {
                        $duplicates[] = $code;
                    }
                    continue;
                }
                $rowsByCode[$code] = $row;
                $counts['parsed_programs']++;
                foreach ([
                    'base_score' => 'base_score_filled',
                    'base_rank' => 'base_rank_filled',
                    'quota' => 'quota_filled',
                    'placed_count' => 'placed_count_filled',
                ] as $field => $counter) {
                    if ($row[$field] !== null) {
                        $counts[$counter]++;
                    }
                }
            } catch (InvalidArgumentException $exception) {
                $counts['validation_errors']++;
                if (count($validationErrors) < 100) {
                    $validationErrors[] = [
                        'program_code' => $rawCode,
                        'reason' => $exception->getMessage(),
                    ];
                }
            }
        }
        $page++;
        if ($counts['source_programs'] >= $officialTotal || !empty($pageData['last'])) {
            break;
        }
    }
    $counts['unique_program_codes'] = count($rowsByCode);

    $existing2025 = [];
    foreach ($pdo->query(<<<'SQL'
SELECT program_code, university_name, faculty_name, department_name, city,
       university_type, score_type, education_type, education_language,
       scholarship_type, base_score, base_rank, quota, placed_count, duration_years
FROM universities WHERE year = 2025 AND program_code IS NOT NULL
SQL)->fetchAll() as $row) {
        $existing2025[(string) $row['program_code']] = $row;
    }
    $continuing = array_intersect_key($rowsByCode, $existing2025);
    $newRows = array_diff_key($rowsByCode, $existing2025);
    $missingFrom2026 = array_diff_key($existing2025, $rowsByCode);
    $fieldChanges = array_fill_keys([
        'university_name', 'faculty_name', 'department_name', 'city', 'score_type',
        'education_type', 'education_language', 'scholarship_type',
    ], 0);
    foreach ($continuing as $code => $row) {
        foreach (array_keys($fieldChanges) as $field) {
            if (comparableText($row[$field]) !== comparableText($existing2025[$code][$field])) {
                $fieldChanges[$field]++;
            }
        }
    }

    $ranked2025 = array_values(array_filter(
        $existing2025,
        static fn (array $row): bool => $row['base_rank'] !== null && (int) $row['base_rank'] > 0,
    ));
    $mappingAnalysis = (new HistoricalProgramMatcher())->analyze(
        array_values($newRows),
        $ranked2025,
        2025,
    );

    $collectedFullDataset = $officialTotal !== null
        && $counts['source_programs'] === $officialTotal;
    $osymConflicts = [];
    $osymCounts = [
        'official_rows' => count($osym['rows']),
        'matched_program_codes' => 0,
        'yokatlas_without_osym' => 0,
        'osym_without_yokatlas' => $collectedFullDataset
            ? count(array_diff_key($osym['rows'], $rowsByCode))
            : 0,
        'score_conflicts' => 0,
        'quota_conflicts' => 0,
        'placed_count_conflicts' => 0,
        'duplicate_program_codes' => count($osym['duplicates']),
    ];
    foreach ($rowsByCode as $code => $row) {
        $official = $osym['rows'][$code] ?? null;
        if ($official === null) {
            $osymCounts['yokatlas_without_osym']++;
            continue;
        }
        $osymCounts['matched_program_codes']++;
        $differences = [];
        if (!sameNullableNumber($row['base_score'], $official['score'], 0.00001)) {
            $osymCounts['score_conflicts']++;
            $differences[] = 'base_score';
        }
        if (!sameNullableNumber($row['quota'], $official['quota'])) {
            $osymCounts['quota_conflicts']++;
            $differences[] = 'quota';
        }
        if (!sameNullableNumber($row['placed_count'], $official['placed_count'])) {
            $osymCounts['placed_count_conflicts']++;
            $differences[] = 'placed_count';
        }
        if ($differences !== [] && count($osymConflicts) < 100) {
            $osymConflicts[] = ['program_code' => $code, 'fields' => $differences];
        }
    }

    $fullRun = $collectedFullDataset;
    $criticalErrors = [];
    foreach ([
        'Eksik/tanımlanamayan program kaydı var.' => $counts['validation_errors'],
        'Duplicate YÖK Atlas program kodu var.' => $counts['duplicate_program_code'],
        'YÖK Atlas ile ÖSYM program kodları tam örtüşmüyor.'
            => $osymCounts['yokatlas_without_osym'] + $osymCounts['osym_without_yokatlas'],
        'YÖK Atlas ile ÖSYM puan/kontenjan/yerleşen alanları çelişiyor.'
            => $osymCounts['score_conflicts'] + $osymCounts['quota_conflicts']
                + $osymCounts['placed_count_conflicts'],
        'ÖSYM dosyalarında duplicate program kodu var.' => $osymCounts['duplicate_program_codes'],
    ] as $message => $value) {
        if ($value > 0) {
            $criticalErrors[] = $message;
        }
    }
    if (!$fullRun && $options['limit'] >= (int) $officialTotal) {
        $criticalErrors[] = 'Tam veri seti tamamlanamadı.';
    }

    $report = [
        'generated_at' => gmdate(DATE_ATOM),
        'mode' => $options['write'] ? 'write' : 'dry-run',
        'year' => $options['year'],
        'source' => [
            'name' => 'YÖK Atlas 2026',
            'endpoint' => 'https://yokatlas.yok.gov.tr/api/tercih-kilavuz/search',
            'robots' => $robots,
            'official_total' => $officialTotal,
            'official_pages' => $officialPages,
            'fetched_at' => $fetchedAt,
        ],
        'counts' => $counts,
        'cross_year_2025' => [
            'same_program_code' => count($continuing),
            'new_2026_program_code' => count($newRows),
            'missing_2025_program_code' => count($missingFrom2026),
            'same_code_field_changes' => $fieldChanges,
            'strict_code_change_candidates' => count($mappingAnalysis['matches']),
            'ambiguous_code_change_candidates' => count($mappingAnalysis['ambiguous']),
            'manual_review_code_change_candidates' => count($mappingAnalysis['manual_review']),
            'unresolved_new_programs' => count($mappingAnalysis['unmatched']),
            'candidate_samples' => array_slice($mappingAnalysis['matches'], 0, 100),
        ],
        'osym_crosscheck' => [
            'counts' => $osymCounts,
            'source_metadata' => $osym['metadata'],
            'conflict_samples' => $osymConflicts,
        ],
        'validation_error_samples' => $validationErrors,
        'duplicate_samples' => $duplicates,
        'quality_passed' => $criticalErrors === [],
        'critical_errors' => $criticalErrors,
        'full_run' => $fullRun,
        'output' => null,
    ];

    if ($criticalErrors === [] && $options['write']) {
        $outputPath = $root . '/' . $options['output'];
        if (is_file($outputPath)) {
            throw new RuntimeException('CSV çıktısı zaten var; üzerine yazılmadı.');
        }
        $handle = fopen($outputPath, 'xb');
        if ($handle === false) {
            throw new RuntimeException('CSV çıktısı oluşturulamadı.');
        }
        try {
            fputcsv($handle, CSV_HEADERS);
            foreach ($rowsByCode as $row) {
                fputcsv($handle, csvValues($row));
            }
        } finally {
            fclose($handle);
        }
        $report['output'] = $options['output'];
    }

    $reportPath = $storage->writePlacementReport($report);
    $pdo->rollBack();
    echo json_encode([
        'mode' => $report['mode'],
        'year' => $report['year'],
        'quality_passed' => $report['quality_passed'],
        'counts' => $counts,
        'cross_year_2025' => $report['cross_year_2025'],
        'osym_crosscheck' => $osymCounts,
        'critical_errors' => $criticalErrors,
        'output' => $report['output'],
        'report' => $reportPath,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit($criticalErrors === [] ? 0 : 2);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'YÖK Atlas 2026 exportu tamamlanamadı: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
