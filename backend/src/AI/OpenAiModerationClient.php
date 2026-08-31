<?php

declare(strict_types=1);

namespace DersRotasi\AI;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;
use Throwable;

final class OpenAiModerationClient implements AiContentModerator, ImageContentModerator
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeoutSeconds,
        private readonly ?string $sslCaBundle = null,
        private readonly ?ClientInterface $httpClient = null
    ) {
    }

    public function inspect(string $content): array
    {
        return $this->request($content);
    }

    public function inspectImage(string $mimeType, string $bytes): array
    {
        if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true) || $bytes === '') {
            throw new RuntimeException('AI güvenlik kontrolü geçici olarak kullanılamıyor.', 503);
        }
        return $this->request([[
            'type' => 'image_url',
            'image_url' => ['url' => 'data:' . $mimeType . ';base64,' . base64_encode($bytes)],
        ]]);
    }

    private function request(string|array $input): array
    {
        if (trim($this->apiKey) === '') {
            throw new RuntimeException('AI güvenlik kontrolü geçici olarak kullanılamıyor.', 503);
        }

        try {
            $client = $this->httpClient ?? new Client([
                'base_uri' => 'https://api.openai.com',
                'connect_timeout' => min(5, $this->timeoutSeconds),
                'timeout' => $this->timeoutSeconds,
                'verify' => $this->sslCaBundle ?? true,
            ]);
            $response = $client->post('/v1/moderations', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'input' => $input,
                ],
            ]);
        } catch (GuzzleException $exception) {
            error_log('[AI_SECURITY] event=moderation_unavailable');
            throw new RuntimeException(
                'AI güvenlik kontrolü geçici olarak kullanılamıyor.',
                503,
                $exception
            );
        } catch (Throwable $exception) {
            error_log('[AI_SECURITY] event=moderation_unexpected_error');
            throw new RuntimeException(
                'AI güvenlik kontrolü geçici olarak kullanılamıyor.',
                503,
                $exception
            );
        }

        $payload = json_decode((string) $response->getBody(), true);
        $result = is_array($payload['results'][0] ?? null) ? $payload['results'][0] : null;
        if ($result === null || !is_bool($result['flagged'] ?? null)) {
            error_log('[AI_SECURITY] event=moderation_invalid_response');
            throw new RuntimeException('AI güvenlik kontrolü geçici olarak kullanılamıyor.', 503);
        }

        $categories = [];
        if (is_array($result['categories'] ?? null)) {
            foreach ($result['categories'] as $category => $flagged) {
                if (is_string($category) && $flagged === true) {
                    $categories[] = $category;
                }
            }
        }

        return ['flagged' => $result['flagged'], 'categories' => $categories];
    }
}
