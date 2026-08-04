<?php

declare(strict_types=1);

namespace DersRotasi\AI;

use RuntimeException;

final class AiChatValidator
{
    public const MAX_MESSAGE_LENGTH = 2500;
    public const MAX_HISTORY_ITEMS = 10;
    public const MAX_HISTORY_CONTENT_LENGTH = 2000;
    public const MAX_HISTORY_TOTAL_LENGTH = 8000;

    public function validate(array $body, ?int $maxMessageLength = null): array
    {
        $messageLimit = $maxMessageLength ?? self::MAX_MESSAGE_LENGTH;
        $messageValue = $body['message'] ?? null;
        if (!is_string($messageValue)) {
            throw new RuntimeException('Mesaj metin olmalıdır.', 422);
        }
        $message = trim($messageValue);
        if ($message === '') {
            throw new RuntimeException('Mesaj boş olamaz.', 422);
        }
        if ($this->length($message) > $messageLimit) {
            throw new RuntimeException(
                'Mesaj en fazla ' . $messageLimit . ' karakter olabilir.',
                422
            );
        }

        $history = $body['history'] ?? [];
        if (!is_array($history) || !array_is_list($history)) {
            throw new RuntimeException('Sohbet geçmişi geçersiz.', 422);
        }
        if (count($history) > self::MAX_HISTORY_ITEMS) {
            throw new RuntimeException(
                'Sohbet geçmişi en fazla ' . self::MAX_HISTORY_ITEMS . ' mesaj içerebilir.',
                422
            );
        }

        $validatedHistory = [];
        $totalLength = 0;
        foreach ($history as $item) {
            if (!is_array($item)) {
                throw new RuntimeException('Sohbet geçmişindeki bir mesaj geçersiz.', 422);
            }
            $roleValue = $item['role'] ?? null;
            $contentValue = $item['content'] ?? null;
            if (!is_string($roleValue) || !is_string($contentValue)) {
                throw new RuntimeException('Sohbet geçmişindeki rol veya içerik geçersiz.', 422);
            }
            $role = $roleValue;
            $content = trim($contentValue);
            if (!in_array($role, ['user', 'assistant'], true) || $content === '') {
                throw new RuntimeException('Sohbet geçmişindeki rol veya içerik geçersiz.', 422);
            }
            $contentLength = $this->length($content);
            if ($contentLength > self::MAX_HISTORY_CONTENT_LENGTH) {
                throw new RuntimeException('Sohbet geçmişindeki bir mesaj çok uzun.', 422);
            }
            $totalLength += $contentLength;
            if ($totalLength > self::MAX_HISTORY_TOTAL_LENGTH) {
                throw new RuntimeException('Sohbet geçmişinin toplam uzunluğu sınırı aşıyor.', 422);
            }
            $validatedHistory[] = ['role' => $role, 'content' => $content];
        }

        return ['message' => $message, 'history' => $validatedHistory];
    }

    private function length(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }
        $count = preg_match_all('/./us', $value);
        return $count === false ? strlen($value) : $count;
    }
}
