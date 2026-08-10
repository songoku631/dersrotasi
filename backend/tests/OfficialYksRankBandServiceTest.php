<?php

declare(strict_types=1);

use DersRotasi\Services\OfficialYksRankBandService;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertOfficialBand(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function officialBandYear(array $result, int $year): array
{
    foreach ($result['years'] as $item) {
        if ($item['year'] === $year) {
            return $item;
        }
    }
    throw new RuntimeException("{$year} sonucu bulunamadı.");
}

$service = new OfficialYksRankBandService(
    dirname(__DIR__) . '/config/yks/official_rank_distributions.php'
);

$between = $service->compare([
    'score_type' => 'SAY',
    'score_kind' => 'placement',
    'score' => 443,
]);
$between2025 = officialBandYear($between, 2025);
assertOfficialBand($between2025['status'] === 'band', 'İki eşik arasındaki puan band üretmeli.');
assertOfficialBand($between2025['rank_min'] === 46143, '2025 Y-SAY iyi sıra sınırı hatalı.');
assertOfficialBand($between2025['rank_max'] === 65449, '2025 Y-SAY kötü sıra sınırı hatalı.');
assertOfficialBand($between2025['higher_score_threshold'] === 450.0, 'Yüksek puan eşiği hatalı.');
assertOfficialBand($between2025['lower_score_threshold'] === 430.0, 'Düşük puan eşiği hatalı.');
assertOfficialBand($between['interpolation_applied'] === false, 'Interpolasyon kesinlikle uygulanmamalı.');
assertOfficialBand(!array_key_exists('center', $between2025), 'Sonuç merkez tahmin içermemeli.');

$exact = $service->compare([
    'score_type' => 'SAY',
    'score_kind' => 'placement',
    'score' => 450,
]);
$exact2025 = officialBandYear($exact, 2025);
assertOfficialBand($exact2025['exact_threshold'] === true, 'Tam eşik puanı işaretlenmeli.');
assertOfficialBand($exact2025['rank_min'] === 29411, 'Tam eşikte iyi sınır bir üst kümülatif sayıdan gelmeli.');
assertOfficialBand($exact2025['rank_max'] === 46142, 'Tam eşikte eşik kümülatif sayısı kullanılmalı.');

$above = $service->compare([
    'score_type' => 'SAY',
    'score_kind' => 'placement',
    'score' => 555,
]);
$above2025 = officialBandYear($above, 2025);
assertOfficialBand($above2025['above_highest_threshold'] === true, 'En yüksek eşik üstü işaretlenmeli.');
assertOfficialBand($above2025['rank_min'] === 1 && $above2025['rank_max'] === 57, 'En yüksek eşik üstü resmî sınırları hatalı.');

$below = $service->compare([
    'score_type' => 'EA',
    'score_kind' => 'placement',
    'score' => 110,
]);
foreach ($below['years'] as $year) {
    assertOfficialBand($year['status'] === 'insufficient_resolution', 'En düşük yayımlanmış eşiğin altı band üretmemeli.');
    assertOfficialBand($year['rank_min'] === null && $year['rank_max'] === null, 'Çözünürlük dışı sonuç sıra uydurmamalı.');
}

$examMaximum = $service->compare([
    'score_type' => 'SAY',
    'score_kind' => 'exam',
    'score' => 500,
]);
$exam2023 = officialBandYear($examMaximum, 2023);
assertOfficialBand($exam2023['rank_min'] === 1 && $exam2023['rank_max'] === 2, '2023 SAY sınav puanı maksimum eşiği hatalı.');

foreach (['placement' => 'Yerleştirme', 'exam' => 'Sınav'] as $scoreKind => $tableLabel) {
    foreach (['SAY', 'EA', 'SÖZ', 'DİL'] as $scoreType) {
        $coverage = $service->compare([
            'score_type' => $scoreType,
            'score_kind' => $scoreKind,
            'score' => 443,
        ]);
        assertOfficialBand(count($coverage['years']) === 3, "{$scoreType} için üç yıl dönmeli.");
        foreach ($coverage['years'] as $year) {
            assertOfficialBand($year['status'] === 'band', "{$scoreKind} {$scoreType} {$year['year']} bandı eksik.");
            assertOfficialBand($year['source']['publisher'] === 'ÖSYM', 'Kaynak provenance bilgisi eksik.');
            assertOfficialBand(str_contains($year['source']['table_name'], $tableLabel), 'Yanlış resmî tablo kullanıldı.');
        }
    }
}

$invalidRejected = false;
try {
    $service->compare(['score_type' => 'SAY', 'score_kind' => 'placement', 'score' => 561]);
} catch (RuntimeException $exception) {
    $invalidRejected = $exception->getCode() === 422;
}
assertOfficialBand($invalidRejected, 'Geçersiz YKS puanı reddedilmeli.');

echo "OfficialYksRankBandService tests passed.\n";
