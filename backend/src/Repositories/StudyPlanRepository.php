<?php

declare(strict_types=1);

namespace DersRotasi\Repositories;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class StudyPlanRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function week(string $firebaseUid, mixed $weekStart): array
    {
        $week = $this->validWeekStart($weekStart);
        $hash = hash('sha256', $firebaseUid);
        $plan = $this->findPlan($hash, $week);
        $tasks = $plan === null ? [] : $this->tasks((int) $plan['id'], $hash);

        return $this->present($plan, $week, $tasks);
    }

    public function addTask(string $firebaseUid, mixed $weekStart, array $payload, string $source = 'manual'): array
    {
        $week = $this->validWeekStart($weekStart);
        $task = $this->validatedTask($payload);
        $hash = hash('sha256', $firebaseUid);
        $planId = $this->ensurePlan($hash, $week);
        $statement = $this->pdo->prepare(
            'INSERT INTO study_plan_tasks '
            . '(plan_id, day_of_week, subject, topic, duration_minutes, question_target, note, is_completed, source) '
            . 'VALUES (:plan_id, :day, :subject, :topic, :duration, :questions, :note, :completed, :source)'
        );
        $statement->execute([
            'plan_id' => $planId, 'day' => $task['day_of_week'], 'subject' => $task['subject'],
            'topic' => $task['topic'], 'duration' => $task['duration_minutes'],
            'questions' => $task['question_target'], 'note' => $task['note'],
            'completed' => $task['is_completed'], 'source' => $source === 'ai' ? 'ai' : 'manual',
        ]);

        return $this->taskById((int) $this->pdo->lastInsertId(), $hash);
    }

    public function updateTask(string $firebaseUid, int $taskId, array $payload): array
    {
        $hash = hash('sha256', $firebaseUid);
        $existing = $this->taskById($taskId, $hash);
        $task = $this->validatedTask([...$existing, ...$payload]);
        $statement = $this->pdo->prepare(
            'UPDATE study_plan_tasks t INNER JOIN study_plans p ON p.id = t.plan_id '
            . 'SET t.day_of_week = :day, t.subject = :subject, t.topic = :topic, '
            . 't.duration_minutes = :duration, t.question_target = :questions, t.note = :note, '
            . 't.is_completed = :completed WHERE t.id = :id AND p.user_key_hash = :hash'
        );
        $statement->execute([
            'day' => $task['day_of_week'], 'subject' => $task['subject'], 'topic' => $task['topic'],
            'duration' => $task['duration_minutes'], 'questions' => $task['question_target'],
            'note' => $task['note'], 'completed' => $task['is_completed'], 'id' => $taskId, 'hash' => $hash,
        ]);

        return $this->taskById($taskId, $hash);
    }

    public function removeTask(string $firebaseUid, int $taskId): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE t FROM study_plan_tasks t INNER JOIN study_plans p ON p.id = t.plan_id '
            . 'WHERE t.id = :id AND p.user_key_hash = :hash'
        );
        $statement->execute(['id' => $taskId, 'hash' => hash('sha256', $firebaseUid)]);
        return $statement->rowCount() > 0;
    }

    public function clearWeek(string $firebaseUid, mixed $weekStart): int
    {
        $week = $this->validWeekStart($weekStart);
        $statement = $this->pdo->prepare(
            'DELETE t FROM study_plan_tasks t INNER JOIN study_plans p ON p.id = t.plan_id '
            . 'WHERE p.user_key_hash = :hash AND p.week_start = :week'
        );
        $statement->execute(['hash' => hash('sha256', $firebaseUid), 'week' => $week]);
        return $statement->rowCount();
    }

    public function addGeneratedTasks(string $firebaseUid, mixed $weekStart, array $tasks): array
    {
        if ($tasks === [] || count($tasks) > 50) {
            throw new RuntimeException('AI planı 1 ile 50 arasında görev içermelidir.', 422);
        }
        try {
            $this->pdo->beginTransaction();
            foreach ($tasks as $task) {
                $this->addTask($firebaseUid, $weekStart, $task, 'ai');
            }
            $this->pdo->commit();
            return $this->week($firebaseUid, $weekStart);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function validWeekStart(mixed $value): string
    {
        $text = trim((string) $value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if (!$date || $date->format('Y-m-d') !== $text || $date->format('N') !== '1') {
            throw new RuntimeException('week_start bir Pazartesi tarihi olmalıdır.', 422);
        }
        return $text;
    }

    private function ensurePlan(string $hash, string $week): int
    {
        $this->pdo->prepare(
            'INSERT INTO study_plans (user_key_hash, week_start) VALUES (:hash, :week) '
            . 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        )->execute(['hash' => $hash, 'week' => $week]);
        return (int) $this->pdo->lastInsertId();
    }

    private function findPlan(string $hash, string $week): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM study_plans WHERE user_key_hash = :hash AND week_start = :week LIMIT 1');
        $statement->execute(['hash' => $hash, 'week' => $week]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function tasks(int $planId, string $hash): array
    {
        $statement = $this->pdo->prepare(
            'SELECT t.* FROM study_plan_tasks t INNER JOIN study_plans p ON p.id = t.plan_id '
            . 'WHERE t.plan_id = :plan AND p.user_key_hash = :hash ORDER BY t.day_of_week, t.id'
        );
        $statement->execute(['plan' => $planId, 'hash' => $hash]);
        return array_map([$this, 'presentTask'], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function taskById(int $taskId, string $hash): array
    {
        $statement = $this->pdo->prepare(
            'SELECT t.* FROM study_plan_tasks t INNER JOIN study_plans p ON p.id = t.plan_id '
            . 'WHERE t.id = :id AND p.user_key_hash = :hash LIMIT 1'
        );
        $statement->execute(['id' => $taskId, 'hash' => $hash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new RuntimeException('Çalışma görevi bulunamadı.', 404);
        return $this->presentTask($row);
    }

    private function validatedTask(array $payload): array
    {
        $day = filter_var($payload['day_of_week'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 7]]);
        $duration = filter_var($payload['duration_minutes'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 5, 'max_range' => 720]]);
        $subject = trim((string) ($payload['subject'] ?? ''));
        $topic = trim((string) ($payload['topic'] ?? ''));
        $note = trim((string) ($payload['note'] ?? ''));
        $questions = $payload['question_target'] ?? null;
        if ($questions === '') $questions = null;
        if ($questions !== null) $questions = filter_var($questions, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
        if ($day === false || $duration === false || $subject === '' || $topic === '' || mb_strlen($subject) > 80 || mb_strlen($topic) > 160 || mb_strlen($note) > 1000 || $questions === false) {
            throw new RuntimeException('Görev bilgileri geçersiz.', 422);
        }
        return [
            'day_of_week' => (int) $day, 'subject' => $subject, 'topic' => $topic,
            'duration_minutes' => (int) $duration, 'question_target' => $questions === null ? null : (int) $questions,
            'note' => $note, 'is_completed' => filter_var($payload['is_completed'] ?? false, FILTER_VALIDATE_BOOL) ? 1 : 0,
        ];
    }

    private function present(?array $plan, string $week, array $tasks): array
    {
        $completed = count(array_filter($tasks, static fn (array $task): bool => $task['is_completed']));
        return [
            'id' => $plan === null ? null : (int) $plan['id'], 'week_start' => $week, 'tasks' => $tasks,
            'progress' => ['completed' => $completed, 'total' => count($tasks)],
        ];
    }

    private function presentTask(array $row): array
    {
        return [
            ...$row, 'id' => (int) $row['id'], 'plan_id' => (int) $row['plan_id'],
            'day_of_week' => (int) $row['day_of_week'], 'duration_minutes' => (int) $row['duration_minutes'],
            'question_target' => $row['question_target'] === null ? null : (int) $row['question_target'],
            'is_completed' => (bool) $row['is_completed'],
        ];
    }
}
