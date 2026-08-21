<?php

declare(strict_types=1);

use DersRotasi\Config\Env;
use DersRotasi\Database\Connection;
use DersRotasi\Import\EducationLanguageNormalizer;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

const EXPECTED_HEADERS = [
    'program_code', 'university_name', 'faculty_name', 'department_name', 'city',
    'university_type', 'score_type', 'education_type', 'education_language',
    'scholarship_type', 'base_score', 'base_rank', 'quota', 'placed_count',
    'duration_years', 'year', 'source_name', 'source_url',
];
const ENUMS = [
    'university_type' => ['devlet', 'vakif', 'kktc', 'yabanci'],
    'score_type' => ['say', 'ea', 'soz', 'dil', 'tyt'],
    'education_type' => ['orgun', 'ikinci_ogretim', 'uzaktan', 'acikogretim', 'diger'],
    'scholarship_type' => ['ucretsiz', 'burslu', 'yuzde_50', 'yuzde_25', 'ucretli', 'diger'],
];
const IMPORT_FIELDS = [
    'program_code', 'identity_hash', 'university_name', 'faculty_name', 'department_name',
    'city', 'university_type', 'score_type', 'education_type', 'education_language',
    'scholarship_type', 'base_score', 'base_rank', 'rank_source_name', 'rank_source_url',
    'quota', 'placed_count', 'duration_years', 'year', 'source_year', 'source_name', 'source_url',
];

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Bu araç yalnızca komut satırından çalıştırılabilir.\n");
    exit(1);
}

function detectDelimiter(string $headerLine): string
{
    foreach ([',', ';'] as $delimiter) {
        if (count(str_getcsv($headerLine, $delimiter, '"', '\\')) === count(EXPECTED_HEADERS)) {
            return $delimiter;
        }
    }
    throw new RuntimeException('CSV ayracı algılanamadı.');
}

function requiredString(array $row, string $field): string
{
    $value = trim((string) ($row[$field] ?? ''));
    if ($value === '') {
        throw new InvalidArgumentException("{$field} alanı zorunludur.");
    }
    if (preg_match('//u', $value) !== 1) {
        throw new InvalidArgumentException("{$field} geçerli UTF-8 değil.");
    }
    return $value;
}

function enumValue(array $row, string $field): string
{
    $value = requiredString($row, $field);
    if (!in_array($value, ENUMS[$field], true)) {
        throw new InvalidArgumentException("{$field} değeri geçersiz: {$value}");
    }
    return $value;
}

function nullableInteger(array $row, string $field, int $maximum = PHP_INT_MAX): ?int
{
    $value = trim((string) ($row[$field] ?? ''));
    if ($value === '') {
        return null;
    }
    $normalized = str_replace(["\u{00A0}", ' ', '.', ','], '', $value);
    if (!ctype_digit($normalized)) {
        throw new InvalidArgumentException("{$field} negatif olmayan tam sayı olmalıdır.");
    }
    $number = (int) $normalized;
    if ($number < 0 || $number > $maximum) {
        throw new InvalidArgumentException("{$field} izin verilen aralığın dışındadır.");
    }
    return $number;
}

function nullableDecimal(array $row, string $field): ?string
{
    $value = trim((string) ($row[$field] ?? ''));
    if ($value === '') {
        return null;
    }
    $value = str_replace(["\u{00A0}", ' '], '', $value);
    if (str_contains($value, ',') && str_contains($value, '.')) {
        $value = strrpos($value, ',') > strrpos($value, '.')
            ? str_replace(',', '.', str_replace('.', '', $value))
            : str_replace(',', '', $value);
    } elseif (str_contains($value, ',')) {
        $value = str_replace(',', '.', $value);
    }
    if (!preg_match('/^\d+(?:\.\d{1,5})?$/', $value) || (float) $value <= 0) {
        throw new InvalidArgumentException("{$field} pozitif ve geçerli bir decimal olmalıdır.");
    }
    return number_format((float) $value, 5, '.', '');
}

/** @return array<string, mixed> */
function validatedRow(array $row, int $expectedYear): array
{
    $programCode = requiredString($row, 'program_code');
    if (preg_match('/^[0-9]{9}$/', $programCode) !== 1) {
        throw new InvalidArgumentException('program_code tam olarak 9 rakam olmalıdır.');
    }
    $year = nullableInteger($row, 'year', 2100);
    if ($year !== $expectedYear) {
        throw new InvalidArgumentException("CSV yalnız {$expectedYear} satırları içermelidir.");
    }
    $sourceName = requiredString($row, 'source_name');
    $officialSourceNames = [
        "YÖK Atlas {$expectedYear}",
        "YÖK Atlas {$expectedYear} + ÖSYM {$expectedYear} yerleştirme tabloları",
    ];
    if (!in_array($sourceName, $officialSourceNames, true)) {
        throw new InvalidArgumentException('source_name resmî 2026 kaynağıyla eşleşmiyor.');
    }
    $sourceUrl = requiredString($row, 'source_url');
    if (filter_var($sourceUrl, FILTER_VALIDATE_URL) === false
        || parse_url($sourceUrl, PHP_URL_SCHEME) !== 'https'
        || parse_url($sourceUrl, PHP_URL_HOST) !== 'yokatlas.yok.gov.tr') {
        throw new InvalidArgumentException('source_url resmî YÖK Atlas HTTPS adresi olmalıdır.');
    }
    $baseRank = nullableInteger($row, 'base_rank');
    if ($baseRank !== null && $baseRank < 1) {
        throw new InvalidArgumentException('base_rank varsa pozitif olmalıdır.');
    }
    $baseScore = nullableDecimal($row, 'base_score');

    return [
        'program_code' => $programCode,
        'identity_hash' => hash('sha256', $programCode),
        'university_name' => requiredString($row, 'university_name'),
        'faculty_name' => trim((string) ($row['faculty_name'] ?? '')),
        'department_name' => requiredString($row, 'department_name'),
        'city' => requiredString($row, 'city'),
        'university_type' => enumValue($row, 'university_type'),
        'score_type' => enumValue($row, 'score_type'),
        'education_type' => enumValue($row, 'education_type'),
        'education_language' => EducationLanguageNormalizer::normalize(
            requiredString($row, 'department_name'),
            $row['education_language'] ?? null,
        ),
        'scholarship_type' => enumValue($row, 'scholarship_type'),
        'base_score' => $baseScore,
        'base_rank' => $baseRank,
        'rank_source_name' => $baseRank === null ? null : "YÖK Atlas {$expectedYear}",
        'rank_source_url' => $baseRank === null ? null : $sourceUrl,
        'quota' => nullableInteger($row, 'quota'),
        'placed_count' => nullableInteger($row, 'placed_count'),
        'duration_years' => nullableInteger($row, 'duration_years', 20),
        'year' => $year,
        'source_year' => $year,
        'source_name' => $sourceName,
        'source_url' => $sourceUrl,
    ];
}

/** @return array<int, array<string, int|string>> */
function protectedSnapshot(PDO $pdo): array
{
    $contexts = [];
    $counts = [];
    $statement = $pdo->query('SELECT * FROM universities WHERE year IN (2023, 2024, 2025) ORDER BY year, id');
    while ($row = $statement->fetch()) {
        $year = (int) $row['year'];
        if (!isset($contexts[$year])) {
            $contexts[$year] = hash_init('sha256');
            $counts[$year] = 0;
        }
        hash_update(
            $contexts[$year],
            json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        );
        $counts[$year]++;
    }
    $snapshot = [];
    foreach ($contexts as $year => $context) {
        $snapshot[$year] = ['rows' => $counts[$year], 'sha256' => hash_final($context)];
    }
    return $snapshot;
}

function rowsEquivalent(array $incoming, array $existing): bool
{
    foreach (IMPORT_FIELDS as $field) {
        $left = $incoming[$field] ?? null;
        $right = $existing[$field] ?? null;
        if (in_array($field, ['base_score'], true)) {
            if (($left === null) !== ($right === null)
                || ($left !== null && abs((float) $left - (float) $right) > 0.00001)) {
                return false;
            }
            continue;
        }
        if ((string) ($left ?? '') !== (string) ($right ?? '')) {
            return false;
        }
    }
    return true;
}

$filePath = $argv[1] ?? '';
$mode = 'dry-run';
$expectedYear = 2026;
$production = false;
foreach (array_slice($argv, 2) as $argument) {
    if ($argument === '--dry-run') {
        $mode = 'dry-run';
    } elseif ($argument === '--apply') {
        $mode = 'apply';
    } elseif (str_starts_with($argument, '--year=')) {
        $expectedYear = (int) substr($argument, strlen('--year='));
    } elseif ($argument === '--production') {
        $production = true;
    } else {
        throw new RuntimeException('Bilinmeyen seçenek: ' . $argument);
    }
}
if ($filePath === '' || !is_file($filePath) || !is_readable($filePath)) {
    fwrite(STDERR, "Kullanım: php scripts/import_universities.php <csv> [--dry-run|--apply] --year=2026 [--production]\n");
    exit(1);
}
if ($expectedYear !== 2026) {
    fwrite(STDERR, "Bu güvenli importer yalnız 2026 veri setini kabul eder.\n");
    exit(1);
}

$handle = fopen($filePath, 'rb');
if ($handle === false) {
    fwrite(STDERR, "CSV açılamadı.\n");
    exit(1);
}

try {
    $firstLine = fgets($handle);
    if ($firstLine === false) {
        throw new RuntimeException('CSV boş.');
    }
    $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine) ?? $firstLine;
    $delimiter = detectDelimiter($firstLine);
    $headers = array_map(
        static fn ($value): string => trim((string) $value),
        str_getcsv(rtrim($firstLine, "\r\n"), $delimiter, '"', '\\'),
    );
    if ($headers !== EXPECTED_HEADERS) {
        throw new RuntimeException('CSV başlıkları beklenen şablonla eşleşmiyor.');
    }

    $rows = [];
    $validationErrors = [];
    $line = 1;
    while (($values = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
        $line++;
        if ($values === [null] || (count($values) === 1 && trim((string) $values[0]) === '')) {
            continue;
        }
        try {
            if (count($values) !== count($headers)) {
                throw new InvalidArgumentException('Kolon sayısı başlıkla eşleşmiyor.');
            }
            $row = validatedRow(array_combine($headers, $values), $expectedYear);
            if (isset($rows[$row['program_code']])) {
                throw new InvalidArgumentException('CSV içinde duplicate program_code.');
            }
            $rows[$row['program_code']] = $row;
        } catch (InvalidArgumentException $exception) {
            if (count($validationErrors) < 100) {
                $validationErrors[] = ['line' => $line, 'reason' => $exception->getMessage()];
            }
        }
    }
    fclose($handle);
    if ($validationErrors !== []) {
        throw new RuntimeException('CSV validation başarısız: ' . json_encode($validationErrors, JSON_UNESCAPED_UNICODE));
    }

    $root = dirname(__DIR__);
    Dotenv::createImmutable($root)->safeLoad();
    $env = new Env($_ENV);
    $localTarget = $env->appEnv() === 'local'
        && in_array($env->dbHost(), ['127.0.0.1', 'localhost'], true)
        && $env->instanceConnectionName() === null
        && $env->dbName() === 'dersrotasi';
    $productionTarget = $production
        && $env->appEnv() === 'production'
        && $env->instanceConnectionName() !== null
        && $env->dbName() === 'dersrotasi'
        && filter_var(getenv('ALLOW_PRODUCTION_DATA_IMPORT') ?: 'false', FILTER_VALIDATE_BOOL);
    if (!$localTarget && !$productionTarget) {
        throw new RuntimeException('Import hedefi güvenli local DB veya açıkça onaylanmış production Cloud SQL olmalıdır.');
    }
    if ($productionTarget
        && $mode === 'apply'
        && getenv('PRODUCTION_DATA_IMPORT_CONFIRMATION') !== 'dersrotasi-db:2026') {
        throw new RuntimeException('Production 2026 apply confirmation değeri eksik veya hatalı.');
    }
    $pdo = Connection::make($env);
    $before = protectedSnapshot($pdo);
    $counts = [
        'csv_rows' => count($rows),
        'inserted' => 0,
        'skipped_identical' => 0,
        'conflicts' => 0,
        'validation_errors' => 0,
    ];
    $conflicts = [];
    $find = $pdo->prepare('SELECT * FROM universities WHERE year = :year AND program_code = :program_code LIMIT 1');
    $insert = $pdo->prepare(<<<'SQL'
INSERT INTO universities (
  program_code, identity_hash, university_name, faculty_name, department_name, city,
  university_type, score_type, education_type, education_language, scholarship_type,
  base_score, base_rank, rank_source_name, rank_source_url, rank_updated_at,
  quota, placed_count, duration_years, year, source_year, source_name, source_url
) VALUES (
  :program_code, :identity_hash, :university_name, :faculty_name, :department_name, :city,
  :university_type, :score_type, :education_type, :education_language, :scholarship_type,
  :base_score, :base_rank, :rank_source_name, :rank_source_url, :rank_updated_at,
  :quota, :placed_count, :duration_years, :year, :source_year, :source_name, :source_url
)
SQL);

    if ($mode === 'dry-run') {
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        $pdo->exec('START TRANSACTION READ ONLY');
    } else {
        $pdo->beginTransaction();
    }
    $rankUpdatedAt = gmdate('Y-m-d H:i:s');
    foreach ($rows as $row) {
        $find->execute(['year' => $row['year'], 'program_code' => $row['program_code']]);
        $existing = $find->fetch();
        if ($existing !== false) {
            if (rowsEquivalent($row, $existing)) {
                $counts['skipped_identical']++;
                continue;
            }
            $counts['conflicts']++;
            if (count($conflicts) < 100) {
                $conflicts[] = $row['program_code'];
            }
            continue;
        }
        $counts['inserted']++;
        if ($mode === 'apply') {
            $insert->execute([
                ...$row,
                'rank_updated_at' => $row['base_rank'] === null ? null : $rankUpdatedAt,
            ]);
        }
    }
    if ($counts['conflicts'] > 0) {
        throw new RuntimeException('Mevcut 2026 satırlarıyla içerik conflict bulundu; işlem geri alındı.');
    }
    if ($mode === 'apply') {
        $pdo->commit();
    } else {
        $pdo->rollBack();
    }
    $after = protectedSnapshot($pdo);
    if ($before !== $after) {
        throw new RuntimeException('2023/2024/2025 integrity snapshot değişti.');
    }

    $coverage = $pdo->query(<<<'SQL'
SELECT COUNT(*) total_rows, COUNT(DISTINCT program_code) unique_program_codes,
       SUM(base_score IS NOT NULL AND base_score > 0) base_score_filled,
       SUM(base_rank IS NOT NULL AND base_rank > 0) base_rank_filled,
       SUM(quota IS NOT NULL) quota_filled,
       SUM(placed_count IS NOT NULL) placed_count_filled,
       SUM(base_score IS NULL OR base_score <= 0) base_score_missing,
       SUM(base_rank IS NULL OR base_rank <= 0) base_rank_missing
FROM universities WHERE year = 2026
SQL)->fetch();
    $report = [
        'generated_at' => gmdate(DATE_ATOM),
        'mode' => $mode,
        'year' => $expectedYear,
        'counts' => $counts,
        'conflict_samples' => $conflicts,
        'protected_before' => $before,
        'protected_after' => $after,
        'protected_unchanged' => true,
        'database_coverage' => $coverage,
    ];
    $reportDirectory = $root . '/storage/reports';
    $reportPath = $reportDirectory . '/university_import_' . str_replace('-', '_', $mode)
        . '_' . date('Ymd_His') . '.json';
    file_put_contents(
        $reportPath,
        json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        LOCK_EX,
    );
    echo json_encode([...$report, 'report' => $reportPath], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (is_resource($handle)) {
        fclose($handle);
    }
    fwrite(STDERR, 'Üniversite importu tamamlanamadı: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
