<?php

declare(strict_types=1);

namespace DersRotasi\Yokatlas;

use RuntimeException;

final class YokatlasStorage
{
    private string $cacheDirectory;
    private string $reportsDirectory;
    private string $stateDirectory;

    public function __construct(string $root, ?string $outputDirectory = null)
    {
        $base = rtrim($root, '/\\') . '/storage/yokatlas';
        $this->cacheDirectory = $base . '/cache';
        $this->reportsDirectory = $outputDirectory !== null
            ? rtrim($outputDirectory, '/\\')
            : $base . '/reports';
        $this->stateDirectory = $base . '/state';
        foreach ([$this->cacheDirectory, $this->reportsDirectory, $this->stateDirectory] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
                throw new RuntimeException('YÖK Atlas çalışma dizini hazırlanamadı.');
            }
        }
    }

    public function readCache(int $year, string $programCode): ?array
    {
        return $this->readJson($this->cachePath($year, $programCode));
    }

    public function writeCache(int $year, string $programCode, array $payload): void
    {
        $this->writeJson($this->cachePath($year, $programCode), $payload);
    }

    public function readPageCache(int $year, int $page, int $size): ?array
    {
        return $this->readJson($this->pageCachePath($year, $page, $size));
    }

    public function writePageCache(int $year, int $page, int $size, array $payload): void
    {
        $this->writeJson($this->pageCachePath($year, $page, $size), $payload);
    }

    public function readState(int $year, string $mode): ?array
    {
        return $this->readJson($this->statePath($year, $mode));
    }

    public function writeState(int $year, string $mode, array $state): void
    {
        $this->writeJson($this->statePath($year, $mode), $state);
    }

    public function readBulkState(int $year, string $mode): ?array
    {
        return $this->readJson($this->bulkStatePath($year, $mode));
    }

    public function writeBulkState(int $year, string $mode, array $state): void
    {
        $this->writeJson($this->bulkStatePath($year, $mode), $state);
    }

    public function writeReports(array $report): array
    {
        $stamp = date('Ymd_His') . '_' . substr(hash('sha256', (string) microtime(true)), 0, 8);
        $jsonPath = $this->reportsDirectory . "/yokatlas_ranks_{$stamp}.json";
        $csvPath = $this->reportsDirectory . "/yokatlas_ranks_{$stamp}.csv";
        $this->writeJson($jsonPath, $report);

        $handle = fopen($csvPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException('CSV raporu oluşturulamadı.');
        }
        try {
            fputcsv($handle, [
                'program_code', 'status', 'base_rank', 'year', 'source_name', 'source_url',
                'fetched_at', 'from_cache', 'reason', 'existing_base_score', 'official_base_score',
            ]);
            foreach ($report['items'] as $item) {
                fputcsv($handle, [
                    $item['program_code'] ?? '', $item['status'] ?? '', $item['base_rank'] ?? '',
                    $item['year'] ?? '', $item['source_name'] ?? '', $item['source_url'] ?? '',
                    $item['fetched_at'] ?? '', !empty($item['from_cache']) ? '1' : '0',
                    $item['reason'] ?? '', $item['existing_base_score'] ?? '',
                    $item['official_base_score'] ?? '',
                ]);
            }
        } finally {
            fclose($handle);
        }
        return ['json' => $jsonPath, 'csv' => $csvPath];
    }

    private function cachePath(int $year, string $programCode): string
    {
        return $this->cacheDirectory . "/{$year}_{$programCode}.json";
    }

    private function pageCachePath(int $year, int $page, int $size): string
    {
        return $this->cacheDirectory . sprintf('/page_%d_%06d_size_%d.json', $year, $page, $size);
    }

    private function statePath(int $year, string $mode): string
    {
        $safeMode = $mode === 'apply' ? 'apply' : 'dry_run';
        return $this->stateDirectory . "/resume_{$year}_{$safeMode}.json";
    }

    private function bulkStatePath(int $year, string $mode): string
    {
        $safeMode = $mode === 'apply' ? 'apply' : 'dry_run';
        return $this->stateDirectory . "/bulk_resume_{$year}_{$safeMode}.json";
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
        if ($json === false) {
            throw new RuntimeException('JSON verisi oluşturulamadı.');
        }
        if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('YÖK Atlas çalışma dosyası güvenli biçimde yazılamadı.');
        }
    }
}
