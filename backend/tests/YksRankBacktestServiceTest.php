<?php

declare(strict_types=1);

use DersRotasi\Services\YksRankBacktestService;
use DersRotasi\Services\YksBacktestConfidenceService;

require dirname(__DIR__) . '/vendor/autoload.php';

$policy = [
    'minimum_distinct_years' => 3,
    'minimum_samples_per_score_type' => 100,
    'confidence_policy' => [],
];
$report = (new YksRankBacktestService())->run([], $policy);
foreach (['SAY', 'EA', 'SÖZ', 'DİL', 'TYT'] as $scoreType) {
    if ($report['score_types'][$scoreType]['status'] !== 'insufficient_data'
        || $report['score_types'][$scoreType]['confidence'] !== 'unavailable') {
        throw new RuntimeException("{$scoreType} için eksik veri güvenli biçimde reddedilmedi.");
    }
}

$missingReport = (new YksBacktestConfidenceService(__DIR__ . '/does-not-exist.json'))->forScoreType('SAY');
if ($missingReport['confidence'] !== 'unavailable') {
    throw new RuntimeException('Backtest raporu olmadan güven seviyesi üretildi.');
}

echo "YksRankBacktestService tests passed.\n";
