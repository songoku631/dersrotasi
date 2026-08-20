<?php

declare(strict_types=1);

namespace DersRotasi\Services;

use DersRotasi\Repositories\PreferenceRepository;
use DersRotasi\Repositories\ProfileRepository;
use DersRotasi\Repositories\UniversityRepository;
use RuntimeException;

final class PremiumAnalysisService
{
    private const YEARS = [2026, 2025, 2024, 2023];

    public function __construct(
        private readonly PreferenceRepository $preferences,
        private readonly ProfileRepository $profiles,
        private readonly UniversityRepository $universities
    ) {
    }

    public function analyzePreferences(string $firebaseUid, mixed $requestedRank): array
    {
        $userRank = $this->userRank($firebaseUid, $requestedRank, true);
        $preferences = $this->preferences->all($firebaseUid);
        if ($preferences === []) {
            throw new RuntimeException('Analiz edilecek tercih bulunamadı.', 422);
        }
        if (count($preferences) > 50) {
            throw new RuntimeException('Tek analizde en fazla 50 tercih değerlendirilebilir.', 422);
        }

        $items = [];
        foreach ($preferences as $preference) {
            $program = $this->universities->findWithHistory((int) $preference['id'], $firebaseUid);
            if ($program === null) {
                continue;
            }
            $program['position'] = (int) $preference['position'];
            $program['note'] = (string) ($preference['note'] ?? '');
            $items[] = $this->analyzeProgram($program, $userRank);
        }
        if ($items === []) {
            throw new RuntimeException('Tercih programlarının güncel verileri bulunamadı.', 422);
        }

        $counts = ['zor' => 0, 'hedef' => 0, 'daha_guvenli' => 0, 'veri_yetersiz' => 0];
        foreach ($items as $item) {
            $counts[$item['analysis']['label'] ?? 'veri_yetersiz']++;
        }
        $warnings = $this->preferenceWarnings($items, $counts);

        return [
            'user_rank' => $userRank,
            'items' => $items,
            'counts' => $counts,
            'warnings' => $warnings,
            'disclaimer' => 'Analiz 2023–2026 gerçek yerleştirme verilerine dayanır; kesin yerleşme garantisi vermez. Eksik yıllar tahmin edilmez.',
        ];
    }

    public function comparePrograms(
        string $firebaseUid,
        array $programIds,
        mixed $requestedRank
    ): array {
        $ids = array_values(array_unique(array_map('intval', $programIds)));
        if (count($ids) !== 2 || min($ids) < 1) {
            throw new RuntimeException('Karşılaştırma için iki farklı program seçmelisin.', 422);
        }
        $userRank = $this->userRank($firebaseUid, $requestedRank, false);
        $programs = [];
        foreach ($ids as $id) {
            $program = $this->universities->findWithHistory($id, $firebaseUid);
            if ($program === null) {
                throw new RuntimeException('Karşılaştırılacak program bulunamadı.', 404);
            }
            $programs[] = $this->analyzeProgram($program, $userRank);
        }

        $leftStability = $programs[0]['analysis']['rank_range_percentage'];
        $rightStability = $programs[1]['analysis']['rank_range_percentage'];
        $moreStable = null;
        if ($leftStability !== null && $rightStability !== null && $leftStability !== $rightStability) {
            $moreStable = $leftStability < $rightStability ? $programs[0]['program_code'] : $programs[1]['program_code'];
        }

        return [
            'user_rank' => $userRank,
            'programs' => $programs,
            'comparison' => [
                'more_stable_program_code' => $moreStable,
                'stability_note' => $moreStable === null
                    ? 'Kararlılık karşılaştırması için yeterli veya ayırt edici veri yok.'
                    : 'Daha düşük yıllık sıra aralığına sahip program daha stabil görünüyor.',
            ],
            'disclaimer' => 'Karşılaştırma yalnızca veritabanındaki 2023–2026 sonuçlarına dayanır; eksik değerler tahmin edilmez.',
        ];
    }

    private function analyzeProgram(array $program, ?int $userRank): array
    {
        $rankings = $this->yearMap($program['rankings'] ?? []);
        $scores = $this->yearMap($program['scores'] ?? [], false);
        $quotas = $this->yearMap($program['quotas'] ?? []);
        $availableRanks = array_values(array_filter($rankings, static fn (mixed $rank): bool => is_int($rank) && $rank > 0));
        sort($availableRanks);
        $representativeRank = count($availableRanks) >= 2 ? $this->median($availableRanks) : null;
        $evaluation = $userRank !== null && $representativeRank !== null
            ? (new PreferenceEvaluationService())->evaluate($userRank, $representativeRank, 2026)
            : null;
        $range = count($availableRanks) >= 2 ? max($availableRanks) - min($availableRanks) : null;
        $rangePercentage = $range !== null && $representativeRank > 0
            ? round(($range / $representativeRank) * 100, 1)
            : null;
        $oldestRank = $this->oldestValue($rankings);
        $latestRank = $this->latestValue($rankings);
        $rankChange = $oldestRank !== null && $latestRank !== null ? $latestRank - $oldestRank : null;
        $oldestQuota = $this->oldestValue($quotas);
        $latestQuota = $this->latestValue($quotas);
        $quotaChange = $oldestQuota !== null && $latestQuota !== null ? $latestQuota - $oldestQuota : null;

        $label = $evaluation['label'] ?? null;
        $labels = [
            'zor' => '🔴 İddialı',
            'hedef' => '🟡 Hedef',
            'daha_guvenli' => '🟢 Daha Güvenli',
        ];
        $comment = $representativeRank === null
            ? 'En az iki yılın başarı sırası bulunmadığı için risk sınıfı üretilmedi.'
            : 'Sınıflandırma ' . count($availableRanks) . ' yılın medyan başarı sırası üzerinden hesaplandı.';

        return [
            ...$program,
            'rankings' => $rankings,
            'scores' => $scores,
            'quotas' => $quotas,
            'analysis' => [
                'label' => $label,
                'label_text' => $labels[$label] ?? 'Veri yetersiz',
                'representative_rank' => $representativeRank,
                'years_used' => count($availableRanks),
                'rank_range' => $range,
                'rank_range_percentage' => $rangePercentage,
                'rank_change' => $rankChange,
                'quota_change' => $quotaChange,
                'user_rank' => $userRank,
                'comment' => $comment,
            ],
        ];
    }

    private function preferenceWarnings(array $items, array $counts): array
    {
        $warnings = [];
        if ($counts['zor'] > count($items) / 2) {
            $warnings[] = 'Listenin yarısından fazlası iddialı tercihlerden oluşuyor.';
        }
        if ($counts['daha_guvenli'] === 0) {
            $warnings[] = 'Listede daha güvenli sınıfında tercih bulunmuyor.';
        }
        $representatives = array_values(array_filter(array_map(
            static fn (array $item): ?int => $item['analysis']['representative_rank'],
            $items
        ), static fn (?int $rank): bool => $rank !== null));
        for ($index = 1; $index < count($representatives); $index++) {
            if ($representatives[$index] < $representatives[$index - 1] * 0.85) {
                $warnings[] = 'Tercih sıralamasında daha iddialı bir program daha güvenli bir programın altında kalıyor.';
                break;
            }
        }
        if (array_filter($items, static fn (array $item): bool => ($item['analysis']['rank_range_percentage'] ?? 0) >= 25)) {
            $warnings[] = 'Bazı programlarda yıllar arasında ciddi başarı sırası değişimi var.';
        }
        if (array_filter($items, static function (array $item): bool {
            $change = $item['analysis']['quota_change'];
            $current = $item['quotas']['2026'];
            return $change !== null && $current !== null && abs($change) >= max(5, $current * 0.20);
        })) {
            $warnings[] = 'Bazı programların kontenjan değişimleri dikkat çekiyor.';
        }
        if ($warnings === []) {
            $warnings[] = 'Listede belirgin bir dağılım veya trend riski tespit edilmedi.';
        }

        return array_values(array_unique($warnings));
    }

    private function userRank(string $firebaseUid, mixed $requestedRank, bool $required): ?int
    {
        $value = $requestedRank;
        if ($value === null || $value === '') {
            $profile = $this->profiles->findByUid($firebaseUid);
            $value = $profile['target_rank'] ?? null;
        }
        if ($value === null || $value === '') {
            if ($required) {
                throw new RuntimeException('Analiz için başarı sıranı girmelisin.', 422);
            }
            return null;
        }
        $rank = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000000]]);
        if ($rank === false) {
            throw new RuntimeException('Başarı sırası geçerli bir pozitif tam sayı olmalı.', 422);
        }

        return (int) $rank;
    }

    private function yearMap(array $values, bool $integers = true): array
    {
        $result = [];
        foreach (self::YEARS as $year) {
            $value = $values[(string) $year] ?? $values[$year] ?? null;
            $result[(string) $year] = $value === null || $value === ''
                ? null
                : ($integers ? (int) $value : (float) $value);
        }

        return $result;
    }

    private function median(array $values): int
    {
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? (int) $values[$middle]
            : (int) round(($values[$middle - 1] + $values[$middle]) / 2);
    }

    private function latestValue(array $values): int|float|null
    {
        foreach (self::YEARS as $year) {
            if ($values[(string) $year] !== null) {
                return $values[(string) $year];
            }
        }

        return null;
    }

    private function oldestValue(array $values): int|float|null
    {
        foreach (array_reverse(self::YEARS) as $year) {
            if ($values[(string) $year] !== null) {
                return $values[(string) $year];
            }
        }

        return null;
    }
}
