<?php

declare(strict_types=1);

use DersRotasi\Config\Env;
use DersRotasi\Database\Connection;
use DersRotasi\Yokatlas\HistoricalImportStorage;
use DersRotasi\Yokatlas\HistoricalUniversityMapper;
use DersRotasi\Yokatlas\HistoricalUniversityRepository;
use DersRotasi\Yokatlas\YokatlasClient;
use DersRotasi\Yokatlas\YokatlasStopException;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

const HISTORICAL_PAGE_SIZE = 100;
const HISTORICAL_MAX_SOURCE_ITEMS = 30000;
const HISTORICAL_ALLOWED_YEARS = [2023, 2024];

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Bu araç yalnızca komut satırından çalıştırılabilir.\n");
    exit(1);
}

function historicalUsage(int $code = 1): never
{
    $stream = $code === 0 ? STDOUT : STDERR;
    fwrite($stream, implode(PHP_EOL, [
        'Kullanım:',
        '  php scripts/import_yokatlas_historical.php 2023 --dry-run --limit=10',
        '  php scripts/import_yokatlas_historical.php 2024 --dry-run --program-code=110110031',
        '  php scripts/import_yokatlas_historical.php 2023 2024 --apply',
        '  php scripts/import_yokatlas_historical.php 2023 2024 --apply --resume',
        '',
        'Seçenekler: --dry-run --apply --yes --resume --production --limit=N --delay-ms=N',
        '            --program-code=KOD --guide-year=YYYY --help',
        'Varsayılan mod dry-run; production için ayrıca açık job onayı gerekir.',
    ]) . PHP_EOL);
    exit($code);
}

function historicalOptionValue(string $argument, string $name): ?string
{
    $prefix = '--' . $name . '=';
    return str_starts_with($argument, $prefix) ? substr($argument, strlen($prefix)) : null;
}

function historicalIntegerOption(
    ?string $value,
    string $name,
    int $minimum,
    int $maximum
): int {
    if ($value === null || filter_var($value, FILTER_VALIDATE_INT) === false) {
        throw new RuntimeException("--{$name} geçerli tam sayı olmalıdır.");
    }
    $number = (int) $value;
    if ($number < $minimum || $number > $maximum) {
        throw new RuntimeException("--{$name} {$minimum}-{$maximum} aralığında olmalıdır.");
    }
    return $number;
}

/**
 * @return array{content: array, number: int, size: int, totalElements: int, totalPages: int, last: bool, fetched_at: string}
 */
function validatedHistoricalPage(array $response, int $expectedPage, int $expectedSize, string $fetchedAt): array
{
    foreach (['content', 'number', 'size', 'totalElements', 'totalPages'] as $field) {
        if (!array_key_exists($field, $response)) {
            throw new RuntimeException("Resmi API yanıtında {$field} alanı bulunamadı.");
        }
    }
    if (!is_array($response['content'])
        || (int) $response['number'] !== $expectedPage
        || (int) $response['size'] !== $expectedSize
        || (int) $response['totalElements'] < 1
        || (int) $response['totalPages'] < 1
        || count($response['content']) > $expectedSize) {
        throw new RuntimeException("Resmi API sayfa {$expectedPage} metadata doğrulaması başarısız.");
    }
    foreach ($response['content'] as $item) {
        if (!is_array($item)) {
            throw new RuntimeException("Resmi API sayfa {$expectedPage} içinde nesne olmayan kayıt var.");
        }
    }
    return [
        'content' => $response['content'],
        'number' => (int) $response['number'],
        'size' => (int) $response['size'],
        'totalElements' => (int) $response['totalElements'],
        'totalPages' => (int) $response['totalPages'],
        'last' => (bool) ($response['last'] ?? false),
        'fetched_at' => $fetchedAt,
    ];
}

$options = [
    'dry_run' => true,
    'apply' => false,
    'yes' => false,
    'resume' => false,
    'limit' => null,
    'delay_ms' => 1000,
    'program_code' => null,
    'guide_year' => (int) date('Y'),
    'production' => false,
];
$years = [];

try {
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help') {
            historicalUsage(0);
        }
        if ($argument === '--dry-run') {
            $options['dry_run'] = true;
            continue;
        }
        if ($argument === '--apply') {
            $options['apply'] = true;
            $options['dry_run'] = false;
            continue;
        }
        if ($argument === '--yes') {
            $options['yes'] = true;
            continue;
        }
        if ($argument === '--resume') {
            $options['resume'] = true;
            continue;
        }
        if ($argument === '--production') {
            $options['production'] = true;
            continue;
        }
        if (($value = historicalOptionValue($argument, 'limit')) !== null) {
            $options['limit'] = historicalIntegerOption(
                $value,
                'limit',
                1,
                HISTORICAL_MAX_SOURCE_ITEMS,
            );
            continue;
        }
        if (($value = historicalOptionValue($argument, 'delay-ms')) !== null) {
            $options['delay_ms'] = historicalIntegerOption($value, 'delay-ms', 1000, 60000);
            continue;
        }
        if (($value = historicalOptionValue($argument, 'program-code')) !== null) {
            if (preg_match('/^[0-9]{9}$/', $value) !== 1) {
                throw new RuntimeException('--program-code tam olarak 9 rakam olmalıdır.');
            }
            $options['program_code'] = $value;
            $options['limit'] = 1;
            continue;
        }
        if (($value = historicalOptionValue($argument, 'guide-year')) !== null) {
            $options['guide_year'] = historicalIntegerOption($value, 'guide-year', 2025, 2100);
            continue;
        }
        if (preg_match('/^\d{4}$/', $argument) === 1) {
            $year = (int) $argument;
            if (!in_array($year, HISTORICAL_ALLOWED_YEARS, true)) {
                throw new RuntimeException('Yalnızca 2023 ve 2024 hedef yılları kabul edilir.');
            }
            $years[] = $year;
            continue;
        }
        throw new RuntimeException("Bilinmeyen argüman: {$argument}");
    }

    $years = array_values(array_unique($years));
    sort($years);
    if ($years === []) {
        historicalUsage();
    }
    if ($options['yes'] && !$options['apply']) {
        throw new RuntimeException('--yes yalnızca --apply ile kullanılabilir.');
    }
    if ($options['resume'] && !$options['apply']) {
        throw new RuntimeException('--resume yalnızca --apply ile kullanılabilir.');
    }
    if ($options['dry_run'] && $options['limit'] === null) {
        $options['limit'] = 10;
    }

    $root = dirname(__DIR__);
    Dotenv::createImmutable($root)->safeLoad();
    $env = new Env($_ENV);
    $localTarget = $env->appEnv() === 'local'
        && in_array($env->dbHost(), ['127.0.0.1', 'localhost'], true)
        && $env->instanceConnectionName() === null;
    $productionTarget = $options['production']
        && $env->appEnv() === 'production'
        && $env->instanceConnectionName() !== null
        && $env->dbName() === 'dersrotasi'
        && filter_var(getenv('ALLOW_PRODUCTION_DATA_IMPORT') ?: 'false', FILTER_VALIDATE_BOOL);
    if (!$localTarget && !$productionTarget) {
        throw new RuntimeException('Import hedefi güvenli local DB veya açıkça onaylanmış production Cloud SQL olmalıdır.');
    }
    if ($productionTarget
        && $options['apply']
        && (!$options['yes']
            || getenv('PRODUCTION_DATA_IMPORT_CONFIRMATION') !== 'dersrotasi-db:2023-2024')) {
        throw new RuntimeException('Production historical apply için --yes ve doğru confirmation değeri gerekir.');
    }

    $pdo = Connection::make($env);
    $repository = new HistoricalUniversityRepository($pdo);
    if ($options['apply']) {
        $repository->assertHistoricalSchemaReady();
    }
    $protectedBefore = $repository->protectedYearSnapshot();

    if ($options['apply'] && !$options['yes']) {
        echo 'Yalnızca ' . implode(', ', $years) . " yıllarındaki eksik satırlar local DB'ye eklenecek.\n";
        echo "2025/2026 satırları güncellenmeyecek veya silinmeyecek. Devam etmek için EVET yazın: ";
        $answer = fgets(STDIN);
        if ($answer === false || strtoupper(trim($answer)) !== 'EVET') {
            throw new RuntimeException('Apply kullanıcı tarafından iptal edildi; veri değişmedi.');
        }
    }

    $client = new YokatlasClient(
        $env->yokatlasUserAgent(),
        $options['delay_ms'],
        $env->sslCaBundle(),
    );
    $mapper = new HistoricalUniversityMapper();
    $storage = new HistoricalImportStorage($root);
    $robots = $client->checkRobots();
    $startPage = 0;
    if ($options['apply'] && $options['resume']) {
        $state = $storage->readState($years);
        if ($state !== null) {
            if (($state['years'] ?? null) !== $years
                || (int) ($state['guide_year'] ?? 0) !== $options['guide_year']
                || (int) ($state['page_size'] ?? 0) !== HISTORICAL_PAGE_SIZE) {
                throw new RuntimeException('Resume state mevcut parametrelerle uyumlu değil.');
            }
            $startPage = max(0, (int) ($state['next_page'] ?? 0));
        }
    }

    $counts = [
        'source_examined' => 0,
        'pages_processed' => 0,
        'page_requests' => 0,
        'page_cache_hits' => 0,
        'mapped' => 0,
        'inserted' => 0,
        'would_insert' => 0,
        'skipped_existing' => 0,
        'skipped_no_historical_evidence' => 0,
        'mapping_failed' => 0,
        'base_rank_null' => 0,
        'base_score_null' => 0,
        'placed_count_null' => 0,
    ];
    $yearCounts = [];
    foreach ($years as $year) {
        $yearCounts[$year] = [
            'mapped' => 0,
            'inserted' => 0,
            'would_insert' => 0,
            'skipped_existing' => 0,
            'skipped_no_historical_evidence' => 0,
            'mapping_failed' => 0,
            'base_rank_null' => 0,
        ];
    }
    $items = [];
    $seen = [];
    $officialTotal = null;
    $officialPages = null;
    $actualGuideYear = null;
    $page = $startPage;
    $startedAt = microtime(true);
    $stop = false;

    while (!$stop) {
        $pageData = $options['program_code'] === null
            ? $storage->readPage($options['guide_year'], $page, HISTORICAL_PAGE_SIZE)
            : null;
        if ($pageData !== null) {
            $counts['page_cache_hits']++;
        } else {
            $response = $options['program_code'] === null
                ? $client->fetchPage($options['guide_year'], $page, HISTORICAL_PAGE_SIZE)
                : $client->fetchProgram($options['program_code'], $options['guide_year']);
            $counts['page_requests']++;
            if ($response['status'] !== 200) {
                throw new YokatlasStopException(
                    "Resmi YÖK Atlas API HTTP {$response['status']} döndürdü.",
                    $response['status'],
                );
            }
            $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
            if ($options['program_code'] !== null) {
                $decoded['number'] = 0;
                $decoded['size'] = HISTORICAL_PAGE_SIZE;
                $decoded['totalElements'] = count($decoded['content'] ?? []);
                $decoded['totalPages'] = 1;
                $decoded['last'] = true;
            }
            $pageData = validatedHistoricalPage(
                $decoded,
                $page,
                HISTORICAL_PAGE_SIZE,
                gmdate('Y-m-d H:i:s'),
            );
            if ($options['program_code'] === null) {
                $storage->writePage($options['guide_year'], $page, HISTORICAL_PAGE_SIZE, $pageData);
            }
        }

        $officialTotal ??= (int) $pageData['totalElements'];
        $officialPages ??= (int) $pageData['totalPages'];
        if ($officialTotal !== (int) $pageData['totalElements']
            || $officialPages !== (int) $pageData['totalPages']) {
            throw new RuntimeException('Resmi API sayfalama toplamı işlem sırasında değişti.');
        }

        $batchRows = [];
        $batchReportIndexes = [];
        foreach ($pageData['content'] as $official) {
            if ($options['limit'] !== null && $counts['source_examined'] >= $options['limit']) {
                $stop = true;
                break;
            }
            $counts['source_examined']++;
            $itemGuideYear = (int) ($official['yil'] ?? 0);
            if ($actualGuideYear === null) {
                $actualGuideYear = $itemGuideYear;
            }
            if ($itemGuideYear !== $actualGuideYear || $itemGuideYear !== $options['guide_year']) {
                throw new RuntimeException(
                    "Resmi yanıt yılı {$itemGuideYear}; beklenen kılavuz yılı {$options['guide_year']}."
                );
            }

            foreach ($years as $year) {
                $programCode = trim((string) ($official['kilavuzKodu'] ?? ''));
                try {
                    $row = $mapper->map($official, $year, (string) $pageData['fetched_at']);
                    if ($row === null) {
                        $counts['skipped_no_historical_evidence']++;
                        $yearCounts[$year]['skipped_no_historical_evidence']++;
                        continue;
                    }
                    $key = $programCode . ':' . $year;
                    if (isset($seen[$key])) {
                        throw new RuntimeException('Aynı program_code + year kaynak sayfalarda tekrarlandı.');
                    }
                    $seen[$key] = true;
                    $counts['mapped']++;
                    $yearCounts[$year]['mapped']++;
                    if ($row['base_rank'] === null) {
                        $counts['base_rank_null']++;
                        $yearCounts[$year]['base_rank_null']++;
                    }
                    if ($row['base_score'] === null) {
                        $counts['base_score_null']++;
                    }
                    if ($row['placed_count'] === null) {
                        $counts['placed_count_null']++;
                    }
                    $status = $options['apply'] ? 'pending' : 'would_insert';
                    $items[] = [
                        'program_code' => $row['program_code'],
                        'year' => $row['year'],
                        'status' => $status,
                        'base_score' => $row['base_score'],
                        'base_rank' => $row['base_rank'],
                        'quota' => $row['quota'],
                        'placed_count' => $row['placed_count'],
                        'guide_year' => $row['guide_year'],
                        'metadata_basis' => $row['metadata_basis'],
                        'university_name' => $row['university_name'],
                        'department_name' => $row['department_name'],
                    ];
                    $batchRows[] = $row;
                    $batchReportIndexes[$key] = array_key_last($items);
                } catch (Throwable $exception) {
                    $counts['mapping_failed']++;
                    $yearCounts[$year]['mapping_failed']++;
                    $items[] = [
                        'program_code' => $programCode,
                        'year' => $year,
                        'status' => 'mapping_failed',
                        'guide_year' => $itemGuideYear,
                        'reason' => $exception->getMessage(),
                    ];
                }
            }
        }

        if ($options['apply'] && $batchRows !== []) {
            $result = $repository->insertMissing($batchRows);
            $counts['inserted'] += $result['inserted'];
            $counts['skipped_existing'] += $result['skipped_existing'];
            foreach ($result['statuses'] as $key => $status) {
                $reportIndex = $batchReportIndexes[$key];
                $items[$reportIndex]['status'] = $status;
                $year = (int) $items[$reportIndex]['year'];
                $yearCounts[$year][$status]++;
            }
        } elseif (!$options['apply'] && $batchRows !== []) {
            $existingKeys = $repository->existingKeys($batchRows);
            foreach ($batchRows as $row) {
                $key = (string) $row['program_code'] . ':' . (int) $row['year'];
                $reportIndex = $batchReportIndexes[$key];
                $year = (int) $row['year'];
                if (isset($existingKeys[$key])) {
                    $items[$reportIndex]['status'] = 'skipped_existing';
                    $counts['skipped_existing']++;
                    $yearCounts[$year]['skipped_existing']++;
                } else {
                    $items[$reportIndex]['status'] = 'would_insert';
                    $counts['would_insert']++;
                    $yearCounts[$year]['would_insert']++;
                }
            }
        }

        $counts['pages_processed']++;
        $page++;
        if ($options['apply']) {
            $storage->writeState($years, [
                'version' => 1,
                'years' => $years,
                'guide_year' => $options['guide_year'],
                'page_size' => HISTORICAL_PAGE_SIZE,
                'next_page' => $page,
                'updated_at' => gmdate(DATE_ATOM),
                'completed' => false,
            ]);
        }
        if ($counts['pages_processed'] % 10 === 0) {
            echo sprintf(
                "İlerleme: %d/%d sayfa, %d kaynak, %d eklenen, %d atlanan\n",
                $page,
                $officialPages,
                $counts['source_examined'],
                $counts['inserted'],
                $counts['skipped_existing'],
            );
        }
        if ($stop || $page >= $officialPages || !empty($pageData['last'])) {
            break;
        }
    }

    if ($options['apply'] && $page >= (int) $officialPages) {
        $storage->writeState($years, [
            'version' => 1,
            'years' => $years,
            'guide_year' => $options['guide_year'],
            'page_size' => HISTORICAL_PAGE_SIZE,
            'next_page' => $page,
            'updated_at' => gmdate(DATE_ATOM),
            'completed' => true,
        ]);
    }

    $protectedAfter = $repository->protectedYearSnapshot();
    if ($protectedBefore !== $protectedAfter) {
        throw new RuntimeException('Güvenlik ihlali: korunan 2025/2026 veri özeti işlem sırasında değişti.');
    }

    $report = [
        'generated_at' => gmdate(DATE_ATOM),
        'mode' => $options['apply'] ? 'apply' : 'dry-run',
        'target_years' => $years,
        'requested_guide_year' => $options['guide_year'],
        'actual_guide_year' => $actualGuideYear,
        'source_endpoint' => 'https://yokatlas.yok.gov.tr/api/tercih-kilavuz/search',
        'historical_mapping' => [
            '2024' => ['base_score' => 'minPuan1', 'base_rank' => 'basariSirasi1', 'quota' => 'gk2'],
            '2023' => ['base_score' => 'minPuan2', 'base_rank' => 'basariSirasi2', 'quota' => 'gk3'],
        ],
        'metadata_basis' => "current_guide_{$actualGuideYear}",
        'robots' => $robots,
        'official_total_elements' => $officialTotal,
        'official_total_pages' => $officialPages,
        'counts' => $counts,
        'year_counts' => $yearCounts,
        'protected_years_before' => $protectedBefore,
        'protected_years_after' => $protectedAfter,
        'protected_years_unchanged' => true,
        'items' => $items,
        'duration_seconds' => round(microtime(true) - $startedAt, 3),
    ];
    $paths = $storage->writeReport($report);

    echo json_encode([
        'mode' => $report['mode'],
        'target_years' => $years,
        'actual_guide_year' => $actualGuideYear,
        'official_total_elements' => $officialTotal,
        'counts' => $counts,
        'year_counts' => $yearCounts,
        'protected_years_unchanged' => true,
        'report_json' => $paths['json'],
        'report_csv' => $paths['csv'],
        'duration_seconds' => $report['duration_seconds'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Historical YÖK Atlas importu tamamlanamadı: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
