<?php

declare(strict_types=1);

namespace DersRotasi\Repositories;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class PreferenceRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function all(string $firebaseUid): array
    {
        $statement = $this->pdo->prepare(
            'SELECT u.*, p.position, p.note, p.created_at AS preference_created_at, '
            . 'p.updated_at AS preference_updated_at FROM preference_items p '
            . 'INNER JOIN universities u ON u.id = p.university_id '
            . 'WHERE p.firebase_uid = :firebase_uid ORDER BY p.position ASC, p.id ASC'
        );
        $statement->execute(['firebase_uid' => $firebaseUid]);
        return $statement->fetchAll();
    }

    public function add(string $firebaseUid, int $universityId, string $note): bool
    {
        $note = $this->validatedNote($note);
        try {
            $this->pdo->beginTransaction();
            $position = $this->nextPosition($firebaseUid);
            $statement = $this->pdo->prepare(
                'INSERT INTO preference_items (firebase_uid, university_id, position, note) '
                . 'VALUES (:firebase_uid, :university_id, :position, :note)'
            );
            $statement->execute([
                'firebase_uid' => $firebaseUid, 'university_id' => $universityId,
                'position' => $position, 'note' => $note,
            ]);
            $this->pdo->commit();
            return true;
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception->getCode() === '23000') {
                $exists = $this->pdo->prepare(
                    'SELECT 1 FROM preference_items WHERE firebase_uid = :firebase_uid AND university_id = :university_id'
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

    public function updateNote(string $firebaseUid, int $universityId, string $note): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE preference_items SET note = :note WHERE firebase_uid = :firebase_uid AND university_id = :university_id'
        );
        $statement->execute([
            'note' => $this->validatedNote($note),
            'firebase_uid' => $firebaseUid,
            'university_id' => $universityId,
        ]);
        if ($statement->rowCount() > 0) {
            return true;
        }

        $exists = $this->pdo->prepare(
            'SELECT 1 FROM preference_items WHERE firebase_uid = :firebase_uid AND university_id = :university_id'
        );
        $exists->execute(['firebase_uid' => $firebaseUid, 'university_id' => $universityId]);
        return (bool) $exists->fetchColumn();
    }

    public function reorder(string $firebaseUid, array $items): void
    {
        if ($items === []) {
            throw new RuntimeException('Tercih sıralaması boş olamaz.', 422);
        }
        $ids = [];
        $positions = [];
        foreach ($items as $item) {
            $id = filter_var($item['university_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $position = filter_var($item['position'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || $position === false || isset($ids[$id]) || isset($positions[$position])) {
                throw new RuntimeException('Tercih sıralaması geçersiz.', 422);
            }
            $ids[$id] = true;
            $positions[$position] = true;
        }

        try {
            $this->pdo->beginTransaction();
            $owned = $this->pdo->prepare('SELECT university_id FROM preference_items WHERE firebase_uid = :firebase_uid FOR UPDATE');
            $owned->execute(['firebase_uid' => $firebaseUid]);
            $ownedIds = array_map('intval', $owned->fetchAll(PDO::FETCH_COLUMN));
            sort($ownedIds);
            $submittedIds = array_map('intval', array_keys($ids));
            sort($submittedIds);
            if ($ownedIds !== $submittedIds) {
                throw new RuntimeException('Yalnızca kendi tercih listenizi sıralayabilirsiniz.', 403);
            }

            $update = $this->pdo->prepare(
                'UPDATE preference_items SET position = :position WHERE firebase_uid = :firebase_uid AND university_id = :university_id'
            );
            foreach ($items as $item) {
                $update->execute([
                    'position' => (int) $item['position'], 'firebase_uid' => $firebaseUid,
                    'university_id' => (int) $item['university_id'],
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function remove(string $firebaseUid, int $universityId): bool
    {
        try {
            $this->pdo->beginTransaction();
            $delete = $this->pdo->prepare(
                'DELETE FROM preference_items WHERE firebase_uid = :firebase_uid AND university_id = :university_id'
            );
            $delete->execute(['firebase_uid' => $firebaseUid, 'university_id' => $universityId]);
            if ($delete->rowCount() === 0) {
                $this->pdo->rollBack();
                return false;
            }
            $select = $this->pdo->prepare(
                'SELECT university_id FROM preference_items WHERE firebase_uid = :firebase_uid ORDER BY position, id FOR UPDATE'
            );
            $select->execute(['firebase_uid' => $firebaseUid]);
            $update = $this->pdo->prepare(
                'UPDATE preference_items SET position = :position WHERE firebase_uid = :firebase_uid AND university_id = :university_id'
            );
            foreach ($select->fetchAll(PDO::FETCH_COLUMN) as $index => $id) {
                $update->execute(['position' => $index + 1, 'firebase_uid' => $firebaseUid, 'university_id' => $id]);
            }
            $this->pdo->commit();
            return true;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function nextPosition(string $firebaseUid): int
    {
        $statement = $this->pdo->prepare(
            'SELECT position FROM preference_items WHERE firebase_uid = :firebase_uid '
            . 'ORDER BY position DESC LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['firebase_uid' => $firebaseUid]);
        $position = $statement->fetchColumn();
        return $position === false ? 1 : (int) $position + 1;
    }

    private function validatedNote(string $note): string
    {
        $note = trim($note);
        $length = preg_match_all('/./us', $note, $matches);
        if ($length === false) {
            throw new RuntimeException('Tercih notu geçerli UTF-8 olmalıdır.', 422);
        }
        if ($length > 1000) {
            throw new RuntimeException('Tercih notu en fazla 1000 karakter olabilir.', 422);
        }
        return $note;
    }
}
