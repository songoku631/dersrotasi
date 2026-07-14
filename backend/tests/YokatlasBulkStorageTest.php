<?php

declare(strict_types=1);

use DersRotasi\Yokatlas\YokatlasStorage;

require dirname(__DIR__) . '/vendor/autoload.php';

function bulkCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . '/dersrotasi_yokatlas_' . bin2hex(random_bytes(6));
$storage = new YokatlasStorage($root);
$page = [
    'content' => [], 'number' => 1, 'size' => 100,
    'totalElements' => 21602, 'totalPages' => 217,
];
$storage->writePageCache(2025, 1, 100, $page);
bulkCheck($storage->readPageCache(2025, 1, 100) === $page, 'Sayfa cache okuma/yazma başarısız.');

$dryState = ['version' => 2, 'page_size' => 100, 'next_index' => 200];
$applyState = ['version' => 2, 'page_size' => 100, 'next_index' => 100];
$storage->writeBulkState(2025, 'dry-run', $dryState);
$storage->writeBulkState(2025, 'apply', $applyState);
bulkCheck($storage->readBulkState(2025, 'dry-run') === $dryState, 'Dry-run resume state okunamadı.');
bulkCheck($storage->readBulkState(2025, 'apply') === $applyState, 'Apply resume state okunamadı.');
bulkCheck($storage->readBulkState(2025, 'dry-run') !== $storage->readBulkState(2025, 'apply'), 'Mod state dosyaları ayrılmadı.');

unlink($root . '/storage/yokatlas/cache/page_2025_000001_size_100.json');
unlink($root . '/storage/yokatlas/state/bulk_resume_2025_dry_run.json');
unlink($root . '/storage/yokatlas/state/bulk_resume_2025_apply.json');
rmdir($root . '/storage/yokatlas/cache');
rmdir($root . '/storage/yokatlas/reports');
rmdir($root . '/storage/yokatlas/state');
rmdir($root . '/storage/yokatlas');
rmdir($root . '/storage');
rmdir($root);

echo "YokatlasBulkStorageTest: OK\n";
