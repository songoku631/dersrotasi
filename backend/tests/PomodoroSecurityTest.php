<?php
declare(strict_types=1);

function pomodoroCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$migration = file_get_contents(dirname(__DIR__) . '/database/migrations/016_create_pomodoro_rooms.sql');
$repository = file_get_contents(dirname(__DIR__) . '/src/Pomodoro/PomodoroRepository.php');
$chatMigration = file_get_contents(dirname(__DIR__) . '/database/migrations/017_create_pomodoro_room_messages.sql');
$attachmentMigration = file_get_contents(dirname(__DIR__) . '/database/migrations/018_add_pomodoro_chat_attachments.sql');
$router = file_get_contents(dirname(__DIR__) . '/public/index.php');
pomodoroCheck(str_contains($migration, 'room_code_hash') && !str_contains($migration, 'room_code VARCHAR'), 'Oda kodu yalnız hash olarak saklanmalı.');
pomodoroCheck(str_contains($repository, 'password_hash') && str_contains($repository, 'password_verify'), 'Özel oda kodu güvenli doğrulanmalı.');
pomodoroCheck(str_contains($repository, "['youtube.com','www.youtube.com','youtu.be']") && str_contains($repository, "open.spotify.com"), 'Müzik sağlayıcısı allowlist ile doğrulanmalı.');
pomodoroCheck(str_contains($repository, 'owner($id,$uid)'), 'Host işlemleri backend tarafında doğrulanmalı.');
pomodoroCheck(str_contains($migration, 'pomodoro_rate_limits'), 'Rate limit tablosu bulunmalı.');
pomodoroCheck(!str_contains($repository, 'file_get_contents($url'), 'Backend kullanıcı URL’sini fetch etmemeli.');
pomodoroCheck(str_contains($chatMigration, 'CREATE TABLE IF NOT EXISTS pomodoro_room_messages') && !preg_match('/^\s*(DROP|TRUNCATE|DELETE|UPDATE)\b/im',$chatMigration), 'Chat migration yalnızca güvenli tablo oluşturmalı.');
pomodoroCheck(str_contains($repository, 'mb_strlen($message)>1000') && str_contains($repository, 'VALUES(:r,:u,:m)'), 'Chat uzunluğu doğrulanmalı ve prepared statement kullanmalı.');
pomodoroCheck(str_contains($router, "'messages' && \$method === 'GET'") && str_contains($router, "'messages' && \$method === 'POST'"), 'Chat endpointleri router içinde bulunmalı.');
pomodoroCheck(!preg_match('/^\s*(DROP|TRUNCATE|DELETE|UPDATE)\b/im',$attachmentMigration) && str_contains($attachmentMigration,'attachment_path'), 'Attachment migration veri silmemeli.');
pomodoroCheck(str_contains($router, 'imageAttachment($uid') && str_contains($router, 'Cache-Control: private') && str_contains($router, 'X-Content-Type-Options: nosniff'), 'Görsel okuma auth/üyelik ve güvenli header akışını kullanmalı.');
pomodoroCheck(str_contains($router, 'catch(Throwable $exception){$storage->discard'), 'DB hatasında orphan object temizlenmeli.');
echo "PomodoroSecurityTest: OK\n";
