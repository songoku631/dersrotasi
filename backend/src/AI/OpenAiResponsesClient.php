<?php

declare(strict_types=1);

namespace DersRotasi\AI;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;
use Throwable;

final class OpenAiResponsesClient implements OpenAiClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeoutSeconds,
        private readonly ?string $sslCaBundle = null,
        private readonly ?ClientInterface $httpClient = null,
        private readonly int $maxOutputTokens = 500
    ) {
    }

    public function respond(string $instructions, array $input, string $safetyIdentifier): array
    {
        if (trim($this->apiKey) === '') {
            throw new RuntimeException(
                'Dersrotası AI henüz yapılandırılmadı: OPENAI_API_KEY eksik.',
                503
            );
        }

        try {
            $client = $this->httpClient ?? new Client([
                'base_uri' => 'https://api.openai.com',
                'connect_timeout' => min(5, $this->timeoutSeconds),
                'timeout' => $this->timeoutSeconds,
                'verify' => $this->sslCaBundle ?? true,
            ]);
            $response = $client->post('/v1/responses', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'instructions' => $instructions,
                    'input' => $input,
                    'reasoning' => ['effort' => 'low'],
                    'text' => ['verbosity' => 'medium'],
                    'max_output_tokens' => $this->maxOutputTokens,
                    'store' => false,
                    'safety_identifier' => $safetyIdentifier,
                ],
            ]);
        } catch (ConnectException $exception) {
            throw new RuntimeException(
                'Dersrotası AI zaman aşımına uğradı. Lütfen tekrar dene.',
                504,
                $exception
            );
        } catch (RequestException $exception) {
            $status = $exception->getResponse()?->getStatusCode();
            if ($status === 408 || $status === 504) {
                throw new RuntimeException(
                    'Dersrotası AI zaman aşımına uğradı. Lütfen tekrar dene.',
                    504,
                    $exception
                );
            }
            error_log('[OpenAI] Responses API request failed with status ' . ($status ?? 'network'));
            throw new RuntimeException(
                'Dersrotası AI şu anda yanıt veremiyor. Lütfen daha sonra tekrar dene.',
                502,
                $exception
            );
        } catch (GuzzleException $exception) {
            error_log('[OpenAI] Responses API transport error');
            throw new RuntimeException(
                'Dersrotası AI şu anda yanıt veremiyor. Lütfen daha sonra tekrar dene.',
                502,
                $exception
            );
        } catch (Throwable $exception) {
            error_log('[OpenAI] Unexpected Responses API client error');
            throw new RuntimeException(
                'Dersrotası AI şu anda yanıt veremiyor. Lütfen daha sonra tekrar dene.',
                502,
                $exception
            );
        }

        $payload = json_decode((string) $response->getBody(), true);
        if (!is_array($payload)) {
            throw new RuntimeException('Dersrotası AI geçersiz bir yanıt döndürdü.', 502);
        }

        $outputs = $payload['output'] ?? null;
        if (!is_array($outputs)) {
            throw new RuntimeException('Dersrotası AI geçersiz bir yanıt döndürdü.', 502);
        }

        $answerParts = [];
        foreach ($outputs as $output) {
            if (!is_array($output) || ($output['type'] ?? '') !== 'message') {
                continue;
            }
            $contents = $output['content'] ?? null;
            if (!is_array($contents)) {
                continue;
            }
            foreach ($contents as $content) {
                if (is_array($content) && ($content['type'] ?? '') === 'output_text') {
                    $textValue = $content['text'] ?? null;
                    if (!is_string($textValue)) {
                        continue;
                    }
                    $text = trim($textValue);
                    if ($text !== '') {
                        $answerParts[] = $text;
                    }
                }
            }
        }
        $answer = trim(implode("\n", $answerParts));
        if ($answer === '') {
            throw new RuntimeException('Dersrotası AI boş bir yanıt döndürdü.', 502);
        }

        $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];
        $responseId = is_string($payload['id'] ?? null) ? $payload['id'] : '';
        $responseModel = is_string($payload['model'] ?? null) ? $payload['model'] : $this->model;

        return [
            'answer' => $answer,
            'meta' => [
                'response_id' => $responseId,
                'model' => $responseModel,
                'usage' => [
                    'input_tokens' => $this->nonNegativeInt($usage['input_tokens'] ?? null),
                    'output_tokens' => $this->nonNegativeInt($usage['output_tokens'] ?? null),
                    'total_tokens' => $this->nonNegativeInt($usage['total_tokens'] ?? null),
                ],
            ],
        ];
    }

    private function nonNegativeInt(mixed $value): int
    {
        return is_int($value) && $value >= 0 ? $value : 0;
    }
}
