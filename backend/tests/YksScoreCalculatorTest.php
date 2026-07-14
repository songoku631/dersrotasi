<?php

declare(strict_types=1);

use DersRotasi\Services\YksScoreCalculator;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertYks(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function inputFor(string $scoreType, bool $placed = false): array
{
    return [
        'year' => 2025,
        'score_type' => $scoreType,
        'input_mode' => 'correct_wrong',
        'diploma_grade' => 81,
        'previously_placed' => $placed,
        'tests' => [
            'tyt_turkish' => ['correct' => 32, 'wrong' => 6],
            'tyt_social' => ['correct' => 0, 'wrong' => 0],
            'tyt_math' => ['correct' => 40, 'wrong' => 0],
            'tyt_science' => ['correct' => 0, 'wrong' => 0],
        ],
    ];
}

$calculator = new YksScoreCalculator(dirname(__DIR__) . '/config/yks');
$result = $calculator->calculate(inputFor('SAY'));
assertYks($result['nets']['tyt_turkish'] === 30.5, 'Yanlış / 4 net formülü hatalı.');
assertYks($result['nets']['tyt_social'] === 0.0, 'Sıfır net hatalı.');
assertYks($result['nets']['tyt_math'] === 40.0, 'Tam doğru neti hatalı.');
assertYks($result['scores']['obp'] === 405.0, 'Normal OBP hatalı.');
assertYks($result['scores']['obp_contribution'] === 48.6, 'Normal OBP katkısı hatalı.');
assertYks(is_float($result['scores']['raw_score']), 'Tahmini ham puan üretilmedi.');
assertYks($result['scores']['placement_score'] > $result['scores']['raw_score'], 'OBP yerleştirme puanına eklenmedi.');
assertYks(count($result['calibration']['historical_scores']) === 3, '2023-2025 tarihsel kalibrasyonu kullanılmadı.');

$broken = $calculator->calculate(inputFor('SAY', true));
assertYks($broken['scores']['obp_contribution'] === 24.3, 'Kırık OBP katkısı hatalı.');
assertYks($broken['obp_details']['previously_placed_reduction_applied'] === true, 'Kırık OBP durumu belirtilmedi.');

foreach (['SAY', 'EA', 'SÖZ', 'DİL', 'TYT'] as $scoreType) {
    $typed = $calculator->calculate(inputFor($scoreType));
    assertYks($typed['score_type'] === $scoreType, "{$scoreType} puan türü işlenemedi.");
    assertYks($typed['scores']['raw_score'] !== null, "{$scoreType} tahmini puanı üretilemedi.");
}

$calibrationInput = [
    'year' => 2025,
    'score_type' => 'SAY',
    'input_mode' => 'correct_wrong',
    'diploma_grade' => 81,
    'previously_placed' => false,
    'tests' => [
        'tyt_turkish' => ['correct' => 32, 'wrong' => 6],
        'tyt_social' => ['correct' => 15, 'wrong' => 4],
        'tyt_math' => ['correct' => 28, 'wrong' => 7],
        'tyt_science' => ['correct' => 16, 'wrong' => 3],
        'ayt_math' => ['correct' => 30, 'wrong' => 5],
        'ayt_physics' => ['correct' => 10, 'wrong' => 3],
        'ayt_chemistry' => ['correct' => 9, 'wrong' => 2],
        'ayt_biology' => ['correct' => 10, 'wrong' => 2],
    ],
];
$calibrated = $calculator->calculate($calibrationInput);
assertYks(abs($calibrated['scores']['raw_score'] - 392.497) < 0.002, 'MEB OGM referans kalibrasyonu bozuldu.');
assertYks(abs($calibrated['scores']['placement_score'] - 441.097) < 0.002, 'Yerleştirme puanı tahmini hatalı.');
assertYks($calibrated['scores']['raw_score_range']['min'] < $calibrated['scores']['raw_score_range']['max'], 'Tarihsel puan aralığı üretilmedi.');
assertYks(
    abs(($calibrated['scores']['raw_score'] - $calibrated['scores']['raw_score_range']['min'])
        - ($calibrated['scores']['raw_score_range']['max'] - $calibrated['scores']['raw_score'])) > 0.001,
    'Tahmin aralığı sabit simetrik yüzdeye dönüştü.'
);
assertYks($calibrated['confidence'] === 'unavailable', 'Backtest olmadan güven seviyesi doğrulanmış gösterildi.');
assertYks(str_contains($calibrated['disclaimer'], 'Bu hesaplama geçmiş YKS verilerine dayalı bir tahmindir.'), 'Tahmin uyarısı eksik.');

$direct = inputFor('TYT');
$direct['input_mode'] = 'net';
$direct['tests'] = ['tyt_turkish' => ['net' => 12.75]];
assertYks($calculator->calculate($direct)['nets']['tyt_turkish'] === 12.75, 'Ondalıklı doğrudan net işlenemedi.');

foreach ([
    ['tests' => ['tyt_turkish' => ['correct' => 40, 'wrong' => 1]], 'message' => 'Soru sayısını aşan giriş kabul edildi.'],
    ['tests' => ['tyt_turkish' => ['correct' => -1, 'wrong' => 0]], 'message' => 'Negatif giriş kabul edildi.'],
] as $case) {
    $invalid = inputFor('TYT');
    $invalid['tests'] = $case['tests'];
    try {
        $calculator->calculate($invalid);
        throw new RuntimeException($case['message']);
    } catch (RuntimeException $exception) {
        assertYks($exception->getCode() === 422, 'API doğrulama hatası 422 olmalı.');
    }
}

echo "YksScoreCalculator tests passed.\n";
