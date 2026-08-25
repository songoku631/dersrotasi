<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Bu araç yalnızca komut satırından çalıştırılabilir.\n");
    exit(1);
}

const VERIFIED_2024_ROWS = 21337;
const VERIFIED_2024_CSV_SHA256 = '78ee39a0a918f2d220e3e432ab0c725a61c109bfb6ffec29a54816a61596e30d';
const VERIFIED_2024_CONFIRMATION = 'dersrotasi-db:2024:78ee39a0a918f2d220e3e432ab0c725a61c109bfb6ffec29a54816a61596e30d';
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
const RESULT_SOURCES = [
    'ÖSYM 2024 YKS Yerleştirme Sonuçları Tablo-3' => [
        'result_url' => 'https://dokuman.osym.gov.tr/pdfdokuman/2024/YKS/YERLESTIRME/tablo-3minmax_d27082024.xlsx',
        'rank_name' => 'ÖSYM 2025 YKS Kılavuzu Tablo-3 (2024 sonuç kolonları)',
        'rank_url' => 'https://dokuman.osym.gov.tr/pdfdokuman/2025/YKS/TERC%C4%B0H/tablo3_01082025.xls',
    ],
    'ÖSYM 2024 YKS Yerleştirme Sonuçları Tablo-4' => [
        'result_url' => 'https://dokuman.osym.gov.tr/pdfdokuman/2024/YKS/YERLESTIRME/tablo-4minmax_b27082024.xlsx',
        'rank_name' => 'ÖSYM 2025 YKS Kılavuzu Tablo-4 (2024 sonuç kolonları)',
        'rank_url' => 'https://dokuman.osym.gov.tr/pdfdokuman/2025/YKS/TERC%C4%B0H/tablo4_01082025d.xls',
    ],
];
const COMPARED_FIELDS = [
    'program_code', 'university_name', 'faculty_name', 'department_name', 'city',
    'university_type', 'score_type', 'education_type', 'education_language',
    'scholarship_type', 'base_score', 'base_rank', 'rank_source_name', 'rank_source_url',
    'quota', 'placed_count', 'duration_years', 'year', 'source_name', 'source_url',
];

function requiredString(array $row, string $field): string
{
    $value = trim((string) ($row[$field] ?? ''));
    if ($value === '' || preg_match('//u', $value) !== 1) {
        throw new InvalidArgumentException("{$field} zorunlu ve geçerli UTF-8 olmalıdır.");
    }
    return $value;
}

function enumValue(array $row, string $field): string
{
    $value = requiredString($row, $field);
    if (!in_array($value, ENUMS[$field], true)) {
        throw new InvalidArgumentException("{$field} geçersiz: {$value}");
    }
    return $value;
}

function nullableInteger(array $row, string $field, int $maximum = PHP_INT_MAX): ?int
{
    $value = trim((string) ($row[$field] ?? ''));
    if ($value === '') return null;
    if (!ctype_digit($value)) throw new InvalidArgumentException("{$field} negatif olmayan tam sayı olmalıdır.");
    $number = (int) $value;
    if ($number > $maximum) throw new InvalidArgumentException("{$field} izin verilen aralığın dışında.");
    return $number;
}

function nullableDecimal(array $row, string $field): ?string
{
    $value = trim((string) ($row[$field] ?? ''));
    if ($value === '') return null;
    if (preg_match('/^\d+(?:\.\d{1,5})?$/', $value) !== 1 || (float) $value <= 0 || (float) $value > 600) {
        throw new InvalidArgumentException("{$field} geçersiz.");
    }
    return number_format((float) $value, 5, '.', '');
}

/** @return array<string, mixed> */
function validatedRow(array $row): array
{
    $programCode = requiredString($row, 'program_code');
    if (preg_match('/^[0-9]{9}$/', $programCode) !== 1) {
        throw new InvalidArgumentException('program_code 9 rakam olmalıdır.');
    }
    $year = nullableInteger($row, 'year', 2100);
    if ($year !== 2024) throw new InvalidArgumentException('Yalnız year=2024 kabul edilir.');

    $sourceName = requiredString($row, 'source_name');
    $source = RESULT_SOURCES[$sourceName] ?? null;
    if ($source === null || requiredString($row, 'source_url') !== $source['result_url']) {
        throw new InvalidArgumentException('2024 resmî ÖSYM kaynak adı/URL eşleşmedi.');
    }
    $baseRank = nullableInteger($row, 'base_rank');
    if ($baseRank !== null && $baseRank < 1) throw new InvalidArgumentException('base_rank pozitif olmalıdır.');

    return [
        'program_code' => $programCode,
        'university_name' => requiredString($row, 'university_name'),
        'faculty_name' => requiredString($row, 'faculty_name'),
        'department_name' => requiredString($row, 'department_name'),
        'city' => requiredString($row, 'city'),
        'university_type' => enumValue($row, 'university_type'),
        'score_type' => enumValue($row, 'score_type'),
        'education_type' => enumValue($row, 'education_type'),
        'education_language' => requiredString($row, 'education_language'),
        'scholarship_type' => enumValue($row, 'scholarship_type'),
        'base_score' => nullableDecimal($row, 'base_score'),
        'base_rank' => $baseRank,
        'rank_source_name' => $baseRank === null ? null : $source['rank_name'],
        'rank_source_url' => $baseRank === null ? null : $source['rank_url'],
        'quota' => nullableInteger($row, 'quota'),
        'placed_count' => nullableInteger($row, 'placed_count'),
        'duration_years' => nullableInteger($row, 'duration_years', 10),
        'year' => 2024,
        'source_name' => $sourceName,
        'source_url' => $source['result_url'],
    ];
}

/** @return array{rows: array<string, array<string, mixed>>, counts: array<string, int>, logical_sha256: string} */
function readVerifiedCsv(string $path): array
{
    if (!is_file($path) || !is_readable($path)) throw new RuntimeException('Doğrulanmış CSV bulunamadı.');
    $sha256 = hash_file('sha256', $path);
    if (!hash_equals(VERIFIED_2024_CSV_SHA256, $sha256)) {
        throw new RuntimeException("CSV SHA-256 doğrulanamadı: {$sha256}");
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) throw new RuntimeException('CSV açılamadı.');
    $headers = fgetcsv($handle, 0, ',', '"', '\\');
    if ($headers === false) throw new RuntimeException('CSV boş.');
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]) ?? $headers[0];
    if ($headers !== EXPECTED_HEADERS) throw new RuntimeException('CSV başlıkları doğrulanamadı.');

    $rows = [];
    $counts = ['source_rows' => 0, 'duplicates' => 0, 'validation_errors' => 0,
        'base_score_null' => 0, 'base_rank_null' => 0, 'quota_null' => 0, 'placed_count_null' => 0];
    $errors = [];
    $line = 1;
    while (($values = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        $line++;
        if ($values === [null] || (count($values) === 1 && trim((string) $values[0]) === '')) continue;
        $counts['source_rows']++;
        try {
            if (count($values) !== count($headers)) throw new InvalidArgumentException('Kolon sayısı eşleşmiyor.');
            $row = validatedRow(array_combine($headers, $values));
            if (isset($rows[$row['program_code']])) {
                $counts['duplicates']++;
                throw new InvalidArgumentException('Duplicate program_code.');
            }
            foreach (['base_score', 'base_rank', 'quota', 'placed_count'] as $field) {
                if ($row[$field] === null) $counts[$field . '_null']++;
            }
            $rows[$row['program_code']] = $row;
        } catch (InvalidArgumentException $exception) {
            $counts['validation_errors']++;
            if (count($errors) < 20) $errors[] = ['line' => $line, 'reason' => $exception->getMessage()];
        }
    }
    fclose($handle);
    if ($counts['source_rows'] !== VERIFIED_2024_ROWS || count($rows) !== VERIFIED_2024_ROWS
        || $counts['duplicates'] !== 0 || $counts['validation_errors'] !== 0
        || $counts['base_score_null'] !== 523 || $counts['base_rank_null'] !== 4011
        || $counts['quota_null'] !== 0 || $counts['placed_count_null'] !== 0) {
        throw new RuntimeException('Doğrulanmış CSV invariantları bozuldu: ' . json_encode([$counts, $errors], JSON_UNESCAPED_UNICODE));
    }
    ksort($rows, SORT_STRING);
    $context = hash_init('sha256');
    foreach ($rows as $row) hash_update($context, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    return ['rows' => $rows, 'counts' => $counts, 'logical_sha256' => hash_final($context)];
}

function productionConnection(): PDO
{
    $host = getenv('PROD_DB_HOST') ?: '';
    $port = getenv('PROD_DB_PORT') ?: '';
    $name = getenv('PROD_DB_NAME') ?: '';
    $user = getenv('PROD_DB_USER') ?: '';
    $password = getenv('PROD_DB_PASSWORD');
    if ($host !== '127.0.0.1' || $port !== '3307' || $name !== 'dersrotasi'
        || $user !== 'dersrotasi-db' || $password === false || $password === '') {
        throw new RuntimeException('Production proxy bağlantı koruması sağlanmadı.');
    }
    return new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $password, [
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function assertSchema(PDO $pdo): array
{
    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM universities')->fetchAll() as $column) {
        $columns[$column['Field']] = strtolower((string) $column['Type']);
    }
    foreach (['id', ...COMPARED_FIELDS, 'rank_updated_at', 'created_at', 'updated_at'] as $required) {
        if (!isset($columns[$required])) throw new RuntimeException("Production universities.{$required} eksik.");
    }
    $statement = $pdo->query(<<<'SQL'
SELECT INDEX_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'universities' AND NON_UNIQUE = 0
GROUP BY INDEX_NAME
HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') = 'program_code,year'
SQL);
    $uniqueIndex = $statement->fetchColumn();
    if ($uniqueIndex === false) throw new RuntimeException('program_code + year unique indeksi aktif değil.');
    return ['required_columns_present' => true, 'program_code_year_unique_index' => $uniqueIndex];
}

/** @return array<int, array{rows: int, sha256: string}> */
function protectedSnapshot(PDO $pdo): array
{
    $snapshots = [];
    foreach ([2023, 2025, 2026] as $year) {
        $context = hash_init('sha256');
        $count = 0;
        $statement = $pdo->query("SELECT * FROM universities WHERE year = {$year} ORDER BY program_code, id");
        while (($row = $statement->fetch()) !== false) {
            $count++;
            hash_update($context, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        }
        $snapshots[$year] = ['rows' => $count, 'sha256' => hash_final($context)];
    }
    return $snapshots;
}

function rowsEquivalent(array $incoming, array $existing): bool
{
    foreach (COMPARED_FIELDS as $field) {
        $left = $incoming[$field] ?? null;
        $right = $existing[$field] ?? null;
        if ($field === 'base_score') {
            if (($left === null) !== ($right === null)
                || ($left !== null && abs((float) $left - (float) $right) > 0.0000051)) return false;
        } elseif ((string) ($left ?? '') !== (string) ($right ?? '')) {
            return false;
        }
    }
    return true;
}

/** @return array<string, int|array<int, string>> */
function buildPlan(PDO $pdo, array $rows): array
{
    $existingRows = [];
    foreach ($pdo->query('SELECT * FROM universities WHERE year = 2024 ORDER BY program_code, id')->fetchAll() as $row) {
        $existingRows[(string) $row['program_code']] = $row;
    }
    $result = ['insert' => 0, 'identical_skip' => 0, 'conflicts' => 0, 'conflict_samples' => []];
    foreach ($rows as $programCode => $row) {
        $existing = $existingRows[$programCode] ?? null;
        if ($existing === null) $result['insert']++;
        elseif (rowsEquivalent($row, $existing)) $result['identical_skip']++;
        else {
            $result['conflicts']++;
            if (count($result['conflict_samples']) < 20) $result['conflict_samples'][] = $programCode;
        }
    }
    foreach ($existingRows as $programCode => $_) {
        if (!isset($rows[$programCode])) {
            $result['conflicts']++;
            if (count($result['conflict_samples']) < 20) $result['conflict_samples'][] = $programCode;
        }
    }
    return $result;
}

function targetSummary(PDO $pdo): array
{
    $summary = $pdo->query(<<<'SQL'
SELECT COUNT(*) total_rows, COUNT(DISTINCT program_code) unique_program_codes,
       SUM(base_score IS NULL) base_score_null, SUM(base_rank IS NULL) base_rank_null,
       SUM(quota IS NULL) quota_null, SUM(placed_count IS NULL) placed_count_null
FROM universities WHERE year = 2024
SQL)->fetch();
    $duplicates = (int) $pdo->query(<<<'SQL'
SELECT COUNT(*) FROM (
  SELECT program_code, year FROM universities WHERE year = 2024
  GROUP BY program_code, year HAVING COUNT(*) > 1
) duplicate_pairs
SQL)->fetchColumn();
    return [...$summary, 'duplicates' => $duplicates];
}

$file = $argv[1] ?? '';
$mode = in_array('--validate-only', $argv, true)
    ? 'validate-only'
    : (in_array('--apply', $argv, true) ? 'apply' : 'dry-run');
if ($file === '') {
    fwrite(STDERR, "Kullanım: php scripts/import_verified_2024.php <verified.csv> [--dry-run|--apply]\n");
    exit(1);
}

$pdo = null;
try {
    $verified = readVerifiedCsv($file);
    if ($mode === 'validate-only') {
        echo json_encode([
            'mode' => $mode,
            'source_csv' => ['rows' => VERIFIED_2024_ROWS, 'sha256' => VERIFIED_2024_CSV_SHA256,
                'logical_sha256' => $verified['logical_sha256'], 'validation' => $verified['counts']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit(0);
    }
    if (getenv('APP_ENV') !== 'production-proxy') throw new RuntimeException('APP_ENV=production-proxy koruması gerekli.');
    if ($mode === 'apply' && (!filter_var(getenv('ALLOW_PRODUCTION_DATA_IMPORT') ?: 'false', FILTER_VALIDATE_BOOL)
        || getenv('PRODUCTION_DATA_IMPORT_CONFIRMATION') !== VERIFIED_2024_CONFIRMATION)) {
        throw new RuntimeException('Production 2024 apply confirmation eksik veya hatalı.');
    }
    $pdo = productionConnection();
    $schema = assertSchema($pdo);

    if ($mode === 'dry-run') {
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        $pdo->exec('START TRANSACTION READ ONLY');
    } else {
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        $pdo->beginTransaction();
    }
    $protectedBefore = protectedSnapshot($pdo);
    $plan = buildPlan($pdo, $verified['rows']);
    if ($plan['conflicts'] !== 0) throw new RuntimeException('2024 conflict bulundu; apply/dry-run durduruldu.');

    if ($mode === 'apply') {
        $insert = $pdo->prepare(<<<'SQL'
INSERT INTO universities (
  program_code, university_name, faculty_name, department_name, city,
  university_type, score_type, education_type, education_language, scholarship_type,
  base_score, base_rank, rank_source_name, rank_source_url, rank_updated_at,
  quota, placed_count, duration_years, year, source_name, source_url
) VALUES (
  :program_code, :university_name, :faculty_name, :department_name, :city,
  :university_type, :score_type, :education_type, :education_language, :scholarship_type,
  :base_score, :base_rank, :rank_source_name, :rank_source_url, :rank_updated_at,
  :quota, :placed_count, :duration_years, :year, :source_name, :source_url
)
SQL);
        $rankUpdatedAt = gmdate('Y-m-d H:i:s');
        foreach ($verified['rows'] as $row) {
            $insert->execute([...$row, 'rank_updated_at' => $row['base_rank'] === null ? null : $rankUpdatedAt]);
        }
        $target = targetSummary($pdo);
        if ((int) $target['total_rows'] !== VERIFIED_2024_ROWS
            || (int) $target['unique_program_codes'] !== VERIFIED_2024_ROWS
            || (int) $target['duplicates'] !== 0
            || (int) $target['base_score_null'] !== 523
            || (int) $target['base_rank_null'] !== 4011
            || (int) $target['quota_null'] !== 0
            || (int) $target['placed_count_null'] !== 0) {
            throw new RuntimeException('Apply sonrası 2024 invariantları sağlanmadı.');
        }
        $protectedAfter = protectedSnapshot($pdo);
        if ($protectedBefore !== $protectedAfter) throw new RuntimeException('Korunan yıl snapshotı değişti.');
        $pdo->commit();
    } else {
        $target = targetSummary($pdo);
        $protectedAfter = protectedSnapshot($pdo);
        if ($protectedBefore !== $protectedAfter) throw new RuntimeException('Dry-run korunan yıl snapshotı değişti.');
        $pdo->rollBack();
    }

    $postCommitProtected = protectedSnapshot($pdo);
    $postCommitTarget = targetSummary($pdo);
    if ($protectedBefore !== $postCommitProtected) throw new RuntimeException('Commit sonrası korunan yıl snapshotı değişti.');
    $report = [
        'generated_at' => gmdate(DATE_ATOM), 'mode' => $mode,
        'source_csv' => ['rows' => VERIFIED_2024_ROWS, 'sha256' => VERIFIED_2024_CSV_SHA256,
            'logical_sha256' => $verified['logical_sha256'], 'validation' => $verified['counts']],
        'schema' => $schema, 'dry_run_or_apply_plan' => $plan,
        'protected_before' => $protectedBefore, 'protected_after' => $postCommitProtected,
        'protected_unchanged' => true, 'target_before_or_in_transaction' => $target,
        'target_after' => $postCommitTarget,
        'target_405290156_imported' => false,
    ];
    $reportDirectory = dirname(__DIR__) . '/storage/reports';
    $reportPath = $reportDirectory . '/verified_2024_import_' . str_replace('-', '_', $mode)
        . '_' . gmdate('Ymd_His') . '.json';
    file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
    echo json_encode([...$report, 'report' => $reportPath], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, '2024 import tamamlanamadı: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
