<?php

declare(strict_types=1);

namespace DersRotasi\Config;

final class Env
{
    public function __construct(private readonly array $values)
    {
    }

    public function appEnv(): string
    {
        return $this->get('APP_ENV', 'production');
    }

    public function isDebug(): bool
    {
        return filter_var($this->get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL);
    }

    public function frontendOrigin(): string
    {
        return $this->get('FRONTEND_ORIGIN');
    }

    public function firebaseProjectId(): string
    {
        return $this->get('FIREBASE_PROJECT_ID');
    }

    public function sslCaBundle(): ?string
    {
        if ($this->appEnv() !== 'local') {
            return null;
        }

        $path = trim($this->get('SSL_CA_BUNDLE'));

        return $path !== '' ? $path : null;
    }

    public function dbHost(): string
    {
        return $this->get('DB_HOST', '127.0.0.1');
    }

    public function dbPort(): string
    {
        return $this->get('DB_PORT', '3306');
    }

    public function dbName(): string
    {
        return $this->get('DB_DATABASE', 'dersrotasi');
    }

    public function dbUsername(): string
    {
        return $this->get('DB_USERNAME', 'root');
    }

    public function dbPassword(): string
    {
        return $this->get('DB_PASSWORD', '');
    }

    public function yokatlasUserAgent(): string
    {
        return $this->get('YOKATLAS_USER_AGENT', 'DersRotasiDataTool/1.0 (+http://localhost)');
    }

    private function get(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        return (string) $value;
    }
}
