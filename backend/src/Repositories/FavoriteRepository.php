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
            'SELECT u.*, 1 AS is_favorite, f.created_at AS favorited_at '
            . 'FROM favorites f INNER JOIN universities u ON u.id = f.university_id '
            . 'WHERE f.firebase_uid = :firebase_uid ORDER BY f.created_at DESC'
        );
        $statement->execute(['firebase_uid' => $firebaseUid]);

        return $statement->fetchAll();
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
