<?php

declare(strict_types=1);

namespace DersRotasi\Historical;

use PDO;
use RuntimeException;
use Throwable;

final class HistoricalProgramMappingRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function assertSchemaReady(): void
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT COUNT(*)
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'program_historical_mappings'
SQL);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException(
                'Önce 010_create_program_historical_mappings.sql migrationı uygulanmalıdır.'
            );
        }
    }

    /** @param list<array<string, mixed>> $matches @return array{inserted: int, skipped_existing: int} */
    public function applyVerified(array $matches): array
    {
        $result = ['inserted' => 0, 'skipped_existing' => 0];
        $find = $this->pdo->prepare(<<<'SQL'
SELECT historical_program_code, confidence, verification_status, match_method
FROM program_historical_mappings
WHERE current_program_code = :current_program_code AND historical_year = :historical_year
LIMIT 1
SQL);
        $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO program_historical_mappings (
  current_program_code, historical_program_code, historical_year,
  confidence, verification_status, match_method, evidence_json, verified_at
) VALUES (
  :current_program_code, :historical_program_code, :historical_year,
  :confidence, :verification_status, :match_method, :evidence_json, CURRENT_TIMESTAMP
)
SQL);

        try {
            $this->pdo->beginTransaction();
            foreach ($matches as $match) {
                if (($match['confidence'] ?? null) !== 'high'
                    || ($match['verification_status'] ?? null) !== 'verified') {
                    throw new RuntimeException('Yalnız verified/high mapping uygulanabilir.');
                }
                $key = [
                    'current_program_code' => (string) $match['current_program_code'],
                    'historical_year' => (int) $match['historical_year'],
                ];
                $find->execute($key);
                $existing = $find->fetch();
                if ($existing !== false) {
                    if ((string) $existing['historical_program_code'] !== (string) $match['historical_program_code']
                        || (string) $existing['confidence'] !== 'high'
                        || (string) $existing['verification_status'] !== 'verified'
                        || (string) $existing['match_method'] !== (string) $match['match_method']) {
                        throw new RuntimeException(
                            "Mevcut mapping conflict: {$key['current_program_code']}/{$key['historical_year']}"
                        );
                    }
                    $result['skipped_existing']++;
                    continue;
                }

                $evidence = json_encode(
                    $match['evidence'] ?? [],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                );
                $insert->execute([
                    ...$key,
                    'historical_program_code' => (string) $match['historical_program_code'],
                    'confidence' => 'high',
                    'verification_status' => 'verified',
                    'match_method' => (string) $match['match_method'],
                    'evidence_json' => $evidence,
                ]);
                $result['inserted']++;
            }
            $this->pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
