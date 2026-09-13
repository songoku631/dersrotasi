<?php
declare(strict_types=1);
namespace DersRotasi\Services;

use RuntimeException;

final class LocalPomodoroChatObjectStorage implements PomodoroChatObjectStorage
{
    public function __construct(private readonly string $root) {}

    public function put(string $key, string $sourcePath, string $mime): void
    {
        $target = $this->path($key);
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) throw new RuntimeException('Görsel saklanamadı.', 500);
        if (!copy($sourcePath, $target)) throw new RuntimeException('Görsel saklanamadı.', 500);
    }

    public function get(string $key): string
    {
        $bytes = @file_get_contents($this->path($key));
        if (!is_string($bytes)) throw new RuntimeException('Görsel bulunamadı.', 404);
        return $bytes;
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path)) @unlink($path);
    }

    private function path(string $key): string
    {
        $safe = PomodoroChatObjectKey::assert($key);
        return rtrim($this->root, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safe);
    }
}
