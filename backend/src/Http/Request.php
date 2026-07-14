<?php

declare(strict_types=1);

namespace DersRotasi\Http;

final class Request
{
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $headers,
        private readonly string $body
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            self::normalizeHeaders(),
            file_get_contents('php://input') ?: ''
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function bearerToken(): ?string
    {
        $authorization = $this->headers['authorization'] ?? '';

        if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            return null;
        }

        return trim($matches[1]);
    }

    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }

        $decoded = json_decode($this->body, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Geçersiz JSON gövdesi.', 422);
        }

        return $decoded;
    }

    private static function normalizeHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (!str_starts_with($key, 'HTTP_')) {
                continue;
            }

            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = (string) $value;
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = (string) $_SERVER['HTTP_AUTHORIZATION'];
        }

        return $headers;
    }
}
