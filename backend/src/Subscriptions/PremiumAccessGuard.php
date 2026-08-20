<?php

declare(strict_types=1);

namespace DersRotasi\Subscriptions;

use RuntimeException;

final class PremiumAccessGuard
{
    public function assertAllowed(array $plan, string $message = 'Bu özellik Dersrotası Premium’a özel.'): void
    {
        if (($plan['is_premium'] ?? false) === true || ($plan['is_admin'] ?? false) === true) {
            return;
        }

        throw new RuntimeException($message, 403);
    }
}
