<?php
declare(strict_types=1);

function pomodoroCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$migration = file_get_contents(dirname(__DIR__) . '/database/migrations/016_create_pomodoro_rooms.sql');
$repository = file_get_contents(dirname(__DIR__) . '/src/Pomodoro/PomodoroRepository.php');
pomodoroCheck(str_contains($migration, 'room_code_hash') && !str_contains($migration, 'room_code VARCHAR'), 'Oda kodu yalnız hash olarak saklanmalı.');
pomodoroCheck(str_contains($repository, 'password_hash') && str_contains($repository, 'password_verify'), 'Özel oda kodu güvenli doğrulanmalı.');
pomodoroCheck(str_contains($repository, "['youtube.com','www.youtube.com','youtu.be']") && str_contains($repository, "open.spotify.com"), 'Müzik sağlayıcısı allowlist ile doğrulanmalı.');
pomodoroCheck(str_contains($repository, 'owner($id,$uid)'), 'Host işlemleri backend tarafında doğrulanmalı.');
pomodoroCheck(str_contains($migration, 'pomodoro_rate_limits'), 'Rate limit tablosu bulunmalı.');
pomodoroCheck(!str_contains($repository, 'file_get_contents($url'), 'Backend kullanıcı URL’sini fetch etmemeli.');
echo "PomodoroSecurityTest: OK\n";
