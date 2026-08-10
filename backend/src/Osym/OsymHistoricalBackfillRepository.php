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
