<?php

declare(strict_types=1);

namespace DersRotasi\Import;

use PDO;
use RuntimeException;

final class PdoUniversityRankStore implements UniversityRankStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function assertSchemaReady(): void
    {
        try {
            $this->pdo->query(
                'SELECT id, program_code, year, base_rank, rank_source_name, rank_source_url, rank_updated_at '
                . 'FROM universities WHERE 1 = 0'
            );
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'Başarı sırası alanları hazır değil. Önce 004 migration dosyasını inceleyip çalıştırın.',
                0,
                $exception
            );
        }
    }

    public function beginTransaction(): void
    {
        if (!$this->pdo->beginTransaction()) {
            throw new RuntimeException('Veritabanı transaction işlemi başlatılamadı.');
        }
    }

    public function commit(): void
    {
        if (!$this->pdo->commit()) {
            throw new RuntimeException('Veritabanı transaction işlemi tamamlanamadı.');
        }
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function find(string $programCode, int $year): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, program_code, year, base_rank '
            . 'FROM universities '
            . 'WHERE program_code = :program_code AND year = :year '
            . 'LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['program_code' => $programCode, 'year' => $year]);
        $row = $statement->fetch();

        return $row ?: null;
    }

    public function updateRank(
        int $id,
        string $programCode,
        int $year,
        int $baseRank,
        string $sourceName,
        ?string $sourceUrl
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE universities SET '
            . 'base_rank = :base_rank, '
            . 'rank_source_name = :rank_source_name, '
            . 'rank_source_url = :rank_source_url, '
            . 'rank_updated_at = CURRENT_TIMESTAMP '
            . 'WHERE id = :id AND program_code = :program_code AND year = :year'
        );
        $statement->execute([
            'base_rank' => $baseRank,
            'rank_source_name' => $sourceName,
            'rank_source_url' => $sourceUrl,
            'id' => $id,
            'program_code' => $programCode,
            'year' => $year,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Başarı sırası kaydı güvenli biçimde güncellenemedi.');
        }
    }
}
