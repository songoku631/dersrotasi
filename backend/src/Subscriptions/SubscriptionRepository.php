<?php

declare(strict_types=1);

namespace DersRotasi\Subscriptions;

use PDO;

final class SubscriptionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function activePlan(string $userKeyHash): array
    {
        $statement = $this->pdo->prepare(
            'SELECT plan_code, status, starts_at, expires_at, '
            . "CASE WHEN plan_code = 'premium' AND status = 'active' "
            . 'AND starts_at <= UTC_TIMESTAMP(6) '
            . 'AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP(6)) THEN 1 ELSE 0 END AS premium_active '
            . 'FROM user_subscriptions WHERE user_key_hash = :user_key_hash LIMIT 1'
        );
        $statement->execute(['user_key_hash' => $userKeyHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return ['plan_code' => 'free', 'subscription' => null];
        }

        $premiumActive = (int) $row['premium_active'] === 1;

        return [
            'plan_code' => $premiumActive ? 'premium' : 'free',
            'subscription' => [
                'plan_code' => (string) $row['plan_code'],
                'status' => (string) $row['status'],
                'starts_at' => (string) $row['starts_at'],
                'expires_at' => $row['expires_at'] === null ? null : (string) $row['expires_at'],
            ],
        ];
    }
}
