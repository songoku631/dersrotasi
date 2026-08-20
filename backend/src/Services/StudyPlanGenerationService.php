<?php

declare(strict_types=1);

namespace DersRotasi\Services;

use DersRotasi\AI\OpenAiClient;
use RuntimeException;

final class StudyPlanGenerationService
{
    public function __construct(private readonly OpenAiClient $client)
    {
    }

    public function generate(array $payload, ?array $profile, string $safetyIdentifier): array
    {
        $input = $this->validateInput($payload, $profile);
        $json = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $response = $this->client->respond(
            <<<'PROMPT'
Sen Dersrotası'nın çalışma planı asistanısın. Verilen öğrenci bilgileriyle uygulanabilir 7 günlük plan üret.
Yalnız geçerli JSON döndür; markdown veya açıklama ekleme. Şema:
{"summary":"kısa Türkçe özet","tasks":[{"day_of_week":1,"subject":"Ders","topic":"Konu","duration_minutes":60,"question_target":30,"note":"kısa not"}]}
day_of_week 1=Pazartesi, 7=Pazar. Sadece working_days içindeki günleri kullan. Her günün toplam süresi daily_minutes değerini aşmasın.
Her çalışma günü için tam 1 ana görev üret; böylece dinlenme ve tekrar payı kalsın. subject en fazla 25, topic en fazla 60 karakter olsun.
summary en fazla 100 karakter, note en fazla 40 karakter olsun veya boş bırak. 30-120 dakikalık dengeli görevler üret.
JSON'u 450 token altında tut. question_target gerektiğinde null olabilir.
Net, sıralama veya sınav sonucu garantisi verme. Kullanıcının yazmadığı mevcut net veya başarı sonucu uydurma.
PROMPT,
            [['role' => 'user', 'content' => $json]],
            $safetyIdentifier
        );
        $decoded = $this->decodeJson((string) ($response['answer'] ?? ''));
        if (!is_array($decoded['tasks'] ?? null)) {
            throw new RuntimeException('AI çalışma planı yapılandırılmış görevler döndürmedi.', 502);
        }

        $tasks = $this->normalizeTasks($decoded['tasks'], $input['working_days'], $input['daily_minutes']);
        if ($tasks === []) {
            throw new RuntimeException('AI uygulanabilir çalışma görevi oluşturamadı.', 502);
        }

        return [
            'tasks' => $tasks,
            'summary' => trim((string) ($decoded['summary'] ?? '7 günlük çalışma planın hazırlandı.')),
            'input' => $input,
            'disclaimer' => 'Bu plan yardımcı bir öneridir; kesin sınav sonucu veya başarı sırası garantisi vermez.',
            'meta' => $response['meta'] ?? [],
        ];
    }

    private function validateInput(array $payload, ?array $profile): array
    {
        $examType = strtolower(trim((string) ($payload['exam_type'] ?? '')));
        $scoreType = strtolower(trim((string) ($payload['score_type'] ?? ($profile['score_type'] ?? ''))));
        $scoreAliases = ['sayisal' => 'sayisal', 'say' => 'sayisal', 'ea' => 'esit_agirlik', 'esit_agirlik' => 'esit_agirlik', 'sozel' => 'sozel', 'soz' => 'sozel', 'dil' => 'dil'];
        $minutes = filter_var($payload['daily_minutes'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 30, 'max_range' => 720]]);
        $days = array_values(array_unique(array_map('intval', is_array($payload['working_days'] ?? null) ? $payload['working_days'] : [])));
        sort($days);
        if (!in_array($examType, ['tyt', 'ayt', 'tyt_ayt'], true) || !isset($scoreAliases[$scoreType]) || $minutes === false || $days === [] || min($days) < 1 || max($days) > 7) {
            throw new RuntimeException('AI plan formu geçersiz.', 422);
        }
        $targetRank = $payload['target_rank'] ?? ($profile['target_rank'] ?? null);
        if ($targetRank !== null && $targetRank !== '') {
            $targetRank = filter_var($targetRank, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000000]]);
            if ($targetRank === false) throw new RuntimeException('Hedef başarı sırası geçersiz.', 422);
        } else $targetRank = null;

        return [
            'exam_type' => $examType, 'score_type' => $scoreAliases[$scoreType],
            'daily_minutes' => (int) $minutes, 'working_days' => $days,
            'target_rank' => $targetRank === null ? null : (int) $targetRank,
            'exam_timing' => $this->limitedText($payload['exam_timing'] ?? '', 120),
            'strong_subjects' => $this->limitedText($payload['strong_subjects'] ?? ($profile['strong_lessons'] ?? ''), 500),
            'weak_subjects' => $this->limitedText($payload['weak_subjects'] ?? ($profile['improvement_lessons'] ?? ''), 500),
            'note' => $this->limitedText($payload['note'] ?? '', 1000),
        ];
    }

    private function normalizeTasks(array $rawTasks, array $workingDays, int $dailyLimit): array
    {
        $result = [];
        $totals = array_fill(1, 7, 0);
        foreach (array_slice($rawTasks, 0, 50) as $raw) {
            if (!is_array($raw)) continue;
            $day = filter_var($raw['day_of_week'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 7]]);
            $duration = filter_var($raw['duration_minutes'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 5, 'max_range' => 120]]);
            $subject = $this->limitedText($raw['subject'] ?? '', 80);
            $topic = $this->limitedText($raw['topic'] ?? '', 160);
            if ($day === false || !in_array((int) $day, $workingDays, true) || $duration === false || $subject === '' || $topic === '') continue;
            $remaining = $dailyLimit - $totals[(int) $day];
            if ($remaining < 5) continue;
            $duration = min((int) $duration, $remaining);
            $questions = $raw['question_target'] ?? null;
            $questions = is_numeric($questions) && (int) $questions > 0 ? min(5000, (int) $questions) : null;
            $result[] = [
                'day_of_week' => (int) $day, 'subject' => $subject, 'topic' => $topic,
                'duration_minutes' => $duration, 'question_target' => $questions,
                'note' => $this->limitedText($raw['note'] ?? '', 1000), 'is_completed' => false,
            ];
            $totals[(int) $day] += $duration;
        }
        return $result;
    }

    private function decodeJson(string $answer): array
    {
        $text = trim($answer);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/su', $text, $matches)) $text = $matches[1];
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) throw new RuntimeException('AI çalışma planı geçersiz JSON döndürdü.', 502);
        return $decoded;
    }

    private function limitedText(mixed $value, int $max): string
    {
        return mb_substr(trim((string) $value), 0, $max, 'UTF-8');
    }
}
