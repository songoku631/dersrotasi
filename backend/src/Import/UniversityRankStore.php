<?php

declare(strict_types=1);

namespace DersRotasi\Import;

interface UniversityRankStore
{
    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;

    public function inTransaction(): bool;

    public function find(string $programCode, int $year): ?array;

    public function updateRank(
        int $id,
        string $programCode,
        int $year,
        int $baseRank,
        string $sourceName,
        ?string $sourceUrl
    ): void;
}
