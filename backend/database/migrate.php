<?php

declare(strict_types=1);

use DersRotasi\Config\Env;
use DersRotasi\Database\Connection;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
if (file_exists($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$pdo = Connection::make(new Env($_ENV));
$migrationFiles = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($migrationFiles);

foreach ($migrationFiles as $file) {
    $pdo->exec(file_get_contents($file));
    echo 'Migrated: ' . basename($file) . PHP_EOL;
}

echo 'All migrations completed.' . PHP_EOL;
