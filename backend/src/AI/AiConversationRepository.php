<?php

declare(strict_types=1);

namespace DersRotasi\AI;

use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class AiConversationRepository
{
    private const DEFAULT_TITLE = 'Yeni Sohbet';
    private const TITLE_MAX_LENGTH = 64;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(string $userKeyHash): array
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO ai_conversations (user_key_hash, title, created_at, updated_at) '
            . 'VALUES (:user_key_hash, :title, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))'
        );
        $statement->execute([
            'user_key_hash' => $userKeyHash,
            'title' => self::DEFAULT_TITLE,
        ]);

        return $this->summary($userKeyHash, (int) $this->pdo->lastInsertId());
    }

    public function all(string $userKeyHash): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.id, c.title, c.last_message_at, c.created_at, c.updated_at, '
            . '(SELECT COUNT(*) FROM ai_conversation_messages m WHERE m.conversation_id = c.id) AS message_count '
            . 'FROM ai_conversations c WHERE c.user_key_hash = :user_key_hash '
            . 'ORDER BY c.updated_at DESC, c.id DESC'
        );
        $statement->execute(['user_key_hash' => $userKeyHash]);

        return array_map([$this, 'presentConversation'], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(string $userKeyHash, int $conversationId, string $firebaseUid): array
    {
        $this->ownedConversation($userKeyHash, $conversationId);
        $touch = $this->pdo->prepare(
            'UPDATE ai_conversations SET updated_at = '
            . 'TIMESTAMPADD(MICROSECOND, 1, GREATEST(updated_at, UTC_TIMESTAMP(6))) '
            . 'WHERE id = :id AND user_key_hash = :user_key_hash'
        );
        $touch->execute(['id' => $conversationId, 'user_key_hash' => $userKeyHash]);
        $favoriteIds = $this->favoriteIds($firebaseUid);
        $statement = $this->pdo->prepare(
            'SELECT id, role, content, structured_data, created_at '
            . 'FROM ai_conversation_messages WHERE conversation_id = :conversation_id ORDER BY id ASC'
        );
        $statement->execute(['conversation_id' => $conversationId]);
        $messages = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $programs = $this->programs($row['structured_data'] ?? null);
            foreach ($programs as &$program) {
                $programId = isset($program['id']) ? (int) $program['id'] : 0;
                $isFavorite = $programId > 0 && isset($favoriteIds[$programId]);
                $program['is_favorite'] = $isFavorite ? 1 : 0;
                $program['favorite_id'] = $isFavorite ? $programId : null;
            }
            unset($program);
            $messages[] = [
                'id' => (int) $row['id'],
                'role' => (string) $row['role'],
                'content' => (string) $row['content'],
                'programs' => $programs,
                'created_at' => $this->timestamp($row['created_at'] ?? null),
            ];
        }

        return [
            'conversation' => $this->summary($userKeyHash, $conversationId),
            'messages' => $messages,
        ];
    }

    public function assertOwned(string $userKeyHash, int $conversationId): void
    {
        $this->ownedConversation($userKeyHash, $conversationId);
    }

    public function appendExchange(
        string $userKeyHash,
        int $conversationId,
        string $requestIdHash,
        string $userMessage,
        array $response
    ): array {
        $answer = $response['answer'] ?? null;
        if (!is_string($answer) || trim($answer) === '') {
            throw new RuntimeException('Kaydedilecek AI yanıtı geçersiz.');
        }
        try {
            $structuredData = json_encode(
                ['programs' => is_array($response['programs'] ?? null) ? $response['programs'] : []],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('AI kart sonuçları kaydedilemedi.', 500, $exception);
        }

        try {
            $this->pdo->beginTransaction();
            $conversation = $this->ownedConversation($userKeyHash, $conversationId, true);
            $firstUserMessage = $this->userMessageCount($conversationId) === 0;
            $insert = $this->pdo->prepare(
                'INSERT INTO ai_conversation_messages '
                . '(conversation_id, request_id_hash, role, content, structured_data, created_at) '
                . 'VALUES (:conversation_id, :request_id_hash, :role, :content, :structured_data, UTC_TIMESTAMP(6)) '
                . 'ON DUPLICATE KEY UPDATE id = id'
            );
            $insert->execute([
                'conversation_id' => $conversationId,
                'request_id_hash' => $requestIdHash,
                'role' => 'user',
                'content' => $userMessage,
                'structured_data' => null,
            ]);
            $userInserted = $insert->rowCount() > 0;
            $insert->execute([
                'conversation_id' => $conversationId,
                'request_id_hash' => $requestIdHash,
                'role' => 'assistant',
                'content' => $answer,
                'structured_data' => $structuredData,
            ]);
            $assistantInserted = $insert->rowCount() > 0;

            if ($userInserted || $assistantInserted) {
                $title = $firstUserMessage ? $this->title($userMessage) : (string) $conversation['title'];
                $update = $this->pdo->prepare(
                    'UPDATE ai_conversations SET title = :title, last_message_at = UTC_TIMESTAMP(6), '
                    . 'updated_at = UTC_TIMESTAMP(6) '
                    . 'WHERE id = :id AND user_key_hash = :user_key_hash'
                );
                $update->execute([
                    'title' => $title,
                    'id' => $conversationId,
                    'user_key_hash' => $userKeyHash,
                ]);
            }
            $this->pdo->commit();

            return $this->summary($userKeyHash, $conversationId);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function summary(string $userKeyHash, int $conversationId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.id, c.title, c.last_message_at, c.created_at, c.updated_at, '
            . '(SELECT COUNT(*) FROM ai_conversation_messages m WHERE m.conversation_id = c.id) AS message_count '
            . 'FROM ai_conversations c WHERE c.id = :id AND c.user_key_hash = :user_key_hash LIMIT 1'
        );
        $statement->execute(['id' => $conversationId, 'user_key_hash' => $userKeyHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Sohbet bulunamadı.', 404);
        }

        return $this->presentConversation($row);
    }

    private function ownedConversation(string $userKeyHash, int $conversationId, bool $lock = false): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, title, last_message_at, created_at, updated_at FROM ai_conversations '
            . 'WHERE id = :id AND user_key_hash = :user_key_hash LIMIT 1'
            . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute(['id' => $conversationId, 'user_key_hash' => $userKeyHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Sohbet bulunamadı.', 404);
        }

        return $row;
    }

    private function userMessageCount(int $conversationId): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM ai_conversation_messages WHERE conversation_id = :conversation_id AND role = 'user'"
        );
        $statement->execute(['conversation_id' => $conversationId]);

        return (int) $statement->fetchColumn();
    }

    private function favoriteIds(string $firebaseUid): array
    {
        $statement = $this->pdo->prepare('SELECT university_id FROM favorites WHERE firebase_uid = :firebase_uid');
        $statement->execute(['firebase_uid' => $firebaseUid]);

        return array_fill_keys(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    private function programs(mixed $structuredData): array
    {
        if (!is_string($structuredData) || $structuredData === '') {
            return [];
        }
        $decoded = json_decode($structuredData, true);

        return is_array($decoded['programs'] ?? null) ? array_values($decoded['programs']) : [];
    }

    private function title(string $message): string
    {
        $title = preg_replace('/\s+/u', ' ', trim($message)) ?? trim($message);
        $title = preg_replace('/[\p{C}]+/u', '', $title) ?? $title;
        if ($this->length($title) <= self::TITLE_MAX_LENGTH) {
            return $title !== '' ? $title : self::DEFAULT_TITLE;
        }

        return rtrim($this->slice($title, self::TITLE_MAX_LENGTH - 1)) . '…';
    }

    private function presentConversation(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'message_count' => isset($row['message_count']) ? (int) $row['message_count'] : 0,
            'last_message_at' => $this->timestamp($row['last_message_at'] ?? null),
            'created_at' => $this->timestamp($row['created_at'] ?? null),
            'updated_at' => $this->timestamp($row['updated_at'] ?? null),
        ];
    }

    private function timestamp(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $timestamp = strtotime($value . ' UTC');

        return $timestamp === false ? null : gmdate('c', $timestamp);
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function slice(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }
}
