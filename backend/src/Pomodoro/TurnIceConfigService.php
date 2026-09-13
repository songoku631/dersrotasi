<?php
declare(strict_types=1);
namespace DersRotasi\Pomodoro;

final class TurnIceConfigService
{
    public const TTL_SECONDS = 3600;

    public function create(string $uid, array $turnUrls, string $secret, ?int $now = null): array
    {
        $servers = [['urls' => ['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302']]];
        $expiresAt = null;
        $urls = array_values(array_filter($turnUrls, static fn($url) => is_string($url) && preg_match('#^turns?:[^\s]+$#', $url)));
        if ($urls !== [] && $secret !== '') {
            $expiresAt = ($now ?? time()) + self::TTL_SECONDS;
            $username = $expiresAt . ':' . substr(hash('sha256', $uid), 0, 24);
            $servers[] = ['urls' => $urls, 'username' => $username, 'credential' => base64_encode(hash_hmac('sha1', $username, $secret, true))];
        }
        return ['ice_servers' => $servers, 'relay_available' => count($servers) > 1, 'expires_at' => $expiresAt];
    }
}
