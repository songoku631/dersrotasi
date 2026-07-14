<?php

declare(strict_types=1);

namespace DersRotasi\Yokatlas;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

final class YokatlasClient
{
    private const API_PATH = '/api/tercih-kilavuz/search';
    private const RETRYABLE_STATUSES = [500, 502, 503];
    private float $lastRequestAt = 0.0;

    public function __construct(
        string $userAgent,
        private readonly int $delayMs = 1000,
        ?string $caBundle = null
    ) {
        if ($delayMs < 1000) {
            throw new RuntimeException('YÖK Atlas istek aralığı en az 1000 ms olmalıdır.');
        }
        $options = [
            'base_uri' => 'https://yokatlas.yok.gov.tr',
            'headers' => [
                'User-Agent' => $userAgent,
                'Accept' => 'application/json, text/plain;q=0.9',
            ],
            'connect_timeout' => 10,
            'timeout' => 30,
            'http_errors' => false,
            'allow_redirects' => false,
        ];
        if ($caBundle !== null) {
            $options['verify'] = $caBundle;
        }
        $this->client = new Client($options);
    }

    private readonly Client $client;

    public function checkRobots(): array
    {
        $response = $this->request('GET', '/robots.txt', [], false);
        if ($response['status'] !== 200) {
            throw new YokatlasStopException('robots.txt güvenli biçimde kontrol edilemedi.', $response['status']);
        }
        $body = $response['body'];
        $hasDirectives = preg_match('/^\s*User-agent\s*:/mi', $body) === 1;
        if (!$hasDirectives) {
            return ['status' => 'no_valid_directives', 'allowed' => true];
        }

        $groupAgents = [];
        $groupHasRules = false;
        $matchingRules = [];
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? $line);
            if (preg_match('/^User-agent\s*:\s*(.+)$/i', $line, $match)) {
                if ($groupHasRules) {
                    $groupAgents = [];
                    $groupHasRules = false;
                }
                $agent = trim($match[1]);
                $groupAgents[] = $agent;
                continue;
            }
            if (preg_match('/^(Allow|Disallow)\s*:\s*(.*)$/i', $line, $match)) {
                $groupHasRules = true;
                $applies = in_array('*', $groupAgents, true)
                    || array_filter($groupAgents, static fn (string $agent): bool =>
                        stripos('DersRotasiDataTool', $agent) !== false) !== [];
                $path = trim($match[2]);
                if ($applies && $path !== '' && $this->robotsRuleMatches(self::API_PATH, $path)) {
                    $matchingRules[] = ['type' => strtolower($match[1]), 'path' => $path];
                }
            }
        }
        usort($matchingRules, static fn (array $left, array $right): int =>
            strlen($right['path']) <=> strlen($left['path']));
        if (($matchingRules[0]['type'] ?? null) === 'disallow') {
            throw new YokatlasStopException('robots.txt resmi veri kaynağına erişime izin vermiyor.');
        }
        return ['status' => 'checked', 'allowed' => true];
    }

    public function fetchProgram(string $programCode, int $year): array
    {
        return $this->request('POST', self::API_PATH, [
            'json' => [
                'filters' => ['yil' => $year, 'kilavuzKodu' => $programCode],
                'page' => 0,
                'size' => 10,
            ],
        ]);
    }

    public function fetchPage(int $year, int $page, int $size): array
    {
        if ($page < 0 || $size < 1 || $size > 100) {
            throw new RuntimeException('YÖK Atlas sayfa parametreleri güvenli sınırların dışında.');
        }
        return $this->request('POST', self::API_PATH, [
            'json' => [
                'filters' => ['yil' => $year],
                'page' => $page,
                'size' => $size,
            ],
        ]);
    }

    private function request(string $method, string $path, array $options, bool $retry = true): array
    {
        $maxAttempts = $retry ? 3 : 1;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $this->throttle();
            try {
                $response = $this->client->request($method, $path, $options);
                $this->lastRequestAt = microtime(true);
                $status = $response->getStatusCode();
                if ($status === 429) {
                    $retryAfter = trim($response->getHeaderLine('Retry-After'));
                    $message = $retryAfter !== ''
                        ? "HTTP 429 alındı; Retry-After={$retryAfter}. İşlem durduruldu."
                        : 'HTTP 429 alındı; yeni istek yapılmadan işlem durduruldu.';
                    throw new YokatlasStopException($message, 429);
                }
                if (in_array($status, self::RETRYABLE_STATUSES, true)) {
                    if ($attempt === $maxAttempts) {
                        throw new YokatlasStopException("HTTP {$status} üç denemede düzelmedi; işlem durduruldu.", $status);
                    }
                    $this->backoff($attempt);
                    continue;
                }
                return [
                    'status' => $status,
                    'body' => (string) $response->getBody(),
                    'attempts' => $attempt,
                ];
            } catch (YokatlasStopException $exception) {
                throw $exception;
            } catch (GuzzleException $exception) {
                $this->lastRequestAt = microtime(true);
                if ($attempt < $maxAttempts) {
                    $this->backoff($attempt);
                    continue;
                }
            }
        }

        throw new YokatlasStopException('Bağlantı hatası üç denemede düzelmedi; işlem durduruldu.', null);
    }

    private function throttle(): void
    {
        if ($this->lastRequestAt <= 0) {
            return;
        }
        $remaining = ($this->delayMs / 1000) - (microtime(true) - $this->lastRequestAt);
        if ($remaining > 0) {
            usleep((int) ceil($remaining * 1_000_000));
        }
    }

    private function backoff(int $attempt): void
    {
        $seconds = min(8, 2 ** $attempt);
        sleep($seconds);
    }

    private function robotsRuleMatches(string $path, string $rule): bool
    {
        $endAnchored = str_ends_with($rule, '$');
        if ($endAnchored) {
            $rule = substr($rule, 0, -1);
        }
        $pattern = str_replace('\\*', '.*', preg_quote($rule, '#'));
        return preg_match('#^' . $pattern . ($endAnchored ? '$' : '') . '#', $path) === 1;
    }
}
