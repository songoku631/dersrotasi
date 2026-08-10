<?php

declare(strict_types=1);

namespace DersRotasi\Osym;

use RuntimeException;

final class OsymHistoricalBackfillService
{
    private const DATABASE_FIELDS = [
        'base_score' => 'score',
        'base_rank' => 'rank',
        'quota' => 'quota',
        'placed_count' => 'placed_count',
    ];

    /**
     * @param list<array<string, mixed>> $databaseRows
     * @param array<int, array{result: array<string, array<string, mixed>>, guide: array<string, array<string, mixed>>}> $sources
     * @return array<string, mixed>
     */
    public function buildPlan(array $databaseRows, array $sources): array
    {
        $counts = [2023 => $this->emptyCounts(), 2024 => $this->emptyCounts()];
        $updates = [];
        $changes = [];
        $conflicts = [];

        foreach ($databaseRows as $databaseRow) {
            $year = (int) ($databaseRow['year'] ?? 0);
            if (!in_array($year, [2023, 2024], true)) {
                throw new RuntimeException('ÖSYM backfill servisi yalnız 2023/2024 satırlarını kabul eder.');
            }
            $programCode = (string) ($databaseRow['program_code'] ?? '');
            if (preg_match('/^[0-9]{9}$/', $programCode) !== 1) {
                throw new RuntimeException("DB program_code 9 haneli değil: {$programCode}");
            }
            $counts[$year]['examined']++;
            $result = $sources[$year]['result'][$programCode] ?? null;
            $guide = $sources[$year]['guide'][$programCode] ?? null;
            if ($result === null && $guide === null) {
                $counts[$year]['missing_source']++;
                continue;
            }
            $counts[$year]['program_code_matched']++;

            $official = $this->officialValues($programCode, $year, $result, $guide);
            foreach ($official['conflicts'] as $conflict) {
                $conflicts[] = $conflict;
                $counts[$year]['conflicts']++;
            }

            $rowChanges = [];
            foreach (self::DATABASE_FIELDS as $databaseField => $sourceField) {
                $candidate = $official['values'][$sourceField] ?? null;
                if ($candidate === null) {
                    continue;
                }
                $existing = $this->normalizeDatabaseValue($databaseField, $databaseRow[$databaseField] ?? null);
                if ($existing !== null) {
                    if (!$this->same($databaseField, $existing, $candidate)) {
                        $provenance = $official['provenance'][$sourceField];
                        $conflicts[] = [
                            'status' => 'conflict',
                            'program_code' => $programCode,
                            'year' => $year,
                            'field' => $databaseField,
                            'old_value' => $existing,
                            'new_value' => $candidate,
                            ...$provenance,
                            'reason' => 'non_null_database_value_differs',
                        ];
                        $counts[$year]['conflicts']++;
                    }
                    continue;
                }

                $provenance = $official['provenance'][$sourceField];
                $change = [
                    'status' => 'would_update',
                    'program_code' => $programCode,
                    'year' => $year,
                    'field' => $databaseField,
                    'old_value' => null,
                    'new_value' => $candidate,
                    ...$provenance,
                    'reason' => null,
                ];
                $changes[] = $change;
                $rowChanges[$databaseField] = $candidate;
                $counts[$year][$this->candidateCounter($databaseField)]++;
            }

            if ($rowChanges === []) {
                if ($official['has_usable_value']) {
                    $counts[$year]['unchanged']++;
                } else {
                    $counts[$year]['source_without_usable_values']++;
                }
                continue;
            }
            $counts[$year]['rows_to_update']++;
            $updates[] = [
                'id' => (int) $databaseRow['id'],
                'program_code' => $programCode,
                'year' => $year,
                'fields' => $rowChanges,
            ];
        }

        return [
            'counts' => $counts,
            'totals' => [
                'rows_to_update' => count($updates),
                'score_cells' => $counts[2023]['score_candidates'] + $counts[2024]['score_candidates'],
                'rank_cells' => $counts[2023]['rank_candidates'] + $counts[2024]['rank_candidates'],
                'quota_cells' => $counts[2023]['quota_candidates'] + $counts[2024]['quota_candidates'],
                'placed_count_cells' => $counts[2023]['placed_count_candidates']
                    + $counts[2024]['placed_count_candidates'],
                'conflicts' => count($conflicts),
                'missing_source' => $counts[2023]['missing_source'] + $counts[2024]['missing_source'],
            ],
            'updates' => $updates,
            'changes' => $changes,
            'conflicts' => $conflicts,
        ];
    }

    /**
     * @param list<array<string, mixed>> $databaseRows
     * @param array<string, array<string, mixed>> $guideRows
     * @return array<string, int>
     */
    public function auditRanks(array $databaseRows, array $guideRows, int $year): array
    {
        if (!in_array($year, [2023, 2024], true)) {
            throw new RuntimeException('ÖSYM rank audit yalnız 2023/2024 için çalışabilir.');
        }

        $databaseByCode = [];
        foreach ($databaseRows as $databaseRow) {
            if ((int) ($databaseRow['year'] ?? 0) === $year) {
                $databaseByCode[(string) $databaseRow['program_code']] = $databaseRow;
            }
        }

        $counts = [
            'official_programs' => count($guideRows),
            'official_ranked_programs' => 0,
            'database_rows' => count($databaseByCode),
            'program_code_matched' => 0,
            'null_rank_fillable' => 0,
            'already_filled_same' => 0,
            'conflicts' => 0,
            'database_without_official_program' => 0,
            'database_without_usable_official_rank' => 0,
            'official_without_database_row' => 0,
        ];

        foreach ($guideRows as $programCode => $guideRow) {
            if (($guideRow['rank'] ?? null) !== null) {
                $counts['official_ranked_programs']++;
            }
            if (!isset($databaseByCode[$programCode])) {
                $counts['official_without_database_row']++;
            }
        }

        foreach ($databaseByCode as $programCode => $databaseRow) {
            $guideRow = $guideRows[$programCode] ?? null;
            if ($guideRow === null) {
                $counts['database_without_official_program']++;
                continue;
            }
            $counts['program_code_matched']++;
            $officialRank = $guideRow['rank'] ?? null;
            if ($officialRank === null) {
                $counts['database_without_usable_official_rank']++;
                continue;
            }
            $databaseRank = $this->normalizeDatabaseValue('base_rank', $databaseRow['base_rank'] ?? null);
            if ($databaseRank === null) {
                $counts['null_rank_fillable']++;
            } elseif ($this->same('base_rank', $databaseRank, $officialRank)) {
                $counts['already_filled_same']++;
            } else {
                $counts['conflicts']++;
            }
        }

        return $counts;
    }

    /** @return array<string, int> */
    private function emptyCounts(): array
    {
        return [
            'examined' => 0,
            'program_code_matched' => 0,
            'score_candidates' => 0,
            'rank_candidates' => 0,
            'quota_candidates' => 0,
            'placed_count_candidates' => 0,
            'rows_to_update' => 0,
            'missing_source' => 0,
            'source_without_usable_values' => 0,
            'conflicts' => 0,
            'unchanged' => 0,
        ];
    }

    /**
     * @return array{values: array<string, mixed>, provenance: array<string, array<string, mixed>>, conflicts: list<array<string, mixed>>, has_usable_value: bool}
     */
    private function officialValues(
        string $programCode,
        int $year,
        ?array $result,
        ?array $guide,
    ): array {
        $resultScore = $result['score'] ?? null;
        $guideScore = $guide['score'] ?? null;
        $conflicts = [];
        $score = $resultScore ?? $guideScore;
        $scoreSource = $resultScore !== null ? $result : $guide;
        if ($resultScore !== null && $guideScore !== null && !$this->same('base_score', $resultScore, $guideScore)) {
            $conflicts[] = [
                'status' => 'conflict',
                'program_code' => $programCode,
                'year' => $year,
                'field' => 'base_score',
                'old_value' => $resultScore,
                'new_value' => $guideScore,
                'source' => $result['source'] . ' | ' . $guide['source'],
                'source_file' => $result['source_file'] . ' | ' . $guide['source_file'],
                'source_url' => $result['source_url'] . ' | ' . $guide['source_url'],
                'source_row' => $result['source_row'] . ' | ' . $guide['source_row'],
                'reason' => 'official_sources_disagree',
            ];
            $score = null;
            $scoreSource = null;
        }

        $values = [
            'score' => $score,
            'rank' => $guide['rank'] ?? null,
            'quota' => $result['quota'] ?? null,
            'placed_count' => $result['placed_count'] ?? null,
        ];
        $provenance = [
            'score' => $this->provenance($scoreSource),
            'rank' => $this->provenance($guide),
            'quota' => $this->provenance($result),
            'placed_count' => $this->provenance($result),
        ];

        return [
            'values' => $values,
            'provenance' => $provenance,
            'conflicts' => $conflicts,
            'has_usable_value' => array_filter($values, static fn (mixed $value): bool => $value !== null) !== [],
        ];
    }

    /** @return array{source: string, source_file: string, source_url: string, source_row: int|string} */
    private function provenance(?array $row): array
    {
        return [
            'source' => (string) ($row['source'] ?? ''),
            'source_file' => (string) ($row['source_file'] ?? ''),
            'source_url' => (string) ($row['source_url'] ?? ''),
            'source_row' => $row['source_row'] ?? '',
        ];
    }

    private function normalizeDatabaseValue(string $field, mixed $value): string|int|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($field === 'base_score') {
            return number_format((float) $value, 5, '.', '');
        }

        return (int) $value;
    }

    private function same(string $field, mixed $left, mixed $right): bool
    {
        if ($field === 'base_score') {
            return abs((float) $left - (float) $right) <= 0.0000051;
        }

        return (int) $left === (int) $right;
    }

    private function candidateCounter(string $field): string
    {
        return match ($field) {
            'base_score' => 'score_candidates',
            'base_rank' => 'rank_candidates',
            'quota' => 'quota_candidates',
            'placed_count' => 'placed_count_candidates',
            default => throw new RuntimeException("İzin verilmeyen backfill alanı: {$field}"),
        };
    }
}
