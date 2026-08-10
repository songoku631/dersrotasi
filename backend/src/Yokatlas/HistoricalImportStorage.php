<?php

declare(strict_types=1);

namespace DersRotasi\Yokatlas;

use RuntimeException;

final class HistoricalImportStorage
{
    private string $cacheDirectory;
    private string $reportsDirectory;
    private string $stateDirectory;

    public function __construct(string $root)
    {
        $base = rtrim($root, '/\\') . '/storage/yokatlas';
        $this->cacheDirectory = $base . '/cache';
        $this->reportsDirectory = $base . '/reports';
        $this->stateDirectory = $base . '/state';
        foreach ([$this->cacheDirectory, $this->reportsDirectory, $this->stateDirectory] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
                throw new RuntimeException('Historical import çalışma dizini hazırlanamadı.');
            }
        }
    }

    public function readPage(int $guideYear, int $page, int $size): ?array
    {
        return $this->readJson($this->pagePath($guideYear, $page, $size));
    }

    public function writePage(int $guideYear, int $page, int $size, array $payload): void
    {
        $this->writeJson($this->pagePath($guideYear, $page, $size), $payload);
    }

    public function readState(array $years): ?array
    {
        return $this->readJson($this->statePath($years));
    }

    public function writeState(array $years, array $state): void
    {
        $this->writeJson($this->statePath($years), $state);
    }

    /**
     * @return array{json: string, csv: string}
     */
    public function writeReport(array $report): array
    {
        $stamp = date('Ymd_His') . '_' . substr(hash('sha256', (string) microtime(true)), 0, 8);
        $jsonPath = $this->reportsDirectory . "/historical_import_{$stamp}.json";
        $csvPath = $this->reportsDirectory . "/historical_import_{$stamp}.csv";
        $this->writeJson($jsonPath, $report);

        $handle = fopen($csvPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Historical import CSV raporu oluşturulamadı.');
        }
        try {
            fputcsv($handle, [
                'program_code', 'year', 'status', 'base_score', 'base_rank', 'quota',
                'placed_count', 'guide_year', 'metadata_basis', 'reason',
            ]);
            foreach ($report['items'] ?? [] as $item) {
                fputcsv($handle, [
                    $item['program_code'] ?? '', $item['year'] ?? '', $item['status'] ?? '',
                    $item['base_score'] ?? '', $item['base_rank'] ?? '', $item['quota'] ?? '',
                    $item['placed_count'] ?? '', $item['guide_year'] ?? '',
                    $item['metadata_basis'] ?? '', $item['reason'] ?? '',
                ]);
            }
        } finally {
            fclose($handle);
        }
        return ['json' => $jsonPath, 'csv' => $csvPath];
    }

    private function pagePath(int $guideYear, int $page, int $size): string
    {
        return $this->cacheDirectory
            . sprintf('/historical_guide_%d_page_%06d_size_%d.json', $guideYear, $page, $size);
    }

    private function statePath(array $years): string
    {
        sort($years);
        $suffix = implode('_', array_map('intval', $years));
        return $this->stateDirectory . "/historical_import_{$suffix}_apply.json";
    }

    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    private function writeJson(string $path, array $payload): void
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Historical import JSON dosyası yazılamadı.');
        }
    }
}
