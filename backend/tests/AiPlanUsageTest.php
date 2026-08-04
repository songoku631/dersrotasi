<?php

declare(strict_types=1);

use DersRotasi\AI\PdoAiUsageStore;
use DersRotasi\Config\Env;
use DersRotasi\Database\Connection;
use DersRotasi\Subscriptions\SubscriptionRepository;
use DersRotasi\Subscriptions\PlanCatalog;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

function planCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function planThrows(callable $callback, int $status, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException $exception) {
        planCheck($exception->getCode() === $status, $message . ' için HTTP kodu hatalı.');
        return;
    }
    throw new RuntimeException($message . ' için hata fırlatılmadı.');
}

function temporaryPlanTables(PDO $pdo): void
{
    $pdo->exec("CREATE TEMPORARY TABLE user_subscriptions (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_key_hash CHAR(64) NOT NULL UNIQUE,
      plan_code ENUM('free','premium') NOT NULL,
      status ENUM('active','expired','cancelled') NOT NULL,
      starts_at DATETIME(6) NOT NULL,
      expires_at DATETIME(6) NULL
    ) ENGINE=InnoDB");
    $pdo->exec('CREATE TEMPORARY TABLE ai_daily_usage (
      user_key_hash CHAR(64) NOT NULL, usage_date DATE NOT NULL,
      request_count INT UNSIGNED NOT NULL DEFAULT 0,
      token_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
      PRIMARY KEY (user_key_hash, usage_date)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TEMPORARY TABLE ai_global_daily_usage (
      usage_date DATE NOT NULL PRIMARY KEY,
      token_count BIGINT UNSIGNED NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $pdo->exec("CREATE TEMPORARY TABLE ai_chat_requests (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_key_hash CHAR(64) NOT NULL,
      request_id_hash CHAR(64) NOT NULL,
      usage_date DATE NOT NULL,
      status ENUM('processing','completed','failed') NOT NULL,
      reserved_tokens INT UNSIGNED NOT NULL DEFAULT 0,
      actual_tokens INT UNSIGNED NULL,
      response_json JSON NULL,
      UNIQUE KEY uq_request (user_key_hash, request_id_hash)
    ) ENGINE=InnoDB");
}

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();
$env = new Env($_ENV);
planCheck(
    $env->appEnv() === 'local' && in_array($env->dbHost(), ['127.0.0.1', 'localhost', '::1'], true),
    'Bu entegrasyon testi yalnızca yerel veritabanında çalışabilir.'
);

$pdo = Connection::make($env);
temporaryPlanTables($pdo);
$store = new PdoAiUsageStore($pdo);
$subscriptions = new SubscriptionRepository($pdo);
$freeLimits = ['daily_requests' => 3, 'daily_token_budget' => 6000];
$premiumLimits = ['daily_requests' => 50, 'daily_token_budget' => 60000];

$catalog = new PlanCatalog(new Env([
    'AI_FREE_DAILY_REQUESTS' => '3', 'AI_FREE_DAILY_TOKEN_BUDGET' => '6000',
    'AI_FREE_MAX_MESSAGE_CHARS' => '1200', 'AI_PREMIUM_DAILY_REQUESTS' => '50',
    'AI_PREMIUM_DAILY_TOKEN_BUDGET' => '60000', 'AI_PREMIUM_MAX_MESSAGE_CHARS' => '2500',
    'AI_MAX_OUTPUT_TOKENS' => '500',
]));
planCheck($catalog->limits('free')['max_message_chars'] === 1200, 'Free mesaj sınırı yanlış.');
planCheck($catalog->limits('premium')['daily_requests'] === 50, 'Premium mesaj hakkı yanlış.');

$missingHash = hash('sha256', 'missing-user');
planCheck($subscriptions->activePlan($missingHash)['plan_code'] === 'free', 'Kayıtsız kullanıcı free olmalı.');

$insertSubscription = $pdo->prepare(
    'INSERT INTO user_subscriptions (user_key_hash, plan_code, status, starts_at, expires_at) '
    . 'VALUES (:hash, :plan, :status, UTC_TIMESTAMP(6), :expires_at)'
);
$expiredHash = hash('sha256', 'expired-user');
$insertSubscription->execute([
    'hash' => $expiredHash, 'plan' => 'premium', 'status' => 'active',
    'expires_at' => gmdate('Y-m-d H:i:s', time() - 3600),
]);
planCheck($subscriptions->activePlan($expiredHash)['plan_code'] === 'free', 'Süresi dolmuş Premium free olmalı.');
$cancelledHash = hash('sha256', 'cancelled-user');
$insertSubscription->execute([
    'hash' => $cancelledHash, 'plan' => 'premium', 'status' => 'cancelled', 'expires_at' => null,
]);
planCheck($subscriptions->activePlan($cancelledHash)['plan_code'] === 'free', 'İptal Premium free olmalı.');
$premiumHash = hash('sha256', 'premium-user');
$insertSubscription->execute([
    'hash' => $premiumHash, 'plan' => 'premium', 'status' => 'active', 'expires_at' => null,
]);
planCheck($subscriptions->activePlan($premiumHash)['plan_code'] === 'premium', 'Aktif Premium tanınmalı.');
$futureHash = hash('sha256', 'future-user');
$pdo->prepare(
    'INSERT INTO user_subscriptions (user_key_hash, plan_code, status, starts_at, expires_at) '
    . "VALUES (:hash, 'premium', 'active', TIMESTAMPADD(DAY, 1, UTC_TIMESTAMP(6)), NULL)"
)->execute(['hash' => $futureHash]);
planCheck($subscriptions->activePlan($futureHash)['plan_code'] === 'free', 'Başlamamış Premium free olmalı.');

$freeHash = hash('sha256', 'free-user');
for ($index = 1; $index <= 3; $index++) {
    $requestHash = hash('sha256', 'free-request-' . $index);
    $reserved = $store->reserve($freeHash, $requestHash, 'free', $freeLimits, 100, 200000);
    planCheck($reserved['state'] === 'reserved', 'Free istek rezerve edilemedi.');
    $store->complete($freeHash, $requestHash, 80, ['success' => true, 'answer' => 'ok-' . $index]);
}
planThrows(
    fn () => $store->reserve($freeHash, hash('sha256', 'free-request-4'), 'free', $freeLimits, 100, 200000),
    429,
    'Free dördüncü istek'
);

$duplicateHash = hash('sha256', 'duplicate-user');
$duplicateRequest = hash('sha256', 'same-request');
$store->reserve($duplicateHash, $duplicateRequest, 'free', $freeLimits, 100, 200000);
$expected = ['success' => true, 'answer' => 'cached'];
$store->complete($duplicateHash, $duplicateRequest, 75, $expected);
$duplicate = $store->reserve($duplicateHash, $duplicateRequest, 'free', $freeLimits, 100, 200000);
planCheck(
    $duplicate['state'] === 'completed' && $duplicate['response'] == $expected,
    'Aynı request_id cache dönmeli.'
);

for ($index = 1; $index <= 50; $index++) {
    $requestHash = hash('sha256', 'premium-request-' . $index);
    $store->reserve($premiumHash, $requestHash, 'premium', $premiumLimits, 1, 200000);
    $store->complete($premiumHash, $requestHash, 1, ['success' => true]);
}
planThrows(
    fn () => $store->reserve($premiumHash, hash('sha256', 'premium-request-51'), 'premium', $premiumLimits, 1, 200000),
    429,
    'Premium elli birinci istek'
);

$oldDayHash = hash('sha256', 'day-reset-user');
$pdo->prepare('INSERT INTO ai_daily_usage VALUES (:hash, :day, 3, 6000)')->execute([
    'hash' => $oldDayHash, 'day' => gmdate('Y-m-d', time() - 86400),
]);
planCheck(
    $store->reserve($oldDayHash, hash('sha256', 'new-day-request'), 'free', $freeLimits, 100, 200000)['state'] === 'reserved',
    'Gün değişiminde kota sıfırlanmalı.'
);

planThrows(
    fn () => $store->reserve(hash('sha256', 'token-user'), hash('sha256', 'token-request'), 'free', [
        'daily_requests' => 3, 'daily_token_budget' => 50,
    ], 51, 200000),
    429,
    'Kullanıcı token bütçesi'
);
planThrows(
    fn () => $store->reserve(hash('sha256', 'global-user'), hash('sha256', 'global-request'), 'premium', $premiumLimits, 101, 100),
    429,
    'Global token bütçesi'
);

$brokenPdo = Connection::make($env);
$brokenPdo->exec('CREATE TEMPORARY TABLE ai_daily_usage (unexpected INT)');
planThrows(
    fn () => (new PdoAiUsageStore($brokenPdo))->usage(hash('sha256', 'db-error-user')),
    503,
    'Kota veritabanı hatası fail-closed'
);

echo "AiPlanUsageTest: OK\n";
