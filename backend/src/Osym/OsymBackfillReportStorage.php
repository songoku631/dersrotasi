<?php

declare(strict_types=1);

namespace DersRotasi\Osym;

use RuntimeException;

final class OsymBackfillReportStorage
{
    private string $reportsDirectory;

    public function __construct(string $backendRoot)
    {
        $this->reportsDirectory = rtrim($backendRoot, '/\\') . '/storage/osym/reports';
        if (!is_dir($this->reportsDirectory)
            && !mkdir($this->reportsDirectory, 0770, true)
            && !is_dir($this->reportsDirectory)) {
            throw new RuntimeException('ÖSYM report dizini oluşturulamadı.');
        }
    }

    /** @return array{json: string, csv: string} */
    public function write(array $report): array
    {
        $mode = ($report['mode'] ?? 'dry_run') === 'apply' ? 'apply' : 'dry_run';
        $stamp = date('Ymd_His') . '_' . substr(hash('sha256', (string) microtime(true)), 0, 8);
        $jsonPath = $this->reportsDirectory . "/osym_historical_backfill_{$mode}_{$stamp}.json";
        $csvPath = $this->reportsDirectory . "/osym_historical_backfill_{$mode}_{$stamp}.csv";
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($jsonPath, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('ÖSYM JSON raporu yazılamadı.');
        }

        $handle = fopen($csvPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException('ÖSYM CSV raporu yazılamadı.');
        }
        try {
            fputcsv($handle, [
                'status', 'program_code', 'year', 'field', 'old_value', 'new_value',
                'source', 'source_file', 'source_url', 'source_row', 'reason',
            ]);
            foreach ([...($report['changes'] ?? []), ...($report['conflicts'] ?? [])] as $item) {
                fputcsv($handle, [
                    $item['status'] ?? '', $item['program_code'] ?? '', $item['year'] ?? '',
                    $item['field'] ?? '', $item['old_value'] ?? '', $item['new_value'] ?? '',
                    $item['source'] ?? '', $item['source_file'] ?? '', $item['source_url'] ?? '',
                    $item['source_row'] ?? '', $item['reason'] ?? '',
                ]);
            }
        } finally {
            fclose($handle);
        }

        return ['json' => $jsonPath, 'csv' => $csvPath];
    }
}
