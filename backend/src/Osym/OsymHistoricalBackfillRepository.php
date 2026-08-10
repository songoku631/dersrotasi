<?php

declare(strict_types=1);

namespace DersRotasi\Osym;

use PDO;
use RuntimeException;

final class OsymHistoricalBackfillRepository
{
    private const ALLOWED_FIELDS = ['base_score', 'base_rank', 'quota', 'placed_count'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function assertSchemaCompatible(): array
    {
        $columns = [];
        foreach ($this->pdo->query('SHOW COLUMNS FROM universities')->fetchAll() as $column) {
            $columns[(string) $column['Field']] = strtolower((string) $column['Type']);
        }
        foreach (['id', 'program_code', 'year', ...self::ALLOWED_FIELDS] as $required) {
            if (!isset($columns[$required])) {
                throw new RuntimeException("universities.{$required} kolonu bulunamadı.");
            }
        }
        foreach (['quota', 'placed_count'] as $integerField) {
            if (!str_contains($columns[$integerField], 'int')) {
                throw new RuntimeException("universities.{$integerField} integer değil; alan yazılmayacak.");
            }
        }
        if (!str_contains($columns['base_score'], 'decimal') || !str_contains($columns['base_rank'], 'int')) {
            throw new RuntimeException('universities puan/sıra kolon tipleri backfill ile uyumlu değil.');
        }

        return array_intersect_key($columns, array_flip(['base_score', 'base_rank', 'quota', 'placed_count', 'year']));
    }

    /** @return list<array<string, mixed>> */
    public function historicalRows(): array
    {
        return $this->pdo->query(<<<'SQL'
SELECT id, program_code, year, base_score, base_rank, quota, placed_count
FROM universities
WHERE year IN (2023, 2024)
ORDER BY year, program_code, id
SQL)->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function programHistory(string $programCode): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT program_code, year, base_score, base_rank, quota, placed_count
FROM universities
WHERE program_code = :program_code AND year IN (2023, 2024, 2025, 2026)
ORDER BY year
SQL);
        $statement->execute(['program_code' => $programCode]);
        return $statement->fetchAll();
    }

    /** @return array<string, int|bool> */
    public function rankCoverage(): array
    {
        $year2024 = $this->pdo->query(<<<'SQL'
SELECT COUNT(*) AS total,
       SUM(CASE WHEN base_rank IS NULL THEN 1 ELSE 0 END) AS rank_null
FROM universities
WHERE year = 2024
SQL)->fetch();

        $currentCoverage = $this->pdo->query(<<<'SQL'
SELECT COUNT(*) AS current_2025,
       SUM(CASE WHEN historical_2024.id IS NOT NULL AND historical_2024.base_rank IS NULL THEN 1 ELSE 0 END) AS row_exists_rank_null,
       SUM(CASE WHEN historical_2024.id IS NULL THEN 1 ELSE 0 END) AS row_missing,
       SUM(CASE WHEN historical_2024.base_rank IS NULL THEN 1 ELSE 0 END) AS rank_missing
FROM universities current_2025
LEFT JOIN universities historical_2024
  ON historical_2024.program_code = current_2025.program_code
 AND historical_2024.year = 2024
WHERE current_2025.year = 2025
SQL)->fetch();

        $mappingTablePresent = (int) $this->pdo->query(<<<'SQL'
SELECT COUNT(*)
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'program_historical_mappings'
SQL)->fetchColumn() === 1;

        $mappingJoins = '';
        $rank2024 = 'historical_2024.base_rank';
        $rank2023 = 'historical_2023.base_rank';
        if ($mappingTablePresent) {
            $mappingJoins = <<<'SQL'
 LEFT JOIN program_historical_mappings mapping_2024
  ON mapping_2024.current_program_code = current_2025.program_code
 AND mapping_2024.historical_year = 2024
 AND mapping_2024.confidence = 'high'
 AND mapping_2024.verification_status = 'verified'
 AND historical_2024.id IS NULL
 LEFT JOIN universities mapped_2024
  ON mapped_2024.program_code = mapping_2024.historical_program_code
 AND mapped_2024.year = 2024
 AND historical_2024.id IS NULL
 LEFT JOIN program_historical_mappings mapping_2023
  ON mapping_2023.current_program_code = current_2025.program_code
 AND mapping_2023.historical_year = 2023
 AND mapping_2023.confidence = 'high'
 AND mapping_2023.verification_status = 'verified'
 AND historical_2023.id IS NULL
 LEFT JOIN universities mapped_2023
  ON mapped_2023.program_code = mapping_2023.historical_program_code
 AND mapped_2023.year = 2023
 AND historical_2023.id IS NULL
SQL;
            $rank2024 = 'CASE WHEN historical_2024.id IS NOT NULL THEN historical_2024.base_rank ELSE mapped_2024.base_rank END';
            $rank2023 = 'CASE WHEN historical_2023.id IS NOT NULL THEN historical_2023.base_rank ELSE mapped_2023.base_rank END';
        }

        $series = $this->pdo->query(
            'SELECT '
            . "SUM(CASE WHEN current_2025.base_rank IS NOT NULL AND {$rank2024} IS NOT NULL "
            . "AND {$rank2023} IS NOT NULL THEN 1 ELSE 0 END) AS complete_three_year_series "
            . 'FROM universities current_2025 '
            . 'LEFT JOIN universities historical_2024 ON historical_2024.program_code = current_2025.program_code '
            . 'AND historical_2024.year = 2024 '
            . 'LEFT JOIN universities historical_2023 ON historical_2023.program_code = current_2025.program_code '
            . 'AND historical_2023.year = 2023 '
            . $mappingJoins
            . ' WHERE current_2025.year = 2025'
        )->fetch();

        return [
            'total_2024_rows' => (int) $year2024['total'],
            'null_2024_ranks' => (int) $year2024['rank_null'],
            'current_2025_programs' => (int) $currentCoverage['current_2025'],
            'current_2025_missing_2024_rank' => (int) $currentCoverage['rank_missing'],
            'same_code_2024_row_rank_null' => (int) $currentCoverage['row_exists_rank_null'],
            'same_code_2024_row_missing' => (int) $currentCoverage['row_missing'],
            'complete_three_year_series' => (int) $series['complete_three_year_series'],
            'verified_mapping_table_present' => $mappingTablePresent,
        ];
    }

    public function beginReadOnly(): void
    {
        $this->pdo->exec('SET SESSION TRANSACTION READ ONLY');
        $this->pdo->beginTransaction();
    }

    public function beginWrite(): void
    {
        $this->pdo->exec('SET SESSION TRANSACTION READ WRITE');
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * @param list<array{id: int, program_code: string, year: int, fields: array<string, mixed>}> $updates
     * @return array{updated_rows: int, updated_cells: int, fields: array<string, int>}
     */
    public function applyUpdates(array $updates): array
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('ÖSYM backfill UPDATE yalnız transaction içinde çalışabilir.');
        }
        $result = [
            'updated_rows' => 0,
            'updated_cells' => 0,
            'fields' => array_fill_keys(self::ALLOWED_FIELDS, 0),
        ];
        $statements = [];
        foreach ($updates as $update) {
            $year = (int) $update['year'];
            if (!in_array($year, [2023, 2024], true)) {
                throw new RuntimeException('ÖSYM backfill 2023/2024 dışındaki bir yılı güncellemeyi reddetti.');
            }
            $fields = $update['fields'];
            if ($fields === []) {
                continue;
            }
            foreach (array_keys($fields) as $field) {
                if (!in_array($field, self::ALLOWED_FIELDS, true)) {
                    throw new RuntimeException("İzin verilmeyen ÖSYM backfill alanı: {$field}");
                }
            }
            ksort($fields);
            $signature = implode(',', array_keys($fields));
            if (!isset($statements[$signature])) {
                $set = implode(', ', array_map(static fn (string $field): string => "{$field} = :{$field}", array_keys($fields)));
                $nullGuards = implode(' AND ', array_map(
                    static fn (string $field): string => "{$field} IS NULL",
                    array_keys($fields),
                ));
                $statements[$signature] = $this->pdo->prepare(
                    "UPDATE universities SET {$set} "
                    . 'WHERE id = :id AND program_code = :program_code AND year = :year '
                    . "AND year IN (2023, 2024) AND {$nullGuards}"
                );
            }
            $statement = $statements[$signature];
            $statement->execute([
                ...$fields,
                'id' => (int) $update['id'],
                'program_code' => (string) $update['program_code'],
                'year' => $year,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException(
                    "NULL-only guard güncellemeyi reddetti: {$update['program_code']}/{$year}"
                );
            }
            $result['updated_rows']++;
            foreach (array_keys($fields) as $field) {
                $result['fields'][$field]++;
                $result['updated_cells']++;
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function integritySnapshot(): array
    {
        $yearCounts = array_fill_keys([2023, 2024, 2025, 2026], 0);
        foreach ($this->pdo->query(<<<'SQL'
SELECT year, COUNT(*) AS total
FROM universities
WHERE year IN (2023, 2024, 2025, 2026)
GROUP BY year
ORDER BY year
SQL)->fetchAll() as $row) {
            $yearCounts[(int) $row['year']] = (int) $row['total'];
        }
        $distinctCodes = (int) $this->pdo->query('SELECT COUNT(DISTINCT program_code) FROM universities')->fetchColumn();
        $duplicates = (int) $this->pdo->query(<<<'SQL'
SELECT COUNT(*) FROM (
  SELECT program_code, year
  FROM universities
  GROUP BY program_code, year
  HAVING COUNT(*) > 1
) duplicate_pairs
SQL)->fetchColumn();

        $contexts = [2025 => hash_init('sha256'), 2026 => hash_init('sha256')];
        $protectedCounts = [2025 => 0, 2026 => 0];
        $statement = $this->pdo->query(<<<'SQL'
SELECT *
FROM universities
WHERE year IN (2025, 2026)
ORDER BY year, program_code, id
SQL);
        while (($row = $statement->fetch()) !== false) {
            $year = (int) $row['year'];
            $protectedCounts[$year]++;
            hash_update($contexts[$year], json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        }

        return [
            'year_counts' => $yearCounts,
            'distinct_program_codes' => $distinctCodes,
            'duplicate_program_code_year' => $duplicates,
            'protected_years' => [
                2025 => ['total' => $protectedCounts[2025], 'sha256' => hash_final($contexts[2025])],
                2026 => ['total' => $protectedCounts[2026], 'sha256' => hash_final($contexts[2026])],
            ],
        ];
    }

    public function assertIntegrityUnchanged(array $before, array $after): void
    {
        foreach ([2023, 2024, 2025, 2026] as $year) {
            if (($before['year_counts'][$year] ?? null) !== ($after['year_counts'][$year] ?? null)) {
                throw new RuntimeException("ÖSYM backfill year={$year} satır sayısını değiştirdi.");
            }
        }
        if ($before['distinct_program_codes'] !== $after['distinct_program_codes']) {
            throw new RuntimeException('ÖSYM backfill distinct program_code sayısını değiştirdi.');
        }
        if ($before['duplicate_program_code_year'] !== $after['duplicate_program_code_year']) {
            throw new RuntimeException('ÖSYM backfill duplicate(program_code, year) sonucunu değiştirdi.');
        }
        foreach ([2025, 2026] as $year) {
            if ($before['protected_years'][$year] !== $after['protected_years'][$year]) {
                throw new RuntimeException("Korunan year={$year} checksum değişti.");
            }
        }
    }
}
