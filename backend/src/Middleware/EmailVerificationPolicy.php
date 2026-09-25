<?php

declare(strict_types=1);

namespace DersRotasi\Middleware;

use RuntimeException;

final class EmailVerificationPolicy
{
    /** @param array{sign_in_provider?: mixed, email_verified?: mixed} $identity */
    public function assertAllowed(array $identity): void
    {
        if (($identity['sign_in_provider'] ?? null) !== 'password') {
            return;
        }

        if (($identity['email_verified'] ?? false) === true) {
            return;
        }

        error_log('[Firebase Auth] unverified_password_email');
        throw new RuntimeException('E-posta adresini doğrulaman gerekiyor.', 403);
    }
}
