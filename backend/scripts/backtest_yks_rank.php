<?php

declare(strict_types=1);

use DersRotasi\Config\Env;
use DersRotasi\Database\Connection;
use DersRotasi\Repositories\YksBacktestRepository;
use DersRotasi\Services\YksRankBacktestService;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Bu araç yalnızca komut satırından çalıştırılabilir.\n");
    exit(1);
}

$root = dirname(__DIR__);
if (file_exists($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$config = require $root . '/config/yks/2025.php';
$output = $root . '/storage/reports/yks_rank_backtest_2025.json';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--output=')) {
        $candidate = substr($argument, strlen('--output='));
        if ($candidate === '') {
            throw new RuntimeException('Rapor dosyası yolu boş olamaz.');
        }
        $output = str_starts_with($candidate, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $candidate) === 1
            ? $candidate
            : $root . DIRECTORY_SEPARATOR . $candidate;
    } else {
        throw new RuntimeException('Bilinmeyen seçenek. Kullanım: php scripts/backtest_yks_rank.php [--output=dosya.json]');
    }
}

$env = new Env($_ENV);
$rows = (new YksBacktestRepository(Connection::make($env)))->usableRankRows();
$report = (new YksRankBacktestService())->run($rows, $config['backtest']);
$directory = dirname($output);
if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
    throw new RuntimeException('Rapor dizini oluşturulamadı.');
}
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false || file_put_contents($output, $json . PHP_EOL, LOCK_EX) === false) {
    throw new RuntimeException('Backtest raporu yazılamadı.');
}

echo "YKS başarı sırası backtest raporu\n";
echo 'Kullanılabilir yıllar: ' . ($report['data_years'] === [] ? 'yok' : implode(', ', $report['data_years'])) . PHP_EOL;
foreach ($report['score_types'] as $scoreType => $result) {
    echo sprintf(
        "%s | durum: %s | örnek: %d | ortanca mutlak hata: %s | ortalama yüzde hata: %s | kapsama: %s | güven: %s\n",
        $scoreType,
        $result['status'],
        $result['sample_count'],
        $result['median_absolute_rank_error'] ?? 'ölçülemedi',
        $result['mean_percentage_error'] === null ? 'ölçülemedi' : $result['mean_percentage_error'] . '%',
        $result['interval_coverage_rate'] === null ? 'ölçülemedi' : $result['interval_coverage_rate'] . '%',
        $result['confidence'] === 'unavailable' ? 'doğrulanmadı' : $result['confidence']
    );
    echo '  ' . $result['message'] . PHP_EOL;
}
echo 'Rapor: ' . $output . PHP_EOL;
