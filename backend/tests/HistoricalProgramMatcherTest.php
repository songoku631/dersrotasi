<?php

declare(strict_types=1);

use DersRotasi\Historical\HistoricalProgramMatcher;

require dirname(__DIR__) . '/vendor/autoload.php';

function historicalMatcherCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, mixed> */
function historicalMatcherRow(string $code, array $overrides = []): array
{
    return [
        'program_code' => $code,
        'university_name' => 'ÖRNEK ÜNİVERSİTESİ (İSTANBUL)',
        'faculty_name' => 'İktisadi ve İdari Bilimler Fakültesi',
        'department_name' => 'İşletme ve Yönetim',
        'city' => 'İstanbul',
        'education_language' => 'Türkçe',
        'scholarship_type' => 'ucretsiz',
        'education_type' => 'orgun',
        'score_type' => 'ea',
        'duration_years' => 4,
        'base_score' => '350.00000',
        'base_rank' => 100000,
        'quota' => 40,
        ...$overrides,
    ];
}

$matcher = new HistoricalProgramMatcher();
$current = historicalMatcherRow('100000001');
$historical = historicalMatcherRow('100000099', [
    'university_name' => 'ornek universitesi',
    'faculty_name' => 'İKTİSADİ   VE İDARİ BİLİMLER FAKÜLTESİ',
    'department_name' => 'Isletme & Yonetim',
    'base_score' => '330.00000',
    'quota' => 30,
]);
$result = $matcher->analyze([$current], [$historical], 2023);
historicalMatcherCheck(count($result['matches']) === 1, 'Tek ve tam uyumlu eski kod eşleşmedi.');
historicalMatcherCheck(
    $result['matches'][0]['historical_program_code'] === '100000099',
    'Yanlış historical code seçildi.',
);

$differences = [
    'faculty' => ['faculty_name' => 'Başka Fakülte'],
    'scholarship' => ['scholarship_type' => 'burslu'],
    'language' => ['education_language' => 'İngilizce'],
    'education_type' => ['education_type' => 'ikinci_ogretim'],
    'score_type' => ['score_type' => 'say'],
    'duration' => ['duration_years' => 2],
    'city' => ['city' => 'Ankara'],
];
foreach ($differences as $label => $overrides) {
    $different = $matcher->analyze([$current], [historicalMatcherRow('100000099', $overrides)], 2023);
    historicalMatcherCheck(
        $different['matches'] === [] && count($different['unmatched']) === 1,
        "Anlamlı {$label} farkı eşleşmeyi engellemedi.",
    );
}

$ambiguous = $matcher->analyze([$current], [
    historicalMatcherRow('100000098'),
    historicalMatcherRow('100000099'),
], 2023);
historicalMatcherCheck(
    $ambiguous['matches'] === [] && count($ambiguous['ambiguous']) === 1,
    'Birden fazla historical aday otomatik eşleştirildi.',
);

$sharedHistorical = $matcher->analyze([
    $current,
    historicalMatcherRow('100000002'),
], [historicalMatcherRow('100000099')], 2023);
historicalMatcherCheck(
    $sharedHistorical['matches'] === [] && count($sharedHistorical['ambiguous']) === 2,
    'Aynı historical aday birden fazla current programa bağlandı.',
);

$scoreRejected = $matcher->analyze(
    [$current],
    [historicalMatcherRow('100000099', ['base_score' => '200.00000'])],
    2023,
);
historicalMatcherCheck(
    $scoreRejected['matches'] === []
        && $scoreRejected['unmatched'][0]['reason'] === 'consistency_rejected',
    'Aşırı taban puan farkı güvenli eşleşme olarak kabul edildi.',
);

$missingDuration = $matcher->analyze(
    [historicalMatcherRow('100000001', ['duration_years' => null])],
    [historicalMatcherRow('100000099')],
    2023,
);
historicalMatcherCheck(
    $missingDuration['matches'] === []
        && count($missingDuration['manual_review']) === 1
        && $missingDuration['manual_review'][0]['reason'] === 'current_duration_missing_not_automatic',
    'Eksik eğitim süresi olan yakın aday otomatik eşleşti veya inceleme raporuna alınmadı.',
);

echo "HistoricalProgramMatcherTest: OK\n";
