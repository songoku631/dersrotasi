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
            . 'u.base_rank AS ranking_2026, '
            . 'CASE WHEN u25.id IS NOT NULL THEN u25.base_rank ELSE u25_mapped.base_rank END AS ranking_2025, '
            . 'CASE WHEN u24.id IS NOT NULL THEN u24.base_rank ELSE u24_mapped.base_rank END AS ranking_2024, '
            . 'CASE WHEN u23.id IS NOT NULL THEN u23.base_rank ELSE u23_mapped.base_rank END AS ranking_2023, '
            . 'u.base_score AS score_2026, '
            . 'CASE WHEN u25.id IS NOT NULL THEN u25.base_score ELSE u25_mapped.base_score END AS score_2025, '
            . 'CASE WHEN u24.id IS NOT NULL THEN u24.base_score ELSE u24_mapped.base_score END AS score_2024, '
            . 'CASE WHEN u23.id IS NOT NULL THEN u23.base_score ELSE u23_mapped.base_score END AS score_2023, '
            . 'u.quota AS quota_2026, '
            . 'CASE WHEN u25.id IS NOT NULL THEN u25.quota ELSE u25_mapped.quota END AS quota_2025, '
            . 'CASE WHEN u24.id IS NOT NULL THEN u24.quota ELSE u24_mapped.quota END AS quota_2024, '
            . 'CASE WHEN u23.id IS NOT NULL THEN u23.quota ELSE u23_mapped.quota END AS quota_2023 '
            . 'FROM favorites f INNER JOIN universities favorited ON favorited.id = f.university_id '
            . 'INNER JOIN universities u ON u.program_code = favorited.program_code AND u.year = 2026 '
            . 'LEFT JOIN universities u25 ON u25.program_code = u.program_code AND u25.year = 2025 '
            . 'LEFT JOIN program_historical_mappings m25 ON m25.current_program_code = u.program_code '
            . "AND m25.historical_year = 2025 AND m25.confidence = 'high' "
            . "AND m25.verification_status = 'verified' AND u25.id IS NULL "
            . 'LEFT JOIN universities u25_mapped ON u25_mapped.program_code = m25.historical_program_code '
            . 'AND u25_mapped.year = 2025 AND u25.id IS NULL '
            . 'LEFT JOIN universities u24 ON u24.program_code = u.program_code AND u24.year = 2024 '
            . 'LEFT JOIN program_historical_mappings m24 ON m24.current_program_code = u.program_code '
            . "AND m24.historical_year = 2024 AND m24.confidence = 'high' "
            . "AND m24.verification_status = 'verified' AND u24.id IS NULL "
            . 'LEFT JOIN universities u24_mapped ON u24_mapped.program_code = m24.historical_program_code '
            . 'AND u24_mapped.year = 2024 AND u24.id IS NULL '
            . 'LEFT JOIN universities u23 ON u23.program_code = u.program_code AND u23.year = 2023 '
            . 'LEFT JOIN program_historical_mappings m23 ON m23.current_program_code = u.program_code '
            . "AND m23.historical_year = 2023 AND m23.confidence = 'high' "
            . "AND m23.verification_status = 'verified' AND u23.id IS NULL "
            . 'LEFT JOIN universities u23_mapped ON u23_mapped.program_code = m23.historical_program_code '
            . 'AND u23_mapped.year = 2023 AND u23.id IS NULL '
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
