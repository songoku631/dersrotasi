<?php
declare(strict_types=1);
namespace DersRotasi\Services;

use Google\Cloud\Storage\StorageClient;
use RuntimeException;
use Throwable;

final class GcsPomodoroChatObjectStorage implements PomodoroChatObjectStorage
{
    private readonly object $bucket;

    public function __construct(string $bucketName, ?StorageClient $client = null)
    {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{1,61}[a-z0-9]$/', $bucketName)) throw new RuntimeException('GCS bucket yapılandırması geçersiz.', 500);
        $this->bucket = ($client ?? new StorageClient())->bucket($bucketName);
    }

    public function put(string $key, string $sourcePath, string $mime): void
    {
        $key = PomodoroChatObjectKey::assert($key);
        $handle = @fopen($sourcePath, 'rb');
        if ($handle === false) throw new RuntimeException('Görsel saklanamadı.', 500);
        try { $this->bucket->upload($handle, ['name' => $key, 'metadata' => ['contentType' => $mime, 'cacheControl' => 'private, max-age=3600']]); }
        catch (Throwable $e) { throw new RuntimeException('Görsel saklanamadı.', 503, $e); }
        finally { fclose($handle); }
    }

    public function get(string $key): string
    {
        try { return $this->bucket->object(PomodoroChatObjectKey::assert($key))->downloadAsString(); }
        catch (Throwable $e) { throw new RuntimeException('Görsel alınamadı.', 503, $e); }
    }

    public function delete(string $key): void
    {
        try { $this->bucket->object(PomodoroChatObjectKey::assert($key))->delete(); }
        catch (Throwable) { error_log('[POMODORO_CHAT_IMAGE] event=orphan_cleanup_failed'); }
    }
}
