<?php

declare(strict_types=1);

use DersRotasi\Import\UniversityRankCsvReader;
use DersRotasi\Import\UniversityRankImportService;
use DersRotasi\Import\UniversityRankStore;

require dirname(__DIR__) . '/vendor/autoload.php';

final class InMemoryUniversityRankStore implements UniversityRankStore
{
    public bool $transaction = false;
    public bool $committed = false;
    public bool $rolledBack = false;
    public bool $throwOnUpdate = false;
    public array $updates = [];

    public function __construct(public array $records)
    {
    }

    public function beginTransaction(): void { $this->transaction = true; }
    public function commit(): void { $this->transaction = false; $this->committed = true; }
    public function rollBack(): void { $this->transaction = false; $this->rolledBack = true; }
    public function inTransaction(): bool { return $this->transaction; }

    public function find(string $programCode, int $year): ?array
    {
        $record = $this->records[$programCode] ?? null;
        return $record !== null && $record['year'] === $year ? $record : null;
    }

    public function updateRank(
        int $id,
        string $programCode,
        int $year,
        int $baseRank,
        string $sourceName,
        ?string $sourceUrl
    ): void {
        if ($this->throwOnUpdate) {
            throw new RuntimeException('Test rollback');
        }
        $this->updates[] = compact('id', 'programCode', 'year', 'baseRank', 'sourceName', 'sourceUrl');
        $this->records[$programCode]['base_rank'] = $baseRank;
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function row(string $code, int $rank, int $line = 2): array
{
    return [
        'program_code' => $code,
        'base_rank' => $rank,
        'year' => 2025,
        'source_name' => 'Test kaynağı',
        'source_url' => null,
        'line' => $line,
    ];
}

$reader = new UniversityRankCsvReader();
check($reader->validateRow([
    'program_code' => '999999991', 'base_rank' => '1.234', 'year' => '2025',
    'source_name' => 'Test kaynağı', 'source_url' => '',
])['base_rank'] === 1234, 'Geçerli base_rank normalize edilmedi.');
foreach (['', '0', '12,5', '-1'] as $invalidRank) {
    try {
        $reader->validateRow([
            'program_code' => '999999991', 'base_rank' => $invalidRank, 'year' => '2025',
            'source_name' => 'Test kaynağı', 'source_url' => '',
        ]);
        throw new RuntimeException('Geçersiz base_rank kabul edildi.');
    } catch (InvalidArgumentException $exception) {
        check($exception->getCode() === UniversityRankCsvReader::INVALID_BASE_RANK, 'Hata türü yanlış.');
    }
}

$csv = tempnam(sys_get_temp_dir(), 'rank_import_test_');
check($csv !== false, 'Geçici CSV oluşturulamadı.');
file_put_contents($csv, "\xEF\xBB\xBFprogram_code,base_rank,year,source_name,source_url\n"
    . "999999991,100,2025,Test kaynağı,\n"
    . "999999991,200,2025,Test kaynağı,\n"
    . "999999992,,2025,Test kaynağı,\n");
$batch = $reader->read($csv);
unlink($csv);
check($batch['counts']['duplicate_program_codes'] === 1, 'Duplicate kod algılanmadı.');
check($batch['counts']['invalid_base_rank'] === 1, 'Boş base_rank reddedilmedi.');
check($batch['rows'] === [], 'Duplicate satır işleme listesinden çıkarılmadı.');

$records = [
    '999999991' => ['id' => 1, 'program_code' => '999999991', 'year' => 2025, 'base_rank' => null, 'base_score' => '400.00000'],
    '999999992' => ['id' => 2, 'program_code' => '999999992', 'year' => 2025, 'base_rank' => 200, 'base_score' => '410.00000'],
    '999999993' => ['id' => 3, 'program_code' => '999999993', 'year' => 2025, 'base_rank' => 300, 'base_score' => '420.00000'],
];
$rows = [row('999999991', 100), row('999999992', 200, 3), row('999999993', 350, 4), row('999999994', 400, 5)];
$dryStore = new InMemoryUniversityRankStore($records);
$dryResult = (new UniversityRankImportService($dryStore))->run($rows, false, static fn (): bool => true);
check($dryResult['counts']['records_to_update'] === 2, 'Güncellenecek kayıt sayısı yanlış.');
check($dryResult['counts']['unchanged_records'] === 1, 'Aynı değer algılanmadı.');
check($dryResult['counts']['unmatched_program_codes'] === 1, 'Eşleşmeyen kod algılanmadı.');
check($dryResult['counts']['conflicting_existing_values'] === 1, 'Çakışan değer algılanmadı.');
check($dryStore->updates === [] && $dryStore->rolledBack, 'Dry-run veri değiştirdi.');

$applyStore = new InMemoryUniversityRankStore($records);
$applyResult = (new UniversityRankImportService($applyStore))->run($rows, true, static fn (): bool => true);
check($applyResult['counts']['updated_records'] === 2 && $applyStore->committed, 'Apply güncellemeleri tamamlamadı.');
check($applyStore->records['999999991']['base_score'] === '400.00000', 'İzin verilmeyen kolon değişti.');
check(array_keys($applyStore->updates[0]) === ['id', 'programCode', 'year', 'baseRank', 'sourceName', 'sourceUrl'], 'Update alanları beklenenden farklı.');

$rollbackStore = new InMemoryUniversityRankStore($records);
$rollbackStore->throwOnUpdate = true;
try {
    (new UniversityRankImportService($rollbackStore))->run([row('999999991', 100)], true, static fn (): bool => true);
    throw new RuntimeException('Update hatası transactionı durdurmadı.');
} catch (RuntimeException) {
    check($rollbackStore->rolledBack && !$rollbackStore->committed, 'Transaction rollback çalışmadı.');
}

echo "UniversityRankImportTest: OK\n";
