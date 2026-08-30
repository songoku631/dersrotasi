<?php

declare(strict_types=1);

namespace DersRotasi\AI;

interface AiContentModerator
{
    /**
     * @return array{flagged: bool, categories: list<string>}
     */
    public function inspect(string $content): array;
}
