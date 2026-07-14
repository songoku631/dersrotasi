<?php

declare(strict_types=1);

namespace DersRotasi\Http;

final class JsonResponse
{
    public static function applyCors(string $origin): void
    {
        if ($origin === '') {
            return;
        }

        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Vary: Origin');
    }

    public static function send(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
