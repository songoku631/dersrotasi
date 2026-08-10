<?php

declare(strict_types=1);

namespace DersRotasi\Osym;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

final class OsymFileCache
{
    private string $cacheDirectory;
    private string $manifestPath;
    private Client $client;

    public function __construct(string $backendRoot, ?Client $client = null)
    {
        $this->cacheDirectory = rtrim($backendRoot, '/\\') . '/storage/osym/cache';
        $this->manifestPath = $this->cacheDirectory . '/manifest.json';
        if (!is_dir($this->cacheDirectory)
            && !mkdir($this->cacheDirectory, 0770, true)
            && !is_dir($this->cacheDirectory)) {
            throw new RuntimeException('ÖSYM cache dizini oluşturulamadı.');
        }
        $this->client = $client ?? new Client([
            'connect_timeout' => 15,
            'timeout' => 120,
            'http_errors' => false,
            'allow_redirects' => ['max' => 3, 'strict' => true],
            'headers' => [
                'User-Agent' => 'DersRotasiOsymBackfill/1.0 (+http://localhost)',
                'Accept' => 'application/vnd.ms-excel, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        ]);
    }

    /**
     * @param array<string, int|string> $source
     * @return array<string, bool|int|string|null>
     */
    public function ensure(array $source, bool $refresh = false): array
    {
        $filename = basename((string) ($source['filename'] ?? ''));
        if ($filename === '' || $filename !== (string) $source['filename']) {
            throw new RuntimeException('Güvensiz ÖSYM cache dosya adı.');
        }
        $path = $this->cacheDirectory . '/' . $filename;
        $manifest = $this->readManifest();
        $old = $manifest['files'][$filename] ?? null;

        if (is_file($path) && !$refresh) {
            $this->validateSignature($path);
            $sha256 = hash_file('sha256', $path);
            if (is_array($old) && isset($old['sha256']) && !hash_equals((string) $old['sha256'], $sha256)) {
                throw new RuntimeException("ÖSYM cache checksum uyuşmazlığı: {$filename}");
            }
            $entry = $this->manifestEntry($source, $path, $sha256, true, false, $old);
            $manifest['files'][$filename] = $entry;
            $this->writeManifest($manifest);
            return ['path' => $path, ...$entry];
        }

        $temporary = $path . '.part';
        if (is_file($temporary)) {
            unlink($temporary);
        }
        $headers = $this->download((string) $source['url'], $temporary);
        try {
            $this->validateSignature($temporary);
            $sha256 = hash_file('sha256', $temporary);
            $remoteChanged = is_array($old)
                && isset($old['sha256'])
                && !hash_equals((string) $old['sha256'], $sha256);
            if (!rename($temporary, $path)) {
                throw new RuntimeException("ÖSYM cache dosyası yerine taşınamadı: {$filename}");
            }
            $entry = $this->manifestEntry($source, $path, $sha256, false, $remoteChanged, [
                'etag' => $headers['etag'] ?? null,
                'last_modified' => $headers['last_modified'] ?? null,
            ]);
            $manifest['files'][$filename] = $entry;
            $this->writeManifest($manifest);
            return ['path' => $path, ...$entry];
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @return array{etag: ?string, last_modified: ?string} */
    private function download(string $url, string $temporary): array
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = $this->client->request('GET', $url, ['sink' => $temporary]);
                $status = $response->getStatusCode();
                if ($status === 200 && is_file($temporary) && filesize($temporary) > 0) {
                    return [
                        'etag' => $this->nullableHeader($response->getHeaderLine('ETag')),
                        'last_modified' => $this->nullableHeader($response->getHeaderLine('Last-Modified')),
                    ];
                }
                if ($status < 500 || $attempt === 3) {
                    throw new RuntimeException("ÖSYM dosyası indirilemedi; HTTP {$status}: {$url}");
                }
            } catch (GuzzleException $exception) {
                if ($attempt === 3) {
                    throw new RuntimeException("ÖSYM dosyası üç denemede indirilemedi: {$url}", 0, $exception);
                }
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
            sleep(2 ** $attempt);
        }

        throw new RuntimeException("ÖSYM dosyası indirilemedi: {$url}");
    }

    private function validateSignature(string $path): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("ÖSYM cache dosyası okunamadı: {$path}");
        }
        try {
            $signature = fread($handle, 8);
        } finally {
            fclose($handle);
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $valid = $extension === 'xlsx'
            ? str_starts_with((string) $signature, "PK\x03\x04")
            : $extension === 'xls' && $signature === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
        if (!$valid) {
            throw new RuntimeException("ÖSYM dosya imzası uzantıyla uyuşmuyor: {$path}");
        }
    }

    /**
     * @param array<string, int|string> $source
     * @param mixed $previous
     * @return array<string, bool|int|string|null>
     */
    private function manifestEntry(
        array $source,
        string $path,
        string $sha256,
        bool $fromCache,
        bool $remoteChanged,
        mixed $previous,
    ): array {
        return [
            'url' => (string) $source['url'],
            'sha256' => $sha256,
            'size' => (int) filesize($path),
            'from_cache' => $fromCache,
            'remote_changed' => $remoteChanged,
            'etag' => is_array($previous) ? ($previous['etag'] ?? null) : null,
            'last_modified' => is_array($previous) ? ($previous['last_modified'] ?? null) : null,
            'verified_at' => date(DATE_ATOM),
        ];
    }

    private function nullableHeader(string $value): ?string
    {
        $value = trim($value);
        return $value !== '' ? $value : null;
    }

    private function readManifest(): array
    {
        if (!is_file($this->manifestPath)) {
            return ['version' => 1, 'files' => []];
        }
        $manifest = json_decode((string) file_get_contents($this->manifestPath), true);
        return is_array($manifest) ? $manifest : ['version' => 1, 'files' => []];
    }

    private function writeManifest(array $manifest): void
    {
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($this->manifestPath, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('ÖSYM cache manifest yazılamadı.');
        }
    }
}
