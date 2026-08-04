<?php

declare(strict_types=1);

namespace DersRotasi\AI;

interface OpenAiClient
{
    public function respond(string $instructions, array $input, string $safetyIdentifier): array;
}
