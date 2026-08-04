<?php

declare(strict_types=1);

namespace DersRotasi\AI;

interface RateLimitStore
{
    public function consume(string $identifierHash, int $limit, int $windowSeconds): bool;
}
