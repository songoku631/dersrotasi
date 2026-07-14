<?php

declare(strict_types=1);

namespace DersRotasi\Services;

use RuntimeException;

final class YksScoreCalculator
{
    private array $config;

    public function __construct(private readonly string $configDirectory)
    {
    }

    public function calculate(array $input): array
    {
        $year = $this->integer($input['year'] ?? null, 'Sınav yılı');
        $this->config = $this->loadConfig($year);
        $scoreType = $this->normalizeScoreType($input['score_type'] ?? null);
        $mode = (string) ($input['input_mode'] ?? 'correct_wrong');
        if (!in_array($mode, ['correct_wrong', 'net'], true)) {
            throw new RuntimeException('Net giriş yöntemi geçersiz.', 422);
        }

        $tests = $input['tests'] ?? [];
        if (!is_array($tests)) {
            throw new RuntimeException('Ders sonuçları geçersiz.', 422);
        }
        $unknown = array_diff(array_keys($tests), array_keys($this->config['questions']));
        if ($unknown !== []) {
            throw new RuntimeException('Bilinmeyen bir ders sonucu gönderildi.', 422);
        }

        $nets = [];
        foreach ($this->config['score_types'][$scoreType] as $testKey) {
            $nets[$testKey] = $this->netForTest(
                $testKey,
                is_array($tests[$testKey] ?? null) ? $tests[$testKey] : [],
                $mode
            );
        }

        $diplomaGrade = $this->number($input['diploma_grade'] ?? null, 'Diploma notu');
        if ($diplomaGrade < 0 || $diplomaGrade > 100) {
            throw new RuntimeException('Diploma notu 0 ile 100 arasında olmalıdır.', 422);
        }
        $previouslyPlaced = filter_var($input['previously_placed'] ?? false, FILTER_VALIDATE_BOOL);
        $obp = $this->calculateObp($diplomaGrade, $previouslyPlaced);
        $scoreEstimate = $this->estimateScore($scoreType, $nets);
        $placementScore = min(560.0, $scoreEstimate['center'] + $obp['contribution']);
        $placementRange = [
            'min' => min(560.0, $scoreEstimate['min'] + $obp['contribution']),
            'max' => min(560.0, $scoreEstimate['max'] + $obp['contribution']),
        ];

        return [
            'year' => $year,
            'score_type' => $scoreType,
            'input_mode' => $mode,
            'nets' => $nets,
            'scores' => [
                'raw_score' => round($scoreEstimate['center'], 3),
                'raw_score_range' => [
                    'min' => round($scoreEstimate['min'], 3),
                    'max' => round($scoreEstimate['max'], 3),
                ],
                'obp' => $obp['obp'],
                'obp_contribution' => $obp['contribution'],
                'placement_score' => round($placementScore, 3),
                'placement_score_range' => [
                    'min' => round($placementRange['min'], 3),
                    'max' => round($placementRange['max'], 3),
                ],
                'placement_score_uncertainty' => round(max(
                    abs($placementScore - $placementRange['min']),
                    abs($placementRange['max'] - $placementScore)
                ), 3),
            ],
            'obp_details' => $obp,
            'rank_estimate' => ['center' => null, 'min' => null, 'max' => null],
            'confidence' => 'unavailable',
            'confidence_explanation' => 'Güven seviyesi için en az üç farklı yılın gerçek puan ve başarı sırası verileriyle backtest gerekir. Mevcut veri bu doğrulamayı sağlamıyor.',
            'calculation_status' => 'estimated',
            'calibration' => [
                'reference_year' => 2025,
                'comparison_years' => [2023, 2024, 2025],
                'historical_scores' => $scoreEstimate['historical_scores'],
                'historical_score_spread' => round($scoreEstimate['spread'], 3),
            ],
            'score_explanation' => 'Tahmini puan; 2025 MEB OGM kalibrasyon referansı ve 2023-2025 ÖSYM test istatistikleri kullanılarak hesaplandı.',
            'disclaimer' => 'Bu hesaplama geçmiş YKS verilerine dayalı bir tahmindir. Sınavın zorluk seviyesi, aday ortalamaları ve standart sapma her yıl değiştiği için gerçek sonuç farklı olabilir. Kesin ÖSYM sonucu değildir.',
        ];
    }

    private function estimateScore(string $scoreType, array $nets): array
    {
        $calibration = $this->config['score_calibration'];
        $coefficients = $calibration['coefficients'][$scoreType];
        $intercept = (float) $calibration['intercepts'][$scoreType];
        $center = $intercept;
        foreach ($coefficients as $test => $coefficient) {
            $center += (float) $coefficient * (float) ($nets[$test] ?? 0.0);
        }
        $center = $this->clamp($center, 100.0, 500.0);

        $referenceStats = $this->config['test_statistics'];
        $historicalStats = $this->config['historical_test_statistics'];
        $historicalStats[2025] = $referenceStats;
        $referenceMeanScore = $intercept;
        foreach ($coefficients as $test => $coefficient) {
            $referenceMeanScore += (float) $coefficient * (float) $referenceStats[$test]['mean'];
        }

        $historicalScores = [];
        foreach ($historicalStats as $year => $statistics) {
            $scenario = $referenceMeanScore;
            foreach ($coefficients as $test => $coefficient) {
                $referenceDeviation = (float) $referenceStats[$test]['standard_deviation'];
                $yearDeviation = (float) $statistics[$test]['standard_deviation'];
                $yearMean = (float) $statistics[$test]['mean'];
                $scenario += (float) $coefficient
                    * ($referenceDeviation / $yearDeviation)
                    * ((float) ($nets[$test] ?? 0.0) - $yearMean);
            }
            $historicalScores[(int) $year] = round($this->clamp($scenario, 100.0, 500.0), 3);
        }
        $values = array_values($historicalScores);
        $minimum = min($values);
        $maximum = max($values);
        $spread = $maximum - $minimum;

        return [
            'center' => $center,
            'min' => $minimum,
            'max' => $maximum,
            'spread' => $spread,
            'historical_scores' => $historicalScores,
        ];
    }

    private function netForTest(string $key, array $value, string $mode): float
    {
        $questionCount = (int) $this->config['questions'][$key];
        if ($mode === 'net') {
            $net = $this->optionalNumber($value['net'] ?? 0, 'Net');
            if ($net < 0 || $net > $questionCount) {
                throw new RuntimeException("{$key} neti 0 ile {$questionCount} arasında olmalıdır.", 422);
            }
            return round($net, 2);
        }

        $correct = $this->optionalInteger($value['correct'] ?? 0, 'Doğru sayısı');
        $wrong = $this->optionalInteger($value['wrong'] ?? 0, 'Yanlış sayısı');
        if ($correct < 0 || $wrong < 0) {
            throw new RuntimeException('Doğru ve yanlış sayıları negatif olamaz.', 422);
        }
        if ($correct + $wrong > $questionCount) {
            throw new RuntimeException("{$key} için doğru ve yanlış toplamı {$questionCount} soru sayısını aşamaz.", 422);
        }
        return round($correct - ($wrong / 4), 2);
    }

    private function calculateObp(float $diplomaGrade, bool $previouslyPlaced): array
    {
        $rule = $this->config['obp'];
        $effectiveGrade = max($diplomaGrade, (float) $rule['minimum_effective_diploma_grade']);
        $obp = $effectiveGrade * (float) $rule['multiplier'];
        $coefficient = (float) ($previouslyPlaced
            ? $rule['previously_placed_coefficient']
            : $rule['placement_coefficient']);

        return [
            'diploma_grade' => round($diplomaGrade, 2),
            'effective_diploma_grade' => round($effectiveGrade, 2),
            'obp' => round($obp, 2),
            'contribution' => round($obp * $coefficient, 2),
            'coefficient' => $coefficient,
            'previously_placed_reduction_applied' => $previouslyPlaced,
        ];
    }

    private function loadConfig(int $year): array
    {
        $path = rtrim($this->configDirectory, '/\\') . DIRECTORY_SEPARATOR . $year . '.php';
        if (!is_file($path)) {
            throw new RuntimeException('Bu sınav yılı henüz desteklenmiyor.', 422);
        }
        $config = require $path;
        if (!is_array($config)) {
            throw new RuntimeException('Sınav yapılandırması okunamadı.', 500);
        }
        return $config;
    }

    private function normalizeScoreType(mixed $value): string
    {
        $normalized = mb_strtoupper(trim((string) $value), 'UTF-8');
        $aliases = ['SOZ' => 'SÖZ', 'DIL' => 'DİL'];
        $normalized = $aliases[$normalized] ?? $normalized;
        if (!isset($this->config['score_types'][$normalized])) {
            throw new RuntimeException('Puan türü geçersiz.', 422);
        }
        return $normalized;
    }

    private function integer(mixed $value, string $field): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new RuntimeException("{$field} tam sayı olmalıdır.", 422);
        }
        return (int) $value;
    }

    private function optionalInteger(mixed $value, string $field): int
    {
        if ($value === '' || $value === null) {
            return 0;
        }
        return $this->integer($value, $field);
    }

    private function number(mixed $value, string $field): float
    {
        if ($value === '' || $value === null || !is_numeric($value)) {
            throw new RuntimeException("{$field} sayısal olmalıdır.", 422);
        }
        return (float) $value;
    }

    private function optionalNumber(mixed $value, string $field): float
    {
        if ($value === '' || $value === null) {
            return 0.0;
        }
        return $this->number($value, $field);
    }

    private function clamp(float $value, float $minimum, float $maximum): float
    {
        return max($minimum, min($maximum, $value));
    }
}
