<?php

declare(strict_types=1);

namespace DersRotasi\Yokatlas;

use PDO;
use RuntimeException;
use Throwable;

final class HistoricalUniversityRepository
{
    private const INSERT_SQL = <<<'SQL'
INSERT INTO universities (
  program_code, university_name, faculty_name, department_name, city,
  university_type, score_type, education_type, education_language,
  scholarship_type, base_score, base_rank, rank_source_name, rank_source_url,
  rank_updated_at, quota, placed_count, duration_years, year, source_name, source_url
) VALUES (
  :program_code, :university_name, :faculty_name, :department_name, :city,
  :university_type, :score_type, :education_type, :education_language,
  :scholarship_type, :base_score, :base_rank, :rank_source_name, :rank_source_url,
  :rank_updated_at, :quota, :placed_count, :duration_years, :year, :source_name, :source_url
)
ON DUPLICATE KEY UPDATE id = id
SQL;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function assertHistoricalSchemaReady(): void
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT COUNT(*)
FROM (
  SELECT INDEX_NAME
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'universities'
    AND NON_UNIQUE = 0
  GROUP BY INDEX_NAME
  HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') = 'program_code,year'
) AS matching_unique_indexes
SQL);
        if ((int) $statement->fetchColumn() < 1) {
            throw new RuntimeException(
                'Historical import için önce 009_make_university_program_year_unique.sql migrationı uygulanmalıdır.'
            );
        }
    }

    /**
     * Inserts only missing rows. Existing (program_code, year) rows are never updated.
     *
     * @return array{inserted: int, skipped_existing: int, statuses: array<string, string>}
     */
    public function insertMissing(array $rows): array
    {
        $result = ['inserted' => 0, 'skipped_existing' => 0, 'statuses' => []];
        if ($rows === []) {
            return $result;
        }

        try {
            $this->pdo->beginTransaction();
            $statement = $this->pdo->prepare(self::INSERT_SQL);
            foreach ($rows as $row) {
                $year = (int) ($row['year'] ?? 0);
                if (!in_array($year, [2023, 2024], true)) {
                    throw new RuntimeException('Historical repository yalnızca 2023 ve 2024 satırı kabul eder.');
                }
                $statement->execute($this->databaseRow($row));
                $key = (string) $row['program_code'] . ':' . $year;
                if ($statement->rowCount() === 1) {
                    $result['inserted']++;
                    $result['statuses'][$key] = 'inserted';
                } else {
                    $result['skipped_existing']++;
                    $result['statuses'][$key] = 'skipped_existing';
                }
            }
            $this->pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException('Historical import batch işlemi geri alındı.', 0, $exception);
        }
    }

    /**
     * @return array<string, true>
     */
    public function existingKeys(array $rows): array
    {
        $programCodes = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['program_code'],
            $rows,
        )));
        $years = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['year'],
            $rows,
        )));
        if ($programCodes === [] || $years === []) {
            return [];
        }

        $codePlaceholders = implode(',', array_fill(0, count($programCodes), '?'));
        $yearPlaceholders = implode(',', array_fill(0, count($years), '?'));
        $statement = $this->pdo->prepare(
            "SELECT program_code, year FROM universities "
            . "WHERE year IN ({$yearPlaceholders}) AND program_code IN ({$codePlaceholders})"
        );
        $statement->execute([...$years, ...$programCodes]);
        $keys = [];
        foreach ($statement->fetchAll() as $row) {
            $keys[(string) $row['program_code'] . ':' . (int) $row['year']] = true;
        }
        return $keys;
    }

    /**
     * Content fingerprint for years that this importer must never mutate.
     *
     * @return array<int, array<string, int|string>>
     */
    public function protectedYearSnapshot(): array
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT
  year,
  COUNT(*) AS total,
  COUNT(DISTINCT program_code) AS distinct_codes,
  SUM(base_rank IS NULL) AS rank_nulls,
  SUM(base_score IS NULL) AS score_nulls,
  SUM(CRC32(CONCAT_WS('|',
    id, program_code, university_name, faculty_name, department_name, city,
    university_type, score_type, education_type, education_language, scholarship_type,
    COALESCE(base_score, 'NULL'), COALESCE(base_rank, 'NULL'),
    COALESCE(rank_source_name, 'NULL'), COALESCE(rank_source_url, 'NULL'),
    COALESCE(rank_updated_at, 'NULL'), COALESCE(quota, 'NULL'),
    COALESCE(placed_count, 'NULL'), COALESCE(duration_years, 'NULL'), year,
    source_name, COALESCE(source_url, 'NULL'), created_at, updated_at
  ))) AS content_crc_sum,
  BIT_XOR(CRC32(CONCAT_WS('|',
    id, program_code, university_name, faculty_name, department_name, city,
    university_type, score_type, education_type, education_language, scholarship_type,
    COALESCE(base_score, 'NULL'), COALESCE(base_rank, 'NULL'),
    COALESCE(rank_source_name, 'NULL'), COALESCE(rank_source_url, 'NULL'),
    COALESCE(rank_updated_at, 'NULL'), COALESCE(quota, 'NULL'),
    COALESCE(placed_count, 'NULL'), COALESCE(duration_years, 'NULL'), year,
    source_name, COALESCE(source_url, 'NULL'), created_at, updated_at
  ))) AS content_crc_xor
FROM universities
WHERE year IN (2025, 2026)
GROUP BY year
ORDER BY year
SQL);
        $snapshot = [];
        foreach ($statement->fetchAll() as $row) {
            $year = (int) $row['year'];
            unset($row['year']);
            $snapshot[$year] = array_map('strval', $row);
        }
        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseRow(array $row): array
    {
        return array_intersect_key($row, array_flip([
            'program_code', 'university_name', 'faculty_name', 'department_name', 'city',
            'university_type', 'score_type', 'education_type', 'education_language',
            'scholarship_type', 'base_score', 'base_rank', 'rank_source_name', 'rank_source_url',
            'rank_updated_at', 'quota', 'placed_count', 'duration_years', 'year',
            'source_name', 'source_url',
        ]));
    }
}
