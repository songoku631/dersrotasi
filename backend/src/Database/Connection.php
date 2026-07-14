<?php

declare(strict_types=1);

namespace DersRotasi\Database;

use DersRotasi\Config\Env;
use PDO;

final class Connection
{
    public static function make(Env $env): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $env->dbHost(),
            $env->dbPort(),
            $env->dbName()
        );

        return new PDO($dsn, $env->dbUsername(), $env->dbPassword(), [
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
