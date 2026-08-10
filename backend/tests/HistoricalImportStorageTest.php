<?php

declare(strict_types=1);

use DersRotasi\Yokatlas\HistoricalImportStorage;

require dirname(__DIR__) . '/vendor/autoload.php';

function historicalStorageCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . '/dersrotasi_historical_' . bin2hex(random_bytes(6));
$storage = new HistoricalImportStorage($root);
$page = [
    'content' => [['kilavuzKodu' => 110110031, 'yil' => 2026]],
    'number' => 0,
    'size' => 100,
    'totalElements' => 1,
    'totalPages' => 1,
    'last' => true,
    'fetched_at' => '2026-08-08 00:00:00',
];
$storage->writePage(2026, 0, 100, $page);
historicalStorageCheck($storage->readPage(2026, 0, 100) === $page, 'Historical page cache okunamadı.');

$state = [
    'version' => 1,
    'years' => [2023, 2024],
    'guide_year' => 2026,
    'page_size' => 100,
    'next_page' => 7,
];
$storage->writeState([2024, 2023], $state);
historicalStorageCheck($storage->readState([2023, 2024]) === $state, 'Historical resume state okunamadı.');

$reportPaths = $storage->writeReport([
    'items' => [[
        'program_code' => '110110031',
        'year' => 2024,
        'status' => 'would_insert',
        'base_score' => '514.28196',
        'base_rank' => 5725,
    ]],
]);
historicalStorageCheck(is_file($reportPaths['json']), 'Historical JSON raporu yazılmadı.');
historicalStorageCheck(is_file($reportPaths['csv']), 'Historical CSV raporu yazılmadı.');

$paths = [
    $root . '/storage/yokatlas/cache/historical_guide_2026_page_000000_size_100.json',
    $root . '/storage/yokatlas/state/historical_import_2023_2024_apply.json',
    $reportPaths['json'],
    $reportPaths['csv'],
];
foreach ($paths as $path) {
    unlink($path);
}
rmdir($root . '/storage/yokatlas/cache');
rmdir($root . '/storage/yokatlas/reports');
rmdir($root . '/storage/yokatlas/state');
rmdir($root . '/storage/yokatlas');
rmdir($root . '/storage');
rmdir($root);

echo "HistoricalImportStorageTest: OK\n";
