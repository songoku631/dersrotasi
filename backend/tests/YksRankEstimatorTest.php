<?php

declare(strict_types=1);

use DersRotasi\Services\YksRankEstimator;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertRank(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$estimator = new YksRankEstimator();
$points = [
    ['base_score' => 200, 'base_rank' => 200000],
    ['base_score' => 250, 'base_rank' => 150000],
    ['base_score' => 300, 'base_rank' => 100000],
    ['base_score' => 400, 'base_rank' => 50000],
    ['base_score' => 450, 'base_rank' => 25000],
    ['base_score' => null, 'base_rank' => 1],
    ['base_score' => 500, 'base_rank' => null],
];

$estimate = $estimator->estimate(350, $points, 2025);
assertRank($estimate['center'] === 75000, 'Puan-sıralama interpolasyonu hatalı.');
assertRank($estimate['min'] < $estimate['center'] && $estimate['max'] > $estimate['center'], 'Tahmin aralığı merkez değeri kapsamıyor.');
assertRank($estimate['outside_data_range'] === false, 'Aralık içi puan yanlış işaretlendi.');

$uncertain = $estimator->estimate(350, $points, 2025, 20);
assertRank($uncertain['min'] <= $estimate['min'] && $uncertain['max'] >= $estimate['max'], 'Puan belirsizliği sıralama aralığına yansıtılmadı.');
assertRank($uncertain['score_uncertainty'] === 20.0, 'Puan belirsizliği raporlanmadı.');

$outside = $estimator->estimate(550, $points, 2025);
assertRank($outside['center'] === 25000, 'Veri aralığı dışındaki merkez en yakın noktadan alınmalı.');
assertRank($outside['local_data_quality'] === 'low' && $outside['outside_data_range'] === true, 'Veri aralığı dışı yerel veri kalitesi düşük olmalı.');

$unavailable = $estimator->estimate(300, [
    ['base_score' => null, 'base_rank' => null],
    ['base_score' => 300, 'base_rank' => null],
], 2025);
assertRank($unavailable['center'] === null, 'NULL başarı sıraları modelden çıkarılmalı.');

echo "YksRankEstimator tests passed.\n";
