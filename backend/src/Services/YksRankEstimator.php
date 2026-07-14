<?php

declare(strict_types=1);

namespace DersRotasi\Services;

final class YksRankEstimator
{
    private const MIN_POINTS = 4;
    private const LOCAL_WINDOW = 8;
    private const MIN_SCORE = 100.0;
    private const MAX_SCORE = 600.0;
    private const MAX_RANK = 5_000_000;

    public function estimate(float $placementScore, array $rows, int $year, float $scoreUncertainty = 0.0): array
    {
        return $this->estimatePrepared($placementScore, $this->prepare($rows), $year, $scoreUncertainty);
    }

    public function prepare(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $score = isset($row['base_score']) ? (float) $row['base_score'] : 0.0;
            $rank = isset($row['base_rank']) ? (int) $row['base_rank'] : 0;
            if ($score < self::MIN_SCORE || $score > self::MAX_SCORE || $rank < 1 || $rank > self::MAX_RANK) {
                continue;
            }
            $key = number_format($score, 6, '.', '');
            $groups[$key]['score'] = $score;
            $groups[$key]['ranks'][] = $rank;
        }

        $points = [];
        foreach ($groups as $group) {
            sort($group['ranks'], SORT_NUMERIC);
            $count = count($group['ranks']);
            $middle = intdiv($count, 2);
            $rank = $count % 2 === 1
                ? $group['ranks'][$middle]
                : (int) round(($group['ranks'][$middle - 1] + $group['ranks'][$middle]) / 2);
            $points[] = ['score' => $group['score'], 'rank' => $rank];
        }
        usort($points, static fn (array $a, array $b): int => $a['score'] <=> $b['score']);
        return $points;
    }

    public function estimatePrepared(float $placementScore, array $points, int $year, float $scoreUncertainty = 0.0): array
    {
        $center = $this->estimatePoint($placementScore, $points, $year);
        if ($center['center'] === null || $scoreUncertainty <= 0.0) {
            return $center;
        }

        $lowerScore = $this->estimatePoint(max(self::MIN_SCORE, $placementScore - $scoreUncertainty), $points, $year);
        $upperScore = $this->estimatePoint(min(self::MAX_SCORE, $placementScore + $scoreUncertainty), $points, $year);
        $ranges = [$center, $lowerScore, $upperScore];
        $minimums = array_filter(array_column($ranges, 'min'), static fn (mixed $value): bool => $value !== null);
        $maximums = array_filter(array_column($ranges, 'max'), static fn (mixed $value): bool => $value !== null);
        $qualityOrder = ['unavailable' => 0, 'low' => 1, 'medium' => 2, 'high' => 3];
        $quality = $center['local_data_quality'];
        foreach ($ranges as $range) {
            if ($qualityOrder[$range['local_data_quality']] < $qualityOrder[$quality]) {
                $quality = $range['local_data_quality'];
            }
        }

        $center['min'] = min($minimums);
        $center['max'] = max($maximums);
        $center['local_data_quality'] = $quality;
        $center['outside_data_range'] = $center['outside_data_range']
            || $lowerScore['outside_data_range']
            || $upperScore['outside_data_range'];
        $center['score_uncertainty'] = round($scoreUncertainty, 3);
        return $center;
    }

    private function estimatePoint(float $placementScore, array $rows, int $year): array
    {
        $points = $rows;
        if (count($points) < self::MIN_POINTS) {
            return $this->unavailable($year);
        }

        $lowerIndex = null;
        for ($i = 0, $count = count($points) - 1; $i < $count; $i++) {
            if ($placementScore >= $points[$i]['score'] && $placementScore <= $points[$i + 1]['score']) {
                $lowerIndex = $i;
                break;
            }
        }

        if ($lowerIndex === null) {
            $edge = $placementScore < $points[0]['score'] ? 0 : count($points) - 1;
            $center = $points[$edge]['rank'];
            $neighbors = array_slice($points, max(0, $edge - self::LOCAL_WINDOW), self::LOCAL_WINDOW * 2 + 1);
            [$min, $max] = $this->rangeFromNeighbors($center, $neighbors, true);
            return $this->result($center, $min, $max, 'low', $year, true, count($points));
        }

        $left = $points[$lowerIndex];
        $right = $points[$lowerIndex + 1];
        $scoreGap = $right['score'] - $left['score'];
        $ratio = $scoreGap > 0 ? ($placementScore - $left['score']) / $scoreGap : 0.5;
        $center = (int) round($left['rank'] + (($right['rank'] - $left['rank']) * $ratio));
        $center = max(1, $center);
        $neighbors = array_slice($points, max(0, $lowerIndex - self::LOCAL_WINDOW), self::LOCAL_WINDOW * 2 + 2);
        [$min, $max] = $this->rangeFromNeighbors($center, $neighbors, false);
        $localDataQuality = count($neighbors) >= 12 && $scoreGap <= 2.0 ? 'high' : 'medium';

        return $this->result($center, $min, $max, $localDataQuality, $year, false, count($points));
    }

    private function rangeFromNeighbors(int $center, array $neighbors, bool $outsideRange): array
    {
        $ranks = array_column($neighbors, 'rank');
        sort($ranks, SORT_NUMERIC);
        $localSpread = $ranks === [] ? 1 : max($ranks) - min($ranks);
        $typicalGap = 1;
        if (count($ranks) > 1) {
            $gaps = [];
            for ($i = 1, $count = count($ranks); $i < $count; $i++) {
                $gaps[] = $ranks[$i] - $ranks[$i - 1];
            }
            sort($gaps, SORT_NUMERIC);
            $typicalGap = max(1, $gaps[(int) floor(count($gaps) / 2)]);
        }
        $uncertainty = max($typicalGap * 2, (int) ceil($localSpread / max(2, count($neighbors) - 1)));
        if ($outsideRange) {
            $uncertainty = max($uncertainty * 3, $localSpread);
        }
        return [max(1, $center - $uncertainty), $center + $uncertainty];
    }

    private function result(int $center, int $min, int $max, string $localDataQuality, int $year, bool $outside, int $pointCount): array
    {
        return [
            'center' => $center,
            'min' => min($min, $max),
            'max' => max($min, $max),
            'local_data_quality' => $localDataQuality,
            'year' => $year,
            'outside_data_range' => $outside,
            'point_count' => $pointCount,
        ];
    }

    private function unavailable(int $year): array
    {
        return [
            'center' => null,
            'min' => null,
            'max' => null,
            'local_data_quality' => 'unavailable',
            'year' => $year,
            'outside_data_range' => false,
            'point_count' => 0,
        ];
    }
}
