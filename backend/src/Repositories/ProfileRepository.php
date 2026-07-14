<?php

declare(strict_types=1);

namespace DersRotasi\Repositories;

use PDO;
use RuntimeException;

final class ProfileRepository
{
    private const ALLOWED_SCORE_TYPES = ['sayisal', 'esit_agirlik', 'sozel', 'dil'];
    private const ALLOWED_UNIVERSITY_TYPES = ['devlet', 'vakif', 'fark_etmez'];
    private const SCORE_TYPE_ALIASES = [
        'sayisal' => 'sayisal',
        'Sayısal' => 'sayisal',
        'esit_agirlik' => 'esit_agirlik',
        'Eşit Ağırlık' => 'esit_agirlik',
        'sozel' => 'sozel',
        'Sözel' => 'sozel',
        'dil' => 'dil',
        'Dil' => 'dil',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByUid(string $firebaseUid): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM user_profiles WHERE firebase_uid = :firebase_uid LIMIT 1'
        );
        $statement->execute(['firebase_uid' => $firebaseUid]);
        $profile = $statement->fetch();

        if (!$profile) {
            return null;
        }

        $profile['score_type'] = $this->normalizeScoreType((string) $profile['score_type'])
            ?? 'sayisal';
        $universityType = $this->normalizeUniversityType((string) $profile['university_type'])
            ?? 'fark_etmez';
        $profile['university_type'] = $this->universityTypeLabel($universityType);

        return $profile;
    }

    public function save(string $firebaseUid, array $payload): array
    {
        $profile = $this->validate($payload);
        $profile['firebase_uid'] = $firebaseUid;

        $sql = <<<SQL
INSERT INTO user_profiles (
  firebase_uid,
  score_type,
  target_rank,
  target_department,
  preferred_cities,
  university_type,
  daily_study_hours,
  strong_lessons,
  improvement_lessons
) VALUES (
  :firebase_uid,
  :score_type,
  :target_rank,
  :target_department,
  :preferred_cities,
  :university_type,
  :daily_study_hours,
  :strong_lessons,
  :improvement_lessons
)
ON DUPLICATE KEY UPDATE
  score_type = VALUES(score_type),
  target_rank = VALUES(target_rank),
  target_department = VALUES(target_department),
  preferred_cities = VALUES(preferred_cities),
  university_type = VALUES(university_type),
  daily_study_hours = VALUES(daily_study_hours),
  strong_lessons = VALUES(strong_lessons),
  improvement_lessons = VALUES(improvement_lessons),
  updated_at = CURRENT_TIMESTAMP
SQL;

        $statement = $this->pdo->prepare($sql);
        $statement->execute($profile);

        return $this->findByUid($firebaseUid) ?? [];
    }

    private function validate(array $payload): array
    {
        $scoreType = $this->normalizeScoreType(
            $this->stringValue($payload, 'score_type', 'sayisal')
        );
        if ($scoreType === null || !in_array($scoreType, self::ALLOWED_SCORE_TYPES, true)) {
            throw new RuntimeException('Puan türü geçersiz.', 422);
        }

        $universityType = $this->normalizeUniversityType(
            $this->stringValue($payload, 'university_type', 'fark_etmez')
        );
        if ($universityType === null || !in_array($universityType, self::ALLOWED_UNIVERSITY_TYPES, true)) {
            throw new RuntimeException('Devlet / Vakıf tercihi geçersiz.', 422);
        }

        $targetRank = $payload['target_rank'] ?? null;
        if ($targetRank !== null && $targetRank !== '') {
            if (!filter_var($targetRank, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
                throw new RuntimeException('Hedef sıralama pozitif tam sayı olmalıdır.', 422);
            }
            $targetRank = (int) $targetRank;
        } else {
            $targetRank = null;
        }

        $dailyStudyHours = $payload['daily_study_hours'] ?? null;
        if ($dailyStudyHours !== null && $dailyStudyHours !== '') {
            if (!is_numeric($dailyStudyHours) || (float) $dailyStudyHours < 0) {
                throw new RuntimeException('Günlük çalışma süresi negatif olamaz.', 422);
            }
            $dailyStudyHours = (float) $dailyStudyHours;
        } else {
            $dailyStudyHours = null;
        }

        return [
            'score_type' => $scoreType,
            'target_rank' => $targetRank,
            'target_department' => $this->stringValue($payload, 'target_department'),
            'preferred_cities' => $this->stringValue($payload, 'preferred_cities'),
            'university_type' => $universityType,
            'daily_study_hours' => $dailyStudyHours,
            'strong_lessons' => $this->stringValue($payload, 'strong_lessons'),
            'improvement_lessons' => $this->stringValue($payload, 'improvement_lessons'),
        ];
    }

    private function stringValue(array $payload, string $key, string $default = ''): string
    {
        $value = $payload[$key] ?? $default;
        return trim((string) $value);
    }

    private function normalizeScoreType(string $value): ?string
    {
        if (isset(self::SCORE_TYPE_ALIASES[$value])) {
            return self::SCORE_TYPE_ALIASES[$value];
        }

        $legacyMojibakeAliases = [
            hex2bin('536179C384C2B173616C') => 'sayisal',
            hex2bin('45C385C5B869742041C384C5B8C384C2B1726CC384C2B16B') => 'esit_agirlik',
            hex2bin('53C383C2B67A656C') => 'sozel',
        ];

        return $legacyMojibakeAliases[$value] ?? null;
    }

    private function normalizeUniversityType(string $value): ?string
    {
        $aliases = [
            'devlet' => 'devlet',
            'Devlet' => 'devlet',
            'vakif' => 'vakif',
            'Vakıf' => 'vakif',
            hex2bin('56616BC384C2B166') => 'vakif',
            'fark_etmez' => 'fark_etmez',
            'Fark etmez' => 'fark_etmez',
        ];

        return $aliases[$value] ?? null;
    }

    private function universityTypeLabel(string $value): string
    {
        return match ($value) {
            'devlet' => 'Devlet',
            'vakif' => 'Vakıf',
            default => 'Fark etmez',
        };
    }
}
