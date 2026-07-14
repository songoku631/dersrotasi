<?php

declare(strict_types=1);

namespace DersRotasi\Services;

final class PreferenceEvaluationService
{
    public const BETTER_THRESHOLD = 0.15;
    public const LOWER_THRESHOLD = 0.20;

    public function evaluate(int $userRank, ?int $programBaseRank, int $year): array
    {
        if ($programBaseRank === null) {
            return [
                'label' => null,
                'label_text' => 'Yeterli veri yok',
                'explanation' => 'Değerlendirme için yeterli veri yok.',
                'user_rank' => $userRank,
                'program_base_rank' => null,
                'difference' => null,
                'percentage_difference' => null,
                'year' => $year,
            ];
        }

        $hardBoundary = (int) round($userRank * (1 - self::BETTER_THRESHOLD));
        $saferBoundary = (int) round($userRank * (1 + self::LOWER_THRESHOLD));
        $label = $programBaseRank < $hardBoundary
            ? 'zor'
            : ($programBaseRank <= $saferBoundary ? 'hedef' : 'daha_guvenli');
        $texts = [
            'zor' => 'Zor tercih', 'hedef' => 'Hedef tercih',
            'daha_guvenli' => 'Daha güvenli tercih',
        ];

        return [
            'label' => $label,
            'label_text' => $texts[$label],
            'explanation' => 'Bu sınıflandırma geçmiş yerleştirme sonuçlarına dayalı yaklaşık yardımcı bilgidir; kontenjanlar, sınav zorluğu ve aday davranışları her yıl değişebilir.',
            'user_rank' => $userRank,
            'program_base_rank' => $programBaseRank,
            'difference' => $programBaseRank - $userRank,
            'percentage_difference' => round((($programBaseRank - $userRank) / $userRank) * 100, 2),
            'year' => $year,
        ];
    }
}
