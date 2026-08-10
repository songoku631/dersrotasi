<?php

declare(strict_types=1);

use DersRotasi\Config\Env;
use DersRotasi\Database\Connection;
use DersRotasi\Historical\HistoricalProgramMatcher;
use DersRotasi\Yokatlas\YokatlasClient;
use DersRotasi\Yokatlas\YokatlasDurationValidator;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Bu araç yalnız komut satırından çalıştırılabilir.');
}

$apply = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--dry-run') {
        continue;
    }
    if ($argument === '--apply') {
        $apply = true;
        continue;
    }
    throw new RuntimeException("Bilinmeyen argüman: {$argument}");
}

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();
$env = new Env($_ENV);
if ($env->appEnv() !== 'local'
    || $env->instanceConnectionName() !== null
    || !in_array($env->dbHost(), ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Süre backfill yalnız local TCP veritabanında çalışabilir.');
}

$pdo = Connection::make($env);
$current = $pdo->query(<<<'SQL'
SELECT id, program_code, university_name, faculty_name, department_name, city,
       education_language, scholarship_type, education_type, score_type,
       duration_years, base_score, base_rank, quota
FROM universities current_program
WHERE year = 2025
  AND duration_years IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM universities historical_same_code
    WHERE historical_same_code.program_code = current_program.program_code
      AND historical_same_code.year = 2023
  )
ORDER BY program_code
SQL)->fetchAll();
$historical = $pdo->query(<<<'SQL'
SELECT id, program_code, university_name, faculty_name, department_name, city,
       education_language, scholarship_type, education_type, score_type,
       duration_years, base_score, base_rank, quota
FROM universities
WHERE year = 2023
  AND base_rank IS NOT NULL
ORDER BY program_code
SQL)->fetchAll();

$analysis = (new HistoricalProgramMatcher())->analyze($current, $historical, 2023);
$targets = $analysis['manual_review'];
$client = new YokatlasClient($env->yokatlasUserAgent(), 1000, $env->sslCaBundle());
$robots = $client->checkRobots();
$validator = new YokatlasDurationValidator();
$results = [];
$updates = [];
foreach ($targets as $target) {
    $programCode = (string) $target['current_program_code'];
    $response = $client->fetchProgram($programCode, 2026);
    if ($response['status'] !== 200) {
        $results[] = [
            'program_code' => $programCode,
            'status' => 'http_error',
            'http_status' => $response['status'],
        ];
        continue;
    }
    $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
    $validated = $validator->validate($decoded, $programCode, 2026);
    $result = [
        'program_code' => $programCode,
        'university_name' => $target['university_name'],
        'faculty_name' => $target['faculty_name'],
        'program_name' => $target['program_name'],
        ...$validated,
        'source_url' => 'https://yokatlas.yok.gov.tr/api/tercih-kilavuz/search',
    ];
    $results[] = $result;
    if ($validated['status'] === 'valid') {
        $updates[] = [
            'program_code' => $programCode,
            'duration_years' => (int) $validated['duration_years'],
        ];
    }
}

$applied = 0;
if ($apply && $updates !== []) {
    $statement = $pdo->prepare(<<<'SQL'
UPDATE universities
SET duration_years = :duration_years
WHERE program_code = :program_code
  AND year = 2025
  AND duration_years IS NULL
SQL);
    $pdo->beginTransaction();
    try {
        foreach ($updates as $update) {
            $statement->execute($update);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException(
                    "NULL-only duration guard güncellemeyi reddetti: {$update['program_code']}"
                );
            }
            $applied++;
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

$statusCounts = array_count_values(array_column($results, 'status'));
$report = [
    'generated_at' => date(DATE_ATOM),
    'mode' => $apply ? 'apply' : 'dry_run',
    'source' => 'YÖK Atlas 2026 tercih kılavuzu',
    'robots' => $robots,
    'strict_matcher_manual_review_before' => count($targets),
    'official_duration_valid' => $statusCounts['valid'] ?? 0,
    'applied' => $applied,
    'status_counts' => $statusCounts,
    'results' => $results,
];
$directory = $root . '/storage/reports';
if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
    throw new RuntimeException('Süre backfill rapor dizini oluşturulamadı.');
}
$path = $directory . '/yokatlas_duration_backfill_' . ($apply ? 'apply_' : 'dry_run_')
    . date('Ymd_His') . '.json';
file_put_contents(
    $path,
    json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
    LOCK_EX,
);

echo sprintf(
    "Mode=%s targets=%d official_valid=%d applied=%d statuses=%s\nReport=%s\n",
    $apply ? 'APPLY' : 'DRY-RUN',
    count($targets),
    $report['official_duration_valid'],
    $applied,
    json_encode($statusCounts, JSON_UNESCAPED_UNICODE),
    $path,
);
