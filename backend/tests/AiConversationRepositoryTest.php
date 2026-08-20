<?php

declare(strict_types=1);

use DersRotasi\AI\AiConversationRepository;
use DersRotasi\Config\Env;
use DersRotasi\Database\Connection;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

function conversationCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function conversationThrows(callable $callback, int $status, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException $exception) {
        conversationCheck($exception->getCode() === $status, $message . ' HTTP kodu hatalı.');
        return;
    }
    throw new RuntimeException($message . ' hata fırlatmadı.');
}

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();
$env = new Env($_ENV);
conversationCheck(
    $env->appEnv() === 'local' && in_array($env->dbHost(), ['127.0.0.1', 'localhost', '::1'], true),
    'Bu test yalnızca local veritabanında çalışabilir.'
);

$pdo = Connection::make($env);
$pdo->exec("CREATE TEMPORARY TABLE ai_conversations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_key_hash CHAR(64) NOT NULL,
  title VARCHAR(120) NOT NULL DEFAULT 'Yeni Sohbet',
  last_message_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB");
$pdo->exec("CREATE TEMPORARY TABLE ai_conversation_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  conversation_id BIGINT UNSIGNED NOT NULL,
  request_id_hash CHAR(64) NOT NULL,
  role ENUM('user','assistant') NOT NULL,
  content TEXT NOT NULL,
  structured_data JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_request_role (conversation_id, request_id_hash, role)
) ENGINE=InnoDB");
$pdo->exec('CREATE TEMPORARY TABLE favorites (
  firebase_uid VARCHAR(128) NOT NULL,
  university_id BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB');

$repository = new AiConversationRepository($pdo);
$userAUid = 'conversation-user-a';
$userAHash = hash('sha256', $userAUid);
$userBUid = 'conversation-user-b';
$userBHash = hash('sha256', $userBUid);
$first = $repository->create($userAHash);
conversationCheck($first['title'] === 'Yeni Sohbet', 'Yeni sohbet varsayılan başlıkla açılmalı.');

$requestHash = hash('sha256', 'conversation-request-1');
$response = [
    'answer' => 'İstanbul için gerçek program kartları hazır.',
    'programs' => [[
        'id' => 42,
        'program_code' => 'TEST-42',
        'university_name' => 'Test Üniversitesi',
        'department_name' => 'Bilgisayar Mühendisliği',
        'city' => 'İstanbul',
        'is_favorite' => 0,
    ]],
];
$summary = $repository->appendExchange(
    $userAHash,
    $first['id'],
    $requestHash,
    'İstanbul’da 100k ile mühendislik bölümleri hakkında oldukça uzun bir ilk mesaj',
    $response
);
conversationCheck($summary['title'] !== 'Yeni Sohbet', 'İlk mesaj otomatik başlık üretmeli.');
conversationCheck(mb_strlen($summary['title'], 'UTF-8') <= 64, 'Başlık güvenli uzunlukta olmalı.');
conversationCheck($summary['message_count'] === 2, 'Kullanıcı ve AI mesajı kaydedilmeli.');

$repository->appendExchange(
    $userAHash,
    $first['id'],
    $requestHash,
    'İstanbul’da 100k ile mühendislik bölümleri hakkında oldukça uzun bir ilk mesaj',
    $response
);
conversationCheck(
    $repository->all($userAHash)[0]['message_count'] === 2,
    'Aynı request_id mesajları çoğaltmamalı.'
);
$repository->appendExchange(
    $userAHash,
    $first['id'],
    hash('sha256', 'conversation-request-2'),
    'Bu seçenekleri nasıl sıralamalıyım?',
    ['answer' => 'Hedef ve daha güvenli seçenekleri dengeli dağıtabilirsin.', 'programs' => []]
);
conversationCheck(
    $repository->all($userAHash)[0]['message_count'] === 4,
    'Birden fazla mesaj çifti aynı sohbette kalmalı.'
);

$pdo->prepare('INSERT INTO favorites (firebase_uid, university_id) VALUES (:uid, 42)')
    ->execute(['uid' => $userAUid]);
$detail = $repository->find($userAHash, $first['id'], $userAUid);
conversationCheck(count($detail['messages']) === 4, 'Tüm sohbet mesajları geri yüklenmeli.');
conversationCheck(
    $detail['messages'][1]['programs'][0]['program_code'] === 'TEST-42',
    'Structured program kartı mesajla birlikte geri yüklenmeli.'
);
conversationCheck(
    $detail['messages'][1]['programs'][0]['is_favorite'] === 1,
    'Kart favori durumu açılışta güncel kullanıcıdan gelmeli.'
);

$second = $repository->create($userAHash);
conversationCheck($repository->all($userAHash)[0]['id'] === $second['id'], 'Yeni sohbet listenin üstünde olmalı.');
conversationCheck(count($repository->all($userAHash)) === 2, 'Eski sohbet silinmemeli.');
$repository->find($userAHash, $first['id'], $userAUid);
$afterReopen = $repository->all($userAHash);
conversationCheck(
    $afterReopen[0]['id'] === $first['id'],
    'Son açılan sohbet listenin üstüne taşınmalı: ' . json_encode($afterReopen)
);

conversationThrows(
    fn () => $repository->find($userBHash, $first['id'], $userBUid),
    404,
    'Başka kullanıcı sohbet okuması'
);
conversationThrows(
    fn () => $repository->appendExchange(
        $userBHash,
        $first['id'],
        hash('sha256', 'forbidden-request'),
        'Yetkisiz mesaj',
        ['answer' => 'Yetkisiz yanıt', 'programs' => []]
    ),
    404,
    'Başka kullanıcı sohbet yazması'
);

echo "AiConversationRepositoryTest: OK\n";
