<?php
declare(strict_types=1);
namespace DersRotasi\Pomodoro;

final class TurnIceConfigService
{
    public const TTL_SECONDS = 3600;

    public function create(string $uid, array $turnUrls, string $secret, ?int $now = null): array
    {
        if ($uid === '' || $secret === '') {
            throw new \RuntimeException('Ses bağlantısı şu anda güvenli şekilde hazırlanamadı. Lütfen daha sonra tekrar dene.', 503);
        }

        $urls = array_values(array_filter($turnUrls, static fn($url) => is_string($url) && preg_match('#^turns?:[^\s]+$#', $url)));
        if ($urls === []) {
            throw new \RuntimeException('Ses bağlantısı şu anda güvenli şekilde hazırlanamadı. Lütfen daha sonra tekrar dene.', 503);
        }

        $expiresAt = ($now ?? time()) + self::TTL_SECONDS;
        $username = $expiresAt . ':' . substr(hash('sha256', $uid), 0, 24);

        return [
            'iceServers' => [
                ['urls' => ['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302']],
                [
                    'urls' => $urls,
                    'username' => $username,
                    'credential' => base64_encode(hash_hmac('sha1', $username, $secret, true)),
                ],
            ],
            'expiresAt' => gmdate('c', $expiresAt),
        ];
    }
}
