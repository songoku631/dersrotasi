<?php

declare(strict_types=1);

namespace DersRotasi\Repositories;

final class UniversityHistoryPresenter
{
    public function present(array $row): array
    {
        $sourceYear = (int) ($row['source_year'] ?? $row['year'] ?? 0);
        $row['source_year'] = $sourceYear;
        $row['year'] = $sourceYear === 2026 ? 2025 : $sourceYear;
        $row['id'] = (int) $row['id'];
        $row['is_favorite'] = (int) ($row['is_favorite'] ?? 0);
        $row['favorite_id'] = $this->nullableInt($row['favorite_id'] ?? null);
        $row['base_rank'] = $this->nullableInt($row['base_rank'] ?? null);
        $row['base_score'] = $this->nullableFloat($row['base_score'] ?? null);
        $row['quota'] = $this->nullableInt($row['quota'] ?? null);
        $row['placed_count'] = $this->nullableInt($row['placed_count'] ?? null);
        $row['duration_years'] = $this->nullableInt($row['duration_years'] ?? null);

        $row['rankings'] = [];
        $row['scores'] = [];
        $row['quotas'] = [];
        foreach ([2025, 2024, 2023] as $year) {
            $row['rankings'][(string) $year] = $this->nullableInt($row['ranking_' . $year] ?? null);
            $row['scores'][(string) $year] = $this->nullableFloat($row['score_' . $year] ?? null);
            $row['quotas'][(string) $year] = $this->nullableInt($row['quota_' . $year] ?? null);
            unset($row['ranking_' . $year], $row['score_' . $year], $row['quota_' . $year]);
        }

        return $row;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}
