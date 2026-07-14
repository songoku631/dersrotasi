<?php

declare(strict_types=1);

namespace DersRotasi\Services;

final class YksRankBacktestService
{
    private const SCORE_TYPES = ['say' => 'SAY', 'ea' => 'EA', 'soz' => 'SÖZ', 'dil' => 'DİL', 'tyt' => 'TYT'];

    public function __construct(private readonly YksRankEstimator $estimator = new YksRankEstimator())
    {
    }

    public function run(array $rows, array $policy): array
    {
        $minimumYears = max(2, (int) ($policy['minimum_distinct_years'] ?? 3));
        $minimumSamples = max(1, (int) ($policy['minimum_samples_per_score_type'] ?? 100));
        $grouped = $this->groupRows($rows);
        $results = [];
        $allYears = [];

        foreach (self::SCORE_TYPES as $databaseType => $displayType) {
            $years = array_keys($grouped[$databaseType] ?? []);
            sort($years, SORT_NUMERIC);
            $allYears = array_merge($allYears, $years);
            if (count($years) < $minimumYears) {
                $results[$displayType] = $this->insufficientResult(
                    $years,
                    sprintf('En az %d farklı yıl gerekli; kullanılabilir yıllar: %s.', $minimumYears, $years === [] ? 'yok' : implode(', ', $years))
                );
                continue;
            }

            $observations = [];
            foreach ($years as $holdoutYear) {
                $trainingRows = [];
                foreach ($years as $trainingYear) {
                    if ($trainingYear !== $holdoutYear) {
                        array_push($trainingRows, ...$grouped[$databaseType][$trainingYear]);
                    }
                }
                $curve = $this->estimator->prepare($trainingRows);
                foreach ($grouped[$databaseType][$holdoutYear] as $actual) {
                    $estimate = $this->estimator->estimatePrepared((float) $actual['base_score'], $curve, $holdoutYear);
                    if ($estimate['center'] === null) {
                        continue;
                    }
                    $actualRank = (int) $actual['base_rank'];
                    $absoluteError = abs((int) $estimate['center'] - $actualRank);
                    $observations[] = [
                        'program_code' => (string) $actual['program_code'],
                        'year' => $holdoutYear,
                        'actual_rank' => $actualRank,
                        'estimated_rank' => (int) $estimate['center'],
                        'absolute_rank_error' => $absoluteError,
                        'percentage_error' => ($absoluteError / $actualRank) * 100,
                        'covered' => $actualRank >= (int) $estimate['min'] && $actualRank <= (int) $estimate['max'],
                    ];
                }
            }

            if (count($observations) < $minimumSamples) {
                $results[$displayType] = $this->insufficientResult(
                    $years,
                    sprintf('Backtest için en az %d geçerli örnek gerekli; %d örnek üretilebildi.', $minimumSamples, count($observations)),
                    count($observations)
                );
                continue;
            }
            $results[$displayType] = $this->metrics($observations, $years, $policy['confidence_policy'] ?? []);
        }

        $allYears = array_values(array_unique($allYears));
        sort($allYears, SORT_NUMERIC);
        return [
            'method_version' => 'leave_one_year_out_rank_curve_v1',
            'generated_at' => gmdate(DATE_ATOM),
            'data_years' => $allYears,
            'requirements' => [
                'minimum_distinct_years' => $minimumYears,
                'minimum_samples_per_score_type' => $minimumSamples,
            ],
            'score_types' => $results,
            'limitations' => [
                'Bu backtest yalnızca program taban puanı ile başarı sırası dönüşümünü sınar.',
                'Aday bazında gerçek net-puan-sıra verisi olmadığı için netten puana kalibrasyonu backtest etmez.',
            ],
        ];
    }

    private function groupRows(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $scoreType = strtolower(trim((string) ($row['score_type'] ?? '')));
            $year = filter_var($row['year'] ?? null, FILTER_VALIDATE_INT);
            $score = isset($row['base_score']) && is_numeric($row['base_score']) ? (float) $row['base_score'] : 0.0;
            $rank = filter_var($row['base_rank'] ?? null, FILTER_VALIDATE_INT);
            if (!isset(self::SCORE_TYPES[$scoreType]) || $year === false || $score < 100 || $score > 600 || $rank === false || $rank < 1 || $rank > 5_000_000) {
                continue;
            }
            $grouped[$scoreType][(int) $year][] = [
                'program_code' => (string) ($row['program_code'] ?? ''),
                'base_score' => $score,
                'base_rank' => (int) $rank,
            ];
        }
        return $grouped;
    }

    private function metrics(array $observations, array $years, array $confidencePolicy): array
    {
        $errors = array_column($observations, 'absolute_rank_error');
        sort($errors, SORT_NUMERIC);
        $percentages = array_column($observations, 'percentage_error');
        $coverage = count(array_filter($observations, static fn (array $row): bool => $row['covered']));
        usort($observations, static fn (array $a, array $b): int => $a['absolute_rank_error'] <=> $b['absolute_rank_error']);
        $sampleCount = count($observations);
        $meanPercentageError = array_sum($percentages) / $sampleCount;
        $coverageRate = ($coverage / $sampleCount) * 100;

        return [
            'status' => 'validated',
            'confidence' => $this->confidence($meanPercentageError, $coverageRate, $confidencePolicy),
            'tested_years' => $years,
            'sample_count' => $sampleCount,
            'median_absolute_rank_error' => (int) round($this->median($errors)),
            'mean_percentage_error' => round($meanPercentageError, 2),
            'interval_coverage_rate' => round($coverageRate, 2),
            'best_example' => $this->example($observations[0]),
            'worst_example' => $this->example($observations[$sampleCount - 1]),
            'message' => 'Her yıl sırayla model dışında bırakıldı ve kalan yıllarla tahmin edildi.',
        ];
    }

    private function confidence(float $meanPercentageError, float $coverageRate, array $policy): string
    {
        $high = $policy['high'] ?? [];
        if ($meanPercentageError <= (float) ($high['maximum_mean_percentage_error'] ?? 15.0)
            && $coverageRate >= (float) ($high['minimum_coverage_rate'] ?? 80.0)) {
            return 'high';
        }
        $medium = $policy['medium'] ?? [];
        if ($meanPercentageError <= (float) ($medium['maximum_mean_percentage_error'] ?? 30.0)
            && $coverageRate >= (float) ($medium['minimum_coverage_rate'] ?? 60.0)) {
            return 'medium';
        }
        return 'low';
    }

    private function median(array $values): float
    {
        $count = count($values);
        $middle = intdiv($count, 2);
        return $count % 2 === 1 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    private function example(array $row): array
    {
        unset($row['covered']);
        $row['percentage_error'] = round((float) $row['percentage_error'], 2);
        return $row;
    }

    private function insufficientResult(array $years, string $message, int $sampleCount = 0): array
    {
        return [
            'status' => 'insufficient_data',
            'confidence' => 'unavailable',
            'tested_years' => $years,
            'sample_count' => $sampleCount,
            'median_absolute_rank_error' => null,
            'mean_percentage_error' => null,
            'interval_coverage_rate' => null,
            'best_example' => null,
            'worst_example' => null,
            'message' => $message,
        ];
    }
}
