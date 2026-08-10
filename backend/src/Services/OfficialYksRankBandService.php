<?php

declare(strict_types=1);

namespace DersRotasi\Services;

use RuntimeException;

final class OfficialYksRankBandService
{
    private const SCORE_TYPE_ALIASES = [
        'SAY' => 'SAY',
        'EA' => 'EA',
        'SÖZ' => 'SÖZ',
        'SOZ' => 'SÖZ',
        'DİL' => 'DİL',
        'DIL' => 'DİL',
    ];

    private const SCORE_KIND_LABELS = [
        'exam' => 'Sınav puanı',
        'placement' => 'Yerleştirme puanı',
    ];

    private array $config;

    public function __construct(string $configPath)
    {
        if (!is_file($configPath)) {
            throw new RuntimeException('Resmî ÖSYM dağılım verisi bulunamadı.', 500);
        }

        $config = require $configPath;
        if (!is_array($config) || !isset($config['score_types'], $config['years'])) {
            throw new RuntimeException('Resmî ÖSYM dağılım verisi geçersiz.', 500);
        }
        $this->config = $config;
    }

    public function compare(array $input): array
    {
        $scoreType = $this->scoreType($input['score_type'] ?? null);
        $scoreKind = $this->scoreKind($input['score_kind'] ?? null);
        $score = $this->score($input['score'] ?? null, $scoreKind);
        $years = [];

        foreach ($this->config['years'] as $year => $yearConfig) {
            $years[] = $this->bandForYear((int) $year, $yearConfig, $scoreType, $scoreKind, $score);
        }

        usort($years, static fn (array $left, array $right): int => $right['year'] <=> $left['year']);

        return [
            'score_type' => $scoreType,
            'score_kind' => $scoreKind,
            'score_kind_label' => self::SCORE_KIND_LABELS[$scoreKind],
            'score_label' => $scoreKind === 'placement' ? 'Y-' . $scoreType : $scoreType,
            'score' => $score,
            'years' => $years,
            'method' => 'official_cumulative_boundaries',
            'interpolation_applied' => false,
            'disclaimer' => 'ÖSYM, her tekil puan için kamuya açık kesin başarı sırası yayımlamadığından bu araç resmî kümülatif dağılımların belirlediği sıra aralığını gösterir. Ara değer tahmini yapılmaz.',
        ];
    }

    private function bandForYear(
        int $year,
        array $yearConfig,
        string $scoreType,
        string $scoreKind,
        float $score
    ): array {
        $dataset = $yearConfig[$scoreKind] ?? null;
        $source = $yearConfig['source'] ?? [];
        if (!is_array($dataset) || !isset($dataset['rows']) || !is_array($dataset['rows'])) {
            throw new RuntimeException("{$year} resmî dağılım verisi eksik.", 500);
        }

        $typeIndex = array_search($scoreType, $this->config['score_types'], true);
        if ($typeIndex === false) {
            throw new RuntimeException('Puan türü dağılım verisinde bulunamadı.', 500);
        }

        $rows = [];
        foreach ($dataset['rows'] as $row) {
            $threshold = isset($row[0]) ? (float) $row[0] : null;
            $count = isset($row[$typeIndex + 1]) ? (int) $row[$typeIndex + 1] : null;
            if ($threshold === null || $count === null || $count < 0) {
                throw new RuntimeException("{$year} resmî dağılım satırı geçersiz.", 500);
            }
            $rows[] = ['threshold' => $threshold, 'candidate_count' => $count];
        }

        usort($rows, static fn (array $left, array $right): int => $right['threshold'] <=> $left['threshold']);
        $provenance = [
            'publisher' => (string) ($source['publisher'] ?? 'ÖSYM'),
            'document' => (string) ($source['document'] ?? ''),
            'table_name' => (string) ($dataset['table_name'] ?? ''),
            'url' => (string) ($source['url'] ?? ''),
            'published_at' => (string) ($source['published_at'] ?? ''),
        ];

        $lowest = $rows[array_key_last($rows)];
        if ($score < $lowest['threshold'] && !$this->sameScore($score, $lowest['threshold'])) {
            return [
                'year' => $year,
                'status' => 'insufficient_resolution',
                'rank_min' => null,
                'rank_max' => null,
                'minimum_published_threshold' => $lowest['threshold'],
                'candidate_count_at_minimum' => $lowest['candidate_count'],
                'source' => $provenance,
            ];
        }

        $betterRow = null;
        $worseRow = null;
        $exactThreshold = false;

        foreach ($rows as $index => $row) {
            if ($this->sameScore($score, $row['threshold'])) {
                $betterRow = $index > 0 ? $rows[$index - 1] : null;
                $worseRow = $row;
                $exactThreshold = true;
                break;
            }
            if ($index === 0 && $score > $row['threshold']) {
                $worseRow = $row;
                break;
            }
            if ($index > 0 && $score < $rows[$index - 1]['threshold'] && $score > $row['threshold']) {
                $betterRow = $rows[$index - 1];
                $worseRow = $row;
                break;
            }
        }

        if ($worseRow === null) {
            throw new RuntimeException("{$year} puanı için resmî sınırlar belirlenemedi.", 500);
        }

        $rankMin = ($betterRow['candidate_count'] ?? 0) + 1;
        $rankMax = $worseRow['candidate_count'];
        $status = $rankMin <= $rankMax ? 'band' : 'no_candidates_in_band';

        return [
            'year' => $year,
            'status' => $status,
            'rank_min' => $status === 'band' ? $rankMin : null,
            'rank_max' => $status === 'band' ? $rankMax : null,
            'higher_score_threshold' => $betterRow['threshold'] ?? null,
            'higher_threshold_candidate_count' => $betterRow['candidate_count'] ?? 0,
            'lower_score_threshold' => $worseRow['threshold'],
            'lower_threshold_candidate_count' => $worseRow['candidate_count'],
            'exact_threshold' => $exactThreshold,
            'above_highest_threshold' => $betterRow === null && !$exactThreshold,
            'source' => $provenance,
        ];
    }

    private function scoreType(mixed $value): string
    {
        $key = strtoupper(strtr(trim((string) $value), [
            'ö' => 'Ö',
            'ı' => 'I',
            'i' => 'İ',
        ]));
        if (!isset(self::SCORE_TYPE_ALIASES[$key])) {
            throw new RuntimeException('Puan türü SAY, EA, SÖZ veya DİL olmalıdır.', 422);
        }
        return self::SCORE_TYPE_ALIASES[$key];
    }

    private function scoreKind(mixed $value): string
    {
        $scoreKind = strtolower(trim((string) $value));
        if (!isset(self::SCORE_KIND_LABELS[$scoreKind])) {
            throw new RuntimeException('Puan tipi sınav puanı veya yerleştirme puanı olmalıdır.', 422);
        }
        return $scoreKind;
    }

    private function score(mixed $value, string $scoreKind): float
    {
        if (!is_int($value) && !is_float($value) && (!is_string($value) || trim($value) === '' || !is_numeric($value))) {
            throw new RuntimeException('Geçerli bir YKS puanı girilmelidir.', 422);
        }
        $score = (float) $value;
        $maximum = $scoreKind === 'exam' ? 500.0 : 560.0;
        if (!is_finite($score) || $score < 100.0 || $score > $maximum) {
            throw new RuntimeException("YKS puanı 100 ile {$maximum} arasında olmalıdır.", 422);
        }
        return round($score, 5);
    }

    private function sameScore(float $left, float $right): bool
    {
        return abs($left - $right) < 0.00001;
    }
}
