<?php

declare(strict_types=1);

namespace DersRotasi\AI;

interface AiGroundingProvider
{
    public function find(string $message, ?string $firebaseUid): array;
}
