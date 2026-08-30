<?php

declare(strict_types=1);

namespace DersRotasi\AI;

use RuntimeException;

final class AiResponseEnvelope
{
    public function seal(array $response, int $conversationId, string $message): array
    {
        $response['_request_binding'] = [
            'conversation_id' => $conversationId,
            'message_hash' => hash('sha256', $message),
        ];

        return $response;
    }

    /** @return array{response: array, storage_message: string} */
    public function open(array $response, int $conversationId, string $message): array
    {
        $binding = $response['_request_binding'] ?? null;
        $matches = is_array($binding)
            && ($binding['conversation_id'] ?? null) === $conversationId
            && is_string($binding['message_hash'] ?? null)
            && hash_equals($binding['message_hash'], hash('sha256', $message));
        if (!$matches) {
            throw new RuntimeException(
                'Bu istek kimliği farklı bir sohbet veya mesaj için kullanılamaz.',
                409
            );
        }

        $storageMessage = $response['_storage_message'] ?? $message;
        if (!is_string($storageMessage) || trim($storageMessage) === '') {
            throw new RuntimeException('AI sohbet kaydı güvenli şekilde hazırlanamadı.', 500);
        }
        unset($response['_request_binding'], $response['_storage_message']);

        return ['response' => $response, 'storage_message' => $storageMessage];
    }
}
