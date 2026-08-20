<?php

declare(strict_types=1);

use DersRotasi\AI\OpenAiClient;
use DersRotasi\Config\Env;
use DersRotasi\Database\Connection;
use DersRotasi\Repositories\StudyPlanRepository;
use DersRotasi\Services\StudyPlanGenerationService;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

function studyCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function studyThrows(callable $callback, int $status): void
{
    try { $callback(); } catch (RuntimeException $exception) {
        studyCheck($exception->getCode() === $status, 'Beklenen HTTP kodu alınamadı.');
        return;
    }
    throw new RuntimeException('Beklenen hata fırlatılmadı.');
}

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();
$env = new Env($_ENV);
studyCheck($env->appEnv() === 'local', 'Test yalnız local ortamda çalışabilir.');
$pdo = Connection::make($env);
$pdo->exec('CREATE TEMPORARY TABLE study_plans (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_key_hash CHAR(64) NOT NULL, week_start DATE NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_week (user_key_hash, week_start)
) ENGINE=InnoDB');
$pdo->exec("CREATE TEMPORARY TABLE study_plan_tasks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  plan_id BIGINT UNSIGNED NOT NULL, day_of_week TINYINT UNSIGNED NOT NULL,
  subject VARCHAR(80) NOT NULL, topic VARCHAR(160) NOT NULL,
  duration_minutes SMALLINT UNSIGNED NOT NULL, question_target SMALLINT UNSIGNED NULL,
  note VARCHAR(1000) NOT NULL DEFAULT '', is_completed TINYINT(1) NOT NULL DEFAULT 0,
  source ENUM('manual','ai') NOT NULL DEFAULT 'manual',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");

$repository = new StudyPlanRepository($pdo);
$week = '2026-08-17';
$empty = $repository->week('study-user-a', $week);
studyCheck($empty['tasks'] === [] && $empty['id'] === null, 'Boş hafta doğru dönmeli.');
$task = $repository->addTask('study-user-a', $week, [
    'day_of_week' => 1, 'subject' => 'Matematik', 'topic' => 'Fonksiyonlar',
    'duration_minutes' => 60, 'question_target' => 40, 'note' => 'Tekrar et',
]);
studyCheck($task['source'] === 'manual', 'Manuel görev kaynağı korunmalı.');
studyCheck(count($repository->week('study-user-a', $week)['tasks']) === 1, 'Görev kalıcı okunmalı.');
studyCheck($repository->week('study-user-b', $week)['tasks'] === [], 'Başka kullanıcı planı görememeli.');
studyThrows(fn () => $repository->updateTask('study-user-b', $task['id'], ['is_completed' => true]), 404);
studyCheck(!$repository->removeTask('study-user-b', $task['id']), 'Başka kullanıcı görev silememeli.');
$completed = $repository->updateTask('study-user-a', $task['id'], ['is_completed' => true]);
studyCheck($completed['is_completed'] === true, 'Tamamlanma durumu kaydedilmeli.');
studyCheck($repository->week('study-user-a', $week)['progress']['completed'] === 1, 'İlerleme doğru hesaplanmalı.');
$repository->updateTask('study-user-a', $task['id'], ['topic' => 'Polinomlar', 'duration_minutes' => 45]);
studyCheck($repository->week('study-user-a', $week)['tasks'][0]['topic'] === 'Polinomlar', 'Düzenleme kalıcı olmalı.');

$client = new class implements OpenAiClient {
    public function respond(string $instructions, array $input, string $safetyIdentifier): array
    {
        return [
            'answer' => json_encode(['summary' => 'Dengeli plan hazır.', 'tasks' => [
                ['day_of_week' => 1, 'subject' => 'Fizik', 'topic' => 'Hareket', 'duration_minutes' => 120, 'question_target' => 30],
                ['day_of_week' => 1, 'subject' => 'Kimya', 'topic' => 'Atom', 'duration_minutes' => 120, 'question_target' => 20],
                ['day_of_week' => 7, 'subject' => 'Türkçe', 'topic' => 'Paragraf', 'duration_minutes' => 40],
            ]], JSON_UNESCAPED_UNICODE),
            'meta' => ['usage' => ['total_tokens' => 123]],
        ];
    }
};
$generated = (new StudyPlanGenerationService($client))->generate([
    'exam_type' => 'tyt_ayt', 'score_type' => 'sayisal', 'daily_minutes' => 180,
    'working_days' => [1, 2, 3], 'target_rank' => 50000, 'exam_timing' => '2027 YKS',
], null, hash('sha256', 'study-user-a'));
studyCheck(count($generated['tasks']) === 2, 'Çalışılmayan gündeki AI görevi elenmeli.');
studyCheck(array_sum(array_column($generated['tasks'], 'duration_minutes')) === 180, 'AI günlük süre sınırını aşmamalı.');
$withAi = $repository->addGeneratedTasks('study-user-a', $week, $generated['tasks']);
studyCheck(count($withAi['tasks']) === 3, 'AI görevleri mevcut haftaya eklenmeli.');
studyCheck(count(array_filter($withAi['tasks'], fn (array $item): bool => $item['source'] === 'ai')) === 2, 'AI kaynak bilgisi saklanmalı.');
studyCheck($repository->removeTask('study-user-a', $task['id']), 'Görev silme çalışmalı.');
studyCheck($repository->clearWeek('study-user-a', $week) === 2, 'Hafta temizleme tüm kalan görevleri silmeli.');
studyThrows(fn () => $repository->week('study-user-a', '2026-08-18'), 422);

echo "StudyPlanSystemTest: OK\n";
