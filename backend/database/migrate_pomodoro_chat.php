<?php
declare(strict_types=1);

use DersRotasi\Config\Env;
use DersRotasi\Database\Connection;

require dirname(__DIR__) . '/vendor/autoload.php';

$pdo = Connection::make(new Env($_ENV));
$files = [
    '017_create_pomodoro_room_messages.sql',
    '018_add_pomodoro_chat_attachments.sql',
];

foreach (($argv[1] ?? '') === '--verify-only' ? [] : $files as $name) {
    $path = __DIR__ . '/migrations/' . $name;
    $sql = file_get_contents($path);
    if (!is_string($sql) || preg_match('/^\s*(DROP|TRUNCATE|DELETE)\b/im', $sql)) {
        throw new RuntimeException("Unsafe or unreadable migration: {$name}");
    }
    $pdo->exec($sql);
    echo "Applied: {$name}\n";
}

$statement = $pdo->query("SELECT COLUMN_NAME AS name,COLUMN_TYPE AS type,IS_NULLABLE AS nullable FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pomodoro_room_messages' ORDER BY ORDINAL_POSITION");
foreach ($statement as $column) {
    echo implode('|', [$column['name'], $column['type'], $column['nullable']]) . "\n";
}
