<?php

declare(strict_types=1);

namespace DersRotasi\Repositories;

use PDO;
use PDOException;
use RuntimeException;

final class FavoriteRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function all(string $firebaseUid): array
    {
        $statement = $this->pdo->prepare(
            'SELECT u.*, u.year AS source_year, 1 AS is_favorite, '
            . 'f.university_id AS favorite_id, f.created_at AS favorited_at, '
            . 'CASE WHEN u25.id IS NOT NULL THEN u25.base_rank ELSE u26.base_rank END AS ranking_2025, '
            . 'u24.base_rank AS ranking_2024, u23.base_rank AS ranking_2023, '
            . 'CASE WHEN u25.id IS NOT NULL THEN u25.base_score ELSE u26.base_score END AS score_2025, '
            . 'u24.base_score AS score_2024, u23.base_score AS score_2023, '
            . 'CASE WHEN u25.id IS NOT NULL THEN u25.quota ELSE u26.quota END AS quota_2025, '
            . 'u24.quota AS quota_2024, u23.quota AS quota_2023 '
            . 'FROM favorites f INNER JOIN universities u ON u.id = f.university_id '
            . 'LEFT JOIN universities u25 ON u25.program_code = u.program_code AND u25.year = 2025 '
            . 'LEFT JOIN universities u26 ON u26.program_code = u.program_code AND u26.year = 2026 AND u25.id IS NULL '
            . 'LEFT JOIN universities u24 ON u24.program_code = u.program_code AND u24.year = 2024 '
            . 'LEFT JOIN universities u23 ON u23.program_code = u.program_code AND u23.year = 2023 '
            . 'WHERE f.firebase_uid = :firebase_uid ORDER BY f.created_at DESC'
        );
        $statement->execute(['firebase_uid' => $firebaseUid]);

        return array_map(
            [new UniversityHistoryPresenter(), 'present'],
            $statement->fetchAll()
        );
    }

    public function add(string $firebaseUid, int $universityId): bool
    {
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO favorites (firebase_uid, university_id) VALUES (:firebase_uid, :university_id)'
            );
            $statement->execute(['firebase_uid' => $firebaseUid, 'university_id' => $universityId]);
            return true;
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $exists = $this->pdo->prepare(
                    'SELECT 1 FROM favorites WHERE firebase_uid = :firebase_uid AND university_id = :university_id'
                );
                $exists->execute(['firebase_uid' => $firebaseUid, 'university_id' => $universityId]);
                if ($exists->fetchColumn()) {
                    return false;
                }
                throw new RuntimeException('Üniversite programı bulunamadı.', 404, $exception);
            }
            throw $exception;
        }
    }

    public function remove(string $firebaseUid, int $universityId): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM favorites WHERE firebase_uid = :firebase_uid AND university_id = :university_id'
        );
        $statement->execute(['firebase_uid' => $firebaseUid, 'university_id' => $universityId]);

        return $statement->rowCount() > 0;
    }
}
