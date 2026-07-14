<?php

declare(strict_types=1);

namespace DersRotasi\Services;

final class YksBacktestConfidenceService
{
    public function __construct(private readonly string $reportPath)
    {
    }

    public function forScoreType(string $scoreType): array
    {
        $unavailable = [
            'confidence' => 'unavailable',
            'explanation' => 'Güven seviyesi için en az üç farklı yılın gerçek puan ve başarı sırası verileriyle backtest gerekir. Mevcut veri bu doğrulamayı sağlamıyor.',
        ];
        if (!is_file($this->reportPath) || !is_readable($this->reportPath)) {
            return $unavailable;
        }
        $contents = file_get_contents($this->reportPath);
        $report = $contents === false ? null : json_decode($contents, true);
        $result = is_array($report) ? ($report['score_types'][$scoreType] ?? null) : null;
        if (!is_array($result) || ($result['status'] ?? '') !== 'validated') {
            return $unavailable;
        }
        $confidence = (string) ($result['confidence'] ?? 'unavailable');
        if (!in_array($confidence, ['high', 'medium', 'low'], true)) {
            return $unavailable;
        }

        return [
            'confidence' => $confidence,
            'explanation' => sprintf(
                'Güven seviyesi %d gerçek backtest örneğinde ölçülen %s%% ortalama hata ve %s%% aralık kapsamasına dayanır.',
                (int) ($result['sample_count'] ?? 0),
                (string) ($result['mean_percentage_error'] ?? '—'),
                (string) ($result['interval_coverage_rate'] ?? '—')
            ),
        ];
    }
}
