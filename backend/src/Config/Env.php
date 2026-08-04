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

    public function openAiApiKey(): string
    {
        return trim($this->get('OPENAI_API_KEY'));
    }

    public function openAiModel(): string
    {
        return trim($this->get('OPENAI_MODEL', 'gpt-5.6-luna')) ?: 'gpt-5.6-luna';
    }

    public function openAiTimeout(): int
    {
        $value = filter_var(
            $this->get('OPENAI_TIMEOUT', '25'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 5, 'max_range' => 60]]
        );

        return $value === false ? 25 : (int) $value;
    }

    public function aiChatEnabled(): bool
    {
        return filter_var(
            $this->has('AI_ENABLED') ? $this->get('AI_ENABLED') : $this->get('AI_CHAT_ENABLED', 'true'),
            FILTER_VALIDATE_BOOL
        );
    }

    public function aiFreeDailyRequests(): int
    {
        return $this->positiveInt('AI_FREE_DAILY_REQUESTS', 3, 1, 1000);
    }

    public function aiFreeDailyTokenBudget(): int
    {
        return $this->positiveInt('AI_FREE_DAILY_TOKEN_BUDGET', 6000, 500, 10000000);
    }

    public function aiFreeMaxMessageChars(): int
    {
        return $this->positiveInt('AI_FREE_MAX_MESSAGE_CHARS', 1200, 100, 10000);
    }

    public function aiPremiumDailyRequests(): int
    {
        return $this->positiveInt('AI_PREMIUM_DAILY_REQUESTS', 50, 1, 10000);
    }

    public function aiPremiumDailyTokenBudget(): int
    {
        return $this->positiveInt('AI_PREMIUM_DAILY_TOKEN_BUDGET', 60000, 500, 100000000);
    }

    public function aiPremiumMaxMessageChars(): int
    {
        return $this->positiveInt('AI_PREMIUM_MAX_MESSAGE_CHARS', 2500, 100, 20000);
    }

    public function aiGlobalDailyTokenBudget(): int
    {
        return $this->positiveInt('AI_GLOBAL_DAILY_TOKEN_BUDGET', 200000, 1000, 1000000000);
    }

    public function aiMaxOutputTokens(): int
    {
        return $this->positiveInt('AI_MAX_OUTPUT_TOKENS', 500, 16, 10000);
    }

    private function get(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        return (string) $value;
    }

    private function has(string $key): bool
    {
        return array_key_exists($key, $this->values) || getenv($key) !== false;
    }

    private function positiveInt(string $key, int $default, int $minimum, int $maximum): int
    {
        $value = filter_var(
            $this->get($key, (string) $default),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]
        );

        return $value === false ? $default : (int) $value;
    }
}
