<?php
declare(strict_types=1);
namespace DersRotasi\Services;

interface PomodoroChatObjectStorage
{
    public function put(string $key, string $sourcePath, string $mime): void;
    public function get(string $key): string;
    public function delete(string $key): void;
}
