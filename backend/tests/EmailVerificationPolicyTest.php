<?php

declare(strict_types=1);

use DersRotasi\Middleware\EmailVerificationPolicy;

require dirname(__DIR__) . '/vendor/autoload.php';

function emailVerificationCheck(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

$policy = new EmailVerificationPolicy();

try {
    $policy->assertAllowed(['sign_in_provider' => 'password', 'email_verified' => false]);
    throw new RuntimeException('Doğrulanmamış password hesabı engellenmedi.');
} catch (RuntimeException $exception) {
    emailVerificationCheck($exception->getCode() === 403, 'Doğrulanmamış password hesabı 403 dönmeli.');
}

$policy->assertAllowed(['sign_in_provider' => 'password', 'email_verified' => true]);
$policy->assertAllowed(['sign_in_provider' => 'google.com', 'email_verified' => false]);
$policy->assertAllowed(['sign_in_provider' => 'apple.com', 'email_verified' => false]);

echo "EmailVerificationPolicyTest: OK\n";
