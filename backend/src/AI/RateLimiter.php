<?php

declare(strict_types=1);

namespace DersRotasi\AI;

use RuntimeException;
use Throwable;

final class RateLimiter
{
    public function __construct(
        private readonly RateLimitStore $store,
        private readonly int $limit = 10,
        private readonly int $windowSeconds = 60
    ) {
    }

    public function hit(string $identifier): void
    {
        try {
            $allowed = $this->store->consume(
                hash('sha256', $identifier),
                $this->limit,
                $this->windowSeconds
            );
        } catch (Throwable $exception) {
            error_log('[AI Rate Limit] shared database check failed');
            throw new RuntimeException(
                'İstek sınırı şu anda doğrulanamıyor. Lütfen biraz sonra tekrar dene.',
                503,
                $exception
            );
        }

        if (!$allowed) {
            throw new RuntimeException(
                'Çok fazla istek gönderdin. Lütfen bir dakika sonra tekrar dene.',
                429
            );
        }
    }
}
