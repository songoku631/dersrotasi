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

    /**
     * @return list<string>
     */
    public function corsAllowedOrigins(): array
    {
        $origins = [
            'https://dersrotasi.com',
            'https://www.dersrotasi.com',
            'https://derspilot-233017262289.europe-west1.run.app',
        ];

        if ($this->appEnv() === 'local') {
            $origins[] = 'http://localhost:5176';
            $origins[] = 'http://localhost:5173';
            $configuredOrigin = trim($this->frontendOrigin());
            if ($configuredOrigin !== '') {
                $origins[] = $configuredOrigin;
            }
        }

        return array_values(array_unique($origins));
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
        return $this->get('DB_NAME', $this->get('DB_DATABASE', 'dersrotasi'));
    }

    public function dbUsername(): string
    {
        return $this->get('DB_USER', $this->get('DB_USERNAME', 'root'));
    }

    public function dbPassword(): string
    {
        return $this->get('DB_PASSWORD', '');
    }

    public function instanceConnectionName(): ?string
    {
        $value = trim($this->get('INSTANCE_CONNECTION_NAME'));

        return $value !== '' ? $value : null;
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
