<?php

declare(strict_types=1);

namespace DersRotasi\AI;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class PdoRateLimitStore implements RateLimitStore
{
    private const CLEANUP_LIMIT = 100;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function consume(string $identifierHash, int $limit, int $windowSeconds): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $identifierHash)) {
            throw new InvalidArgumentException('Rate limit identifier hash is invalid.');
        }
        if ($limit < 1 || $windowSeconds < 1) {
            throw new InvalidArgumentException('Rate limit configuration is invalid.');
        }
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Rate limit store cannot join an existing transaction.');
        }

        try {
            $this->cleanupExpired();
            $this->pdo->beginTransaction();

            $statement = $this->pdo->prepare(
                'INSERT INTO ai_rate_limits '
                . '(identifier_hash, window_started_at, request_count, expires_at) '
                . 'VALUES (:identifier_hash, UTC_TIMESTAMP(6), 1, '
                . 'TIMESTAMPADD(SECOND, :insert_window_seconds, UTC_TIMESTAMP(6))) '
                . 'ON DUPLICATE KEY UPDATE '
                . 'request_count = IF(expires_at <= UTC_TIMESTAMP(6), 1, request_count + 1), '
                . 'window_started_at = IF('
                . 'expires_at <= UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), window_started_at), '
                . 'expires_at = IF('
                . 'expires_at <= UTC_TIMESTAMP(6), '
                . 'TIMESTAMPADD(SECOND, :update_window_seconds, UTC_TIMESTAMP(6)), expires_at)'
            );
            $statement->bindValue(':identifier_hash', $identifierHash, PDO::PARAM_STR);
            $statement->bindValue(':insert_window_seconds', $windowSeconds, PDO::PARAM_INT);
            $statement->bindValue(':update_window_seconds', $windowSeconds, PDO::PARAM_INT);
            $statement->execute();

            $countStatement = $this->pdo->prepare(
                'SELECT request_count FROM ai_rate_limits '
                . 'WHERE identifier_hash = :identifier_hash FOR UPDATE'
            );
            $countStatement->execute(['identifier_hash' => $identifierHash]);
            $count = $countStatement->fetchColumn();
            if (!is_int($count) && !(is_string($count) && preg_match('/^\d+$/', $count))) {
                throw new RuntimeException('Rate limit counter could not be read.');
            }

            $allowed = (int) $count <= $limit;
            if ($allowed) {
                $this->pdo->commit();
            } else {
                $this->pdo->rollBack();
            }

            return $allowed;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function cleanupExpired(): void
    {
        $deleted = $this->pdo->exec(
            'DELETE FROM ai_rate_limits WHERE expires_at <= UTC_TIMESTAMP(6) '
            . 'ORDER BY expires_at LIMIT ' . self::CLEANUP_LIMIT
        );
        if ($deleted === false) {
            throw new RuntimeException('Expired rate limit records could not be cleaned.');
        }
    }
}
