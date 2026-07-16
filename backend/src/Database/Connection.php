<?php

declare(strict_types=1);

namespace DersRotasi\Database;

use DersRotasi\Config\Env;
use PDO;
use PDOException;

final class Connection
{
    public static function make(Env $env): PDO
    {
        $instanceConnectionName = $env->instanceConnectionName();
        $connectionType = $instanceConnectionName === null ? 'local TCP' : 'Cloud SQL Unix socket';
        $dsn = $instanceConnectionName === null
            ? sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $env->dbHost(),
                $env->dbPort(),
                $env->dbName()
            )
            : sprintf(
                'mysql:unix_socket=/cloudsql/%s;dbname=%s;charset=utf8mb4',
                $instanceConnectionName,
                $env->dbName()
            );

        try {
            return new PDO($dsn, $env->dbUsername(), $env->dbPassword(), [
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            if (str_contains($exception->getMessage(), 'Connection refused')) {
                error_log(sprintf('[Database] Connection refused using %s.', $connectionType));
            }

            throw $exception;
        }
    }
}
