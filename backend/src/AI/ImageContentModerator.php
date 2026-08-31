<?php

declare(strict_types=1);

namespace DersRotasi\AI;

interface ImageContentModerator
{
    /** @return array{flagged: bool, categories: list<string>} */
    public function inspectImage(string $mimeType, string $bytes): array;
}
