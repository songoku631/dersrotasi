<?php

declare(strict_types=1);

use DersRotasi\Config\Env;
use DersRotasi\Database\Connection;
use DersRotasi\Historical\HistoricalProgramMappingRepository;
use DersRotasi\Historical\HistoricalProgramMatcher;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Bu araç yalnızca komut satırından çalıştırılabilir.\n");
    exit(1);
}

$apply = false;
$historicalYear = 2023;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--dry-run') {
        continue;
    }
    if ($argument === '--apply') {
        $apply = true;
        continue;
    }
    if (str_starts_with($argument, '--year=')) {
        $historicalYear = (int) substr($argument, strlen('--year='));
        continue;
    }
    throw new RuntimeException("Bilinmeyen argüman: {$argument}");
}
if (!in_array($historicalYear, [2023, 2024], true)) {
    throw new RuntimeException('Yalnız 2023 veya 2024 historical mapping desteklenir.');
}

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();
$env = new Env($_ENV);
if ($apply && ($env->appEnv() !== 'local'
    || !in_array($env->dbHost(), ['127.0.0.1', 'localhost'], true)
    || $env->instanceConnectionName() !== null)) {
    throw new RuntimeException('Apply yalnız local TCP veritabanında çalışabilir.');
}

$pdo = Connection::make($env);
$current = $pdo->prepare(<<<'SQL'
SELECT id, program_code, university_name, faculty_name, department_name, city,
       education_language, scholarship_type, education_type, score_type,
       duration_years, base_score, base_rank, quota
FROM universities current_program
WHERE year = 2025
  AND program_code IS NOT NULL
  AND program_code <> ''
  AND NOT EXISTS (
    SELECT 1 FROM universities historical_same_code
    WHERE historical_same_code.program_code = current_program.program_code
      AND historical_same_code.year = :historical_year
  )
ORDER BY program_code
SQL);
$current->execute(['historical_year' => $historicalYear]);
$currentRows = $current->fetchAll();

$historical = $pdo->prepare(<<<'SQL'
SELECT id, program_code, university_name, faculty_name, department_name, city,
       education_language, scholarship_type, education_type, score_type,
       duration_years, base_score, base_rank, quota
FROM universities
WHERE year = :historical_year
  AND program_code IS NOT NULL
  AND program_code <> ''
  AND base_rank IS NOT NULL
ORDER BY program_code
SQL);
$historical->execute(['historical_year' => $historicalYear]);
$historicalRows = $historical->fetchAll();

$matcher = new HistoricalProgramMatcher();
$analysis = $matcher->analyze($currentRows, $historicalRows, $historicalYear);
$manualReviewCodes = array_fill_keys(
    array_column($analysis['manual_review'], 'current_program_code'),
    true,
);
$withoutAnyCandidate = array_values(array_filter(
    $analysis['unmatched'],
    static fn (array $item): bool => !isset($manualReviewCodes[$item['current_program_code']]),
));
$riskNames = ['Hemşirelik', 'Dış Ticaret', 'Bilgisayar Programcılığı', 'İşletme', 'Psikoloji', 'Tıp'];
$protectedFields = ['faculty', 'language', 'scholarship', 'education_type'];
$signatureWithout = static function (array $normalized, string $excluded): string {
    unset($normalized[$excluded]);
    return implode("\x1F", array_map('strval', array_values($normalized)));
};
$protectedConflictIndex = [];
foreach ($historicalRows as $historicalRow) {
    $normalized = $matcher->normalized($historicalRow);
    foreach ($protectedFields as $protectedField) {
        $protectedConflictIndex[$protectedField][$signatureWithout($normalized, $protectedField)][] = [
            'row' => $historicalRow,
            'normalized' => $normalized,
        ];
    }
}
$protectedFieldConflicts = [];
foreach ($currentRows as $currentRow) {
    $normalized = $matcher->normalized($currentRow);
    if (in_array('', $normalized, true) || (int) $normalized['duration'] < 1) {
        continue;
    }
    foreach ($protectedFields as $protectedField) {
        $candidates = $protectedConflictIndex[$protectedField][
            $signatureWithout($normalized, $protectedField)
        ] ?? [];
        foreach ($candidates as $candidate) {
            if ($normalized[$protectedField] === $candidate['normalized'][$protectedField]) {
                continue;
            }
            $protectedFieldConflicts[] = [
                'current_program_code' => (string) $currentRow['program_code'],
                'historical_program_code' => (string) $candidate['row']['program_code'],
                'historical_year' => $historicalYear,
                'university_name' => (string) $currentRow['university_name'],
                'faculty_name' => (string) $currentRow['faculty_name'],
                'program_name' => (string) $currentRow['department_name'],
                'rejected_field' => $protectedField,
                'current_value' => $normalized[$protectedField],
                'historical_value' => $candidate['normalized'][$protectedField],
                'reason' => 'protected_semantic_field_differs_not_automatic',
            ];
        }
    }
}
$riskReport = [];
foreach ($riskNames as $riskName) {
    $contains = static fn (array $item): bool => preg_match(
        '/^' . preg_quote($riskName, '/') . '(?:\s|\(|$)/ui',
        (string) ($item['program_name'] ?? ''),
    ) === 1;
    $riskReport[$riskName] = [
        'high_confidence' => count(array_filter($analysis['matches'], $contains)),
        'ambiguous' => count(array_filter($analysis['ambiguous'], $contains)),
        'no_candidate' => count(array_filter($withoutAnyCandidate, $contains)),
        'manual_review_only' => count(array_filter($analysis['manual_review'], $contains)),
        'protected_field_conflicts' => count(array_filter($protectedFieldConflicts, $contains)),
    ];
}

$applyResult = null;
if ($apply) {
    $repository = new HistoricalProgramMappingRepository($pdo);
    $repository->assertSchemaReady();
    $applyResult = $repository->applyVerified($analysis['matches']);
}

$report = [
    'generated_at' => date(DATE_ATOM),
    'mode' => $apply ? 'apply' : 'dry_run',
    'historical_year' => $historicalYear,
    'rules_version' => 'strict_normalized_v1',
    'counts' => [
        'current_without_same_code_row' => count($currentRows),
        'historical_ranked_rows' => count($historicalRows),
        'high_confidence' => count($analysis['matches']),
        'ambiguous' => count($analysis['ambiguous']),
        'no_candidate' => count($withoutAnyCandidate),
        'unmatched_total' => count($analysis['unmatched']),
        'manual_review_only' => count($analysis['manual_review']),
        'protected_field_conflicts' => count($protectedFieldConflicts),
    ],
    'risk_programs' => $riskReport,
    'apply_result' => $applyResult,
    'matches' => $analysis['matches'],
    'ambiguous' => $analysis['ambiguous'],
    'unmatched' => $analysis['unmatched'],
    'manual_review' => $analysis['manual_review'],
    'protected_field_conflicts' => $protectedFieldConflicts,
];

$reportDirectory = $root . '/storage/reports';
if (!is_dir($reportDirectory) && !mkdir($reportDirectory, 0770, true) && !is_dir($reportDirectory)) {
    throw new RuntimeException('Mapping report dizini oluşturulamadı.');
}
$reportPath = $reportDirectory . '/program_historical_mapping_' . ($apply ? 'apply_' : 'dry_run_')
    . date('Ymd_His') . '.json';
file_put_contents(
    $reportPath,
    json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
    LOCK_EX,
);

echo sprintf(
    "Mode=%s year=%d missing_same_code=%d high=%d ambiguous=%d no_candidate=%d manual_review=%d\n",
    $apply ? 'APPLY' : 'DRY-RUN',
    $historicalYear,
    $report['counts']['current_without_same_code_row'],
    $report['counts']['high_confidence'],
    $report['counts']['ambiguous'],
    $report['counts']['no_candidate'],
    $report['counts']['manual_review_only'],
);
if ($applyResult !== null) {
    echo "Inserted={$applyResult['inserted']} skipped_existing={$applyResult['skipped_existing']}\n";
}
foreach (array_slice($analysis['matches'], 0, 20) as $match) {
    echo sprintf(
        "%s -> %s | %s | %s | %s | %s\n",
        $match['current_program_code'],
        $match['historical_program_code'],
        $match['university_name'],
        $match['faculty_name'],
        $match['program_name'],
        $match['reason'],
    );
}
foreach (array_slice($analysis['manual_review'], 0, max(0, 20 - count($analysis['matches']))) as $review) {
    foreach ($review['candidates'] as $candidate) {
        echo sprintf(
            "REJECTED %s -> %s | %s | %s | %s | %s\n",
            $review['current_program_code'],
            $candidate['historical_program_code'],
            $review['university_name'],
            $review['faculty_name'],
            $review['program_name'],
            $review['reason'],
        );
    }
}
foreach (array_slice($protectedFieldConflicts, 0, 5) as $conflict) {
    echo sprintf(
        "REJECTED %s -> %s | %s | %s | %s | protected_%s_differs\n",
        $conflict['current_program_code'],
        $conflict['historical_program_code'],
        $conflict['university_name'],
        $conflict['faculty_name'],
        $conflict['program_name'],
        $conflict['rejected_field'],
    );
}
echo "Report={$reportPath}\n";
