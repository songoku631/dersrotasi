<?php
declare(strict_types=1);
namespace DersRotasi\Services;

use RuntimeException;

final class PomodoroChatObjectKey
{
    public static function generate(int $roomId): string
    {
        if ($roomId < 1) throw new RuntimeException('Geçersiz oda.', 422);
        return sprintf('pomodoro/%d/%s.jpg', $roomId, bin2hex(random_bytes(24)));
    }

    public static function assert(string $key): string
    {
        if (!preg_match('#^pomodoro/[1-9][0-9]*/[a-f0-9]{48}\.jpg$#D', $key)) {
            throw new RuntimeException('Geçersiz görsel anahtarı.', 422);
        }
        return $key;
    }
}
