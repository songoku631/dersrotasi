<?php

declare(strict_types=1);

namespace DersRotasi\Subscriptions;

use PDO;

final class UserRoleRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function role(string $userKeyHash): string
    {
        $statement = $this->pdo->prepare(
            'SELECT role FROM user_roles WHERE user_key_hash = :user_key_hash LIMIT 1'
        );
        $statement->execute(['user_key_hash' => $userKeyHash]);
        $role = $statement->fetchColumn();

        return $role === 'admin' ? 'admin' : 'user';
    }
}
