<?php

declare(strict_types=1);

namespace DersRotasi\AI;

use PDO;
use RuntimeException;
use Throwable;

final class PdoAiUsageStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function reserve(
        string $userKeyHash,
        string $requestIdHash,
        string $planCode,
        array $limits,
        int $reservedTokens,
        int $globalTokenBudget,
        bool $enforceUserLimits = true
    ): array {
        try {
            $this->pdo->beginTransaction();

            $existing = $this->request($userKeyHash, $requestIdHash, true);
            if ($existing !== null) {
                $this->pdo->commit();
                if ($existing['status'] === 'completed' && is_string($existing['response_json'])) {
                    $response = json_decode($existing['response_json'], true);
                    if (is_array($response)) {
                        return ['state' => 'completed', 'response' => $response];
                    }
                }
                throw new RuntimeException(
                    'Bu istek kimliği daha önce işlendi. Yeni bir mesaj için yeni istek kimliği kullan.',
                    409
                );
            }

            $today = gmdate('Y-m-d');
            $this->pdo->prepare(
                'INSERT INTO ai_daily_usage (user_key_hash, usage_date) VALUES (:user_key_hash, :usage_date) '
                . 'ON DUPLICATE KEY UPDATE user_key_hash = VALUES(user_key_hash)'
            )->execute(['user_key_hash' => $userKeyHash, 'usage_date' => $today]);
            $this->pdo->prepare(
                'INSERT INTO ai_global_daily_usage (usage_date) VALUES (:usage_date) '
                . 'ON DUPLICATE KEY UPDATE usage_date = VALUES(usage_date)'
            )->execute(['usage_date' => $today]);

            $daily = $this->lockedDailyUsage($userKeyHash, $today);
            $global = $this->lockedGlobalUsage($today);
            if ($enforceUserLimits && $daily['request_count'] >= (int) $limits['daily_requests']) {
                throw new RuntimeException(
                    $planCode === 'premium'
                        ? 'Premium günlük AI mesaj hakkın doldu. Yarın tekrar deneyebilirsin.'
                        : 'Ücretsiz günlük AI mesaj hakkın doldu. Premium planı inceleyebilir veya yarın tekrar deneyebilirsin.',
                    429
                );
            }
            if ($enforceUserLimits && $daily['token_count'] + $reservedTokens > (int) $limits['daily_token_budget']) {
                throw new RuntimeException(
                    $planCode === 'premium'
                        ? 'Premium günlük AI token bütçen doldu. Yarın tekrar deneyebilirsin.'
                        : 'Ücretsiz günlük AI token bütçen doldu. Premium planı inceleyebilir veya yarın tekrar deneyebilirsin.',
                    429
                );
            }
            if ($global['token_count'] + $reservedTokens > $globalTokenBudget) {
                throw new RuntimeException('Dersrotası AI günlük genel kapasitesine ulaştı. Lütfen yarın tekrar dene.', 429);
            }

            $this->pdo->prepare(
                'INSERT INTO ai_chat_requests '
                . '(user_key_hash, request_id_hash, usage_date, status, reserved_tokens) '
                . "VALUES (:user_key_hash, :request_id_hash, :usage_date, 'processing', :reserved_tokens)"
            )->execute([
                'user_key_hash' => $userKeyHash,
                'request_id_hash' => $requestIdHash,
                'usage_date' => $today,
                'reserved_tokens' => $reservedTokens,
            ]);
            $this->pdo->prepare(
                'UPDATE ai_daily_usage SET request_count = request_count + 1, '
                . 'token_count = token_count + :tokens '
                . 'WHERE user_key_hash = :user_key_hash AND usage_date = :usage_date'
            )->execute(['tokens' => $reservedTokens, 'user_key_hash' => $userKeyHash, 'usage_date' => $today]);
            $this->pdo->prepare(
                'UPDATE ai_global_daily_usage SET token_count = token_count + :tokens WHERE usage_date = :usage_date'
            )->execute(['tokens' => $reservedTokens, 'usage_date' => $today]);

            $this->pdo->commit();
            return ['state' => 'reserved'];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception instanceof RuntimeException && in_array($exception->getCode(), [409, 429], true)) {
                throw $exception;
            }
            error_log('[AI Usage] reservation database operation failed');
            throw new RuntimeException('AI kullanım hakkın şu anda doğrulanamıyor. Lütfen biraz sonra tekrar dene.', 503, $exception);
        }
    }

    public function complete(string $userKeyHash, string $requestIdHash, int $actualTokens, array $response): array
    {
        try {
            $this->pdo->beginTransaction();
            $request = $this->request($userKeyHash, $requestIdHash, true);
            if ($request === null) {
                throw new RuntimeException('AI kullanım rezervasyonu bulunamadı.');
            }
            if ($request['status'] === 'completed' && is_string($request['response_json'])) {
                $this->pdo->commit();
                $cached = json_decode($request['response_json'], true);
                return is_array($cached) ? $cached : $response;
            }
            if ($request['status'] !== 'processing') {
                throw new RuntimeException('AI kullanım rezervasyonu tamamlanamaz.');
            }

            $actualTokens = max(0, $actualTokens);
            $delta = $actualTokens - (int) $request['reserved_tokens'];
            $this->adjustTokens($userKeyHash, (string) $request['usage_date'], $delta);
            $json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $this->pdo->prepare(
                "UPDATE ai_chat_requests SET status = 'completed', actual_tokens = :actual_tokens, response_json = :response_json "
                . 'WHERE user_key_hash = :user_key_hash AND request_id_hash = :request_id_hash'
            )->execute([
                'actual_tokens' => $actualTokens,
                'response_json' => $json,
                'user_key_hash' => $userKeyHash,
                'request_id_hash' => $requestIdHash,
            ]);
            $this->pdo->commit();
            return $response;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('[AI Usage] completion database operation failed');
            throw new RuntimeException('AI kullanımın şu anda kaydedilemiyor. Lütfen biraz sonra tekrar dene.', 503, $exception);
        }
    }

    public function fail(string $userKeyHash, string $requestIdHash): void
    {
        try {
            $this->pdo->beginTransaction();
            $request = $this->request($userKeyHash, $requestIdHash, true);
            if ($request === null || $request['status'] !== 'processing') {
                $this->pdo->commit();
                return;
            }
            $today = (string) $request['usage_date'];
            $reserved = (int) $request['reserved_tokens'];
            $this->pdo->prepare(
                'UPDATE ai_daily_usage SET request_count = GREATEST(0, request_count - 1), '
                . 'token_count = GREATEST(0, token_count - :tokens) '
                . 'WHERE user_key_hash = :user_key_hash AND usage_date = :usage_date'
            )->execute(['tokens' => $reserved, 'user_key_hash' => $userKeyHash, 'usage_date' => $today]);
            $this->pdo->prepare(
                'UPDATE ai_global_daily_usage SET token_count = GREATEST(0, token_count - :tokens) '
                . 'WHERE usage_date = :usage_date'
            )->execute(['tokens' => $reserved, 'usage_date' => $today]);
            $this->pdo->prepare(
                "UPDATE ai_chat_requests SET status = 'failed' "
                . 'WHERE user_key_hash = :user_key_hash AND request_id_hash = :request_id_hash'
            )->execute(['user_key_hash' => $userKeyHash, 'request_id_hash' => $requestIdHash]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('[AI Usage] failed reservation cleanup failed');
        }
    }

    public function usage(string $userKeyHash): array
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT request_count, token_count FROM ai_daily_usage '
                . 'WHERE user_key_hash = :user_key_hash AND usage_date = :usage_date'
            );
            $statement->execute(['user_key_hash' => $userKeyHash, 'usage_date' => gmdate('Y-m-d')]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return [
                'requests_used' => is_array($row) ? (int) $row['request_count'] : 0,
                'tokens_used' => is_array($row) ? (int) $row['token_count'] : 0,
            ];
        } catch (Throwable $exception) {
            error_log('[AI Usage] usage database read failed');
            throw new RuntimeException('AI kullanım hakkın şu anda doğrulanamıyor. Lütfen biraz sonra tekrar dene.', 503, $exception);
        }
    }

    private function request(string $userKeyHash, string $requestIdHash, bool $lock): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT status, usage_date, reserved_tokens, response_json FROM ai_chat_requests '
            . 'WHERE user_key_hash = :user_key_hash AND request_id_hash = :request_id_hash'
            . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute(['user_key_hash' => $userKeyHash, 'request_id_hash' => $requestIdHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function lockedDailyUsage(string $userKeyHash, string $today): array
    {
        $statement = $this->pdo->prepare(
            'SELECT request_count, token_count FROM ai_daily_usage '
            . 'WHERE user_key_hash = :user_key_hash AND usage_date = :usage_date FOR UPDATE'
        );
        $statement->execute(['user_key_hash' => $userKeyHash, 'usage_date' => $today]);
        return array_map('intval', $statement->fetch(PDO::FETCH_ASSOC) ?: []);
    }

    private function lockedGlobalUsage(string $today): array
    {
        $statement = $this->pdo->prepare(
            'SELECT token_count FROM ai_global_daily_usage WHERE usage_date = :usage_date FOR UPDATE'
        );
        $statement->execute(['usage_date' => $today]);
        return array_map('intval', $statement->fetch(PDO::FETCH_ASSOC) ?: []);
    }

    private function adjustTokens(string $userKeyHash, string $usageDate, int $delta): void
    {
        $daily = $this->pdo->prepare(
            'UPDATE ai_daily_usage SET token_count = GREATEST(0, token_count + :delta) '
            . 'WHERE user_key_hash = :user_key_hash AND usage_date = :usage_date'
        );
        $daily->execute(['delta' => $delta, 'user_key_hash' => $userKeyHash, 'usage_date' => $usageDate]);
        $global = $this->pdo->prepare(
            'UPDATE ai_global_daily_usage SET token_count = GREATEST(0, token_count + :delta) '
            . 'WHERE usage_date = :usage_date'
        );
        $global->execute(['delta' => $delta, 'usage_date' => $usageDate]);
    }
}
