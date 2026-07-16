<?php

declare(strict_types=1);

namespace DersRotasi\Http;

final class JsonResponse
{
    /**
     * @param list<string> $allowedOrigins
     */
    public static function applyCors(array $allowedOrigins, ?string $requestOrigin): void
    {
        if ($requestOrigin === null || !in_array($requestOrigin, $allowedOrigins, true)) {
            return;
        }

        header('Access-Control-Allow-Origin: ' . $requestOrigin);
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
