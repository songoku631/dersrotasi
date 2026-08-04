<?php

declare(strict_types=1);

use DersRotasi\Config\Env;
use DersRotasi\Database\Connection;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$env = new Env($_ENV);
if ($env->appEnv() !== 'local' || !in_array($env->dbHost(), ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Bu araç yalnızca yerel veritabanında çalışır.\n");
    exit(1);
}

function prompt(string $label): string
{
    fwrite(STDOUT, $label);
    $value = fgets(STDIN);
    return $value === false ? '' : trim($value);
}

$uid = prompt('Firebase UID: ');
if ($uid === '' || strlen($uid) > 256 || preg_match('/\s/', $uid)) {
    fwrite(STDERR, "Firebase UID geçersiz.\n");
    exit(1);
}

$plan = strtolower(prompt('Plan (free/premium): '));
if (!in_array($plan, ['free', 'premium'], true)) {
    fwrite(STDERR, "Plan yalnızca free veya premium olabilir.\n");
    exit(1);
}

$expiresAt = null;
if ($plan === 'premium') {
    $expiry = strtolower(prompt('Bitiş (kalıcı veya YYYY-MM-DD): '));
    if (!in_array($expiry, ['kalıcı', 'kalici', 'permanent'], true)) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $expiry, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            fwrite(STDERR, "Bitiş tarihi YYYY-MM-DD biçiminde olmalı.\n");
            exit(1);
        }
        $expiresAt = $date->modify('+1 day')->format('Y-m-d H:i:s.u');
        if (strtotime($expiresAt) <= time()) {
            fwrite(STDERR, "Bitiş tarihi gelecekte olmalı.\n");
            exit(1);
        }
    }
}

$pdo = Connection::make($env);
$statement = $pdo->prepare(
    'INSERT INTO user_subscriptions '
    . '(user_key_hash, plan_code, status, starts_at, expires_at) '
    . "VALUES (:user_key_hash, :plan_code, 'active', UTC_TIMESTAMP(6), :expires_at) "
    . 'ON DUPLICATE KEY UPDATE plan_code = VALUES(plan_code), status = VALUES(status), '
    . 'starts_at = UTC_TIMESTAMP(6), expires_at = VALUES(expires_at)'
);
$statement->execute([
    'user_key_hash' => hash('sha256', $uid),
    'plan_code' => $plan,
    'expires_at' => $expiresAt,
]);

unset($uid);
fwrite(STDOUT, "Plan yerel veritabanında güvenle güncellendi.\n");
