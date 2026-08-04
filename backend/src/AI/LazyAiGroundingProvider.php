<?php

declare(strict_types=1);

namespace DersRotasi\AI;

use Closure;
use PDO;

final class LazyAiGroundingProvider implements AiGroundingProvider
{
    /**
     * @param Closure(): PDO $pdoFactory
     */
    public function __construct(
        private readonly Closure $pdoFactory,
        private readonly AiIntent $intent
    ) {
    }

    public function find(string $message, ?string $firebaseUid): array
    {
        if (!$this->intent->requiresDatabase($message)) {
            return [
                'required' => false,
                'searched' => false,
                'source' => null,
                'filters' => [],
                'items' => [],
            ];
        }

        return (new AiGroundingRepository(
            ($this->pdoFactory)(),
            $this->intent
        ))->find($message, $firebaseUid);
    }
}
