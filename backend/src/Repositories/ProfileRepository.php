<?php

declare(strict_types=1);

namespace DersRotasi\Repositories;

use PDO;
use RuntimeException;

final class ProfileRepository
{
    private const ALLOWED_SCORE_TYPES = ['sayisal', 'esit_agirlik', 'sozel', 'dil'];
    private const ALLOWED_UNIVERSITY_TYPES = ['devlet', 'vakif', 'fark_etmez'];
    private const ALLOWED_EDUCATION_STATUSES = ['ortaokul', 'lise', 'universite', 'mezun'];
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
        $current = $this->findByUid($firebaseUid);
        $payload['profile_photo_path'] = $current['profile_photo_path'] ?? null;
        $profile = $this->validate(array_merge($current ?? [], $payload));
        $profile['firebase_uid'] = $firebaseUid;
        $profile['username'] = array_key_exists('username', $payload)
            ? $this->validateUsername($payload['username'])
            : ($current['username'] ?? null);

        if ($profile['username'] !== null) {
            $usernameOwner = $this->pdo->prepare(
                'SELECT firebase_uid FROM user_profiles WHERE username = :username LIMIT 1'
            );
            $usernameOwner->execute(['username' => $profile['username']]);
            $ownerUid = $usernameOwner->fetchColumn();
            if ($ownerUid !== false && $ownerUid !== $firebaseUid) {
                throw new RuntimeException('Bu kullanıcı adı daha önce alınmış.', 409);
            }
        }

        $fields = array_keys($profile);
        if ($current === null) {
            $columns = implode(', ', $fields);
            $placeholders = implode(', ', array_map(static fn (string $field): string => ':' . $field, $fields));
            $sql = "INSERT INTO user_profiles ({$columns}) VALUES ({$placeholders})";
        } else {
            $updateFields = array_values(array_filter($fields, static fn (string $field): bool => $field !== 'firebase_uid'));
            $assignments = implode(', ', array_map(
                static fn (string $field): string => "{$field} = :{$field}",
                $updateFields
            ));
            $sql = "UPDATE user_profiles SET {$assignments}, updated_at = CURRENT_TIMESTAMP "
                . 'WHERE firebase_uid = :firebase_uid';
        }

        $statement = $this->pdo->prepare($sql);
        try {
            $statement->execute($profile);
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new RuntimeException('Bu kullanıcı adı daha önce alınmış.', 409);
            }
            throw $exception;
        }

        return $this->findByUid($firebaseUid) ?? [];
    }

    public function updatePhotoPath(string $firebaseUid, string $path): array
    {
        $this->findByUid($firebaseUid) ?? $this->save($firebaseUid, []);
        if (!preg_match('#^/uploads/profiles/[a-f0-9-]+\.(jpg|png|webp)$#', $path)) {
            throw new RuntimeException('Profil fotoğrafı yolu geçersiz.', 422);
        }
        $statement = $this->pdo->prepare(
            'UPDATE user_profiles SET profile_photo_path = :path, updated_at = CURRENT_TIMESTAMP WHERE firebase_uid = :uid'
        );
        $statement->execute(['path' => $path, 'uid' => $firebaseUid]);
        return $this->findByUid($firebaseUid) ?? [];
    }

    private function validateUsername(mixed $value): ?string
    {
        $username = strtolower(trim((string) $value));
        if ($username === '') return null;
        if (!preg_match('/^[a-z0-9_]{3,24}$/', $username)) {
            throw new RuntimeException('Kullanıcı adı 3-24 karakter olmalı; yalnızca küçük harf, rakam ve alt çizgi içermelidir.', 422);
        }
        return $username;
    }

    private function validate(array $payload): array
    {
        $educationStatus = $this->nullableString($payload, 'education_status');
        if ($educationStatus !== null && !in_array($educationStatus, self::ALLOWED_EDUCATION_STATUSES, true)) {
            throw new RuntimeException('Eğitim durumu geçersiz.', 422);
        }
        $birthYear = $payload['birth_year'] ?? null;
        if ($birthYear !== null && $birthYear !== '') {
            $currentYear = (int) date('Y');
            if (!filter_var($birthYear, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1900, 'max_range' => $currentYear]])) {
                throw new RuntimeException('Doğum yılı geçersiz.', 422);
            }
            $birthYear = (int) $birthYear;
        } else {
            $birthYear = null;
        }
        $visibility = $this->stringValue($payload, 'profile_visibility', 'private');
        if (!in_array($visibility, ['public', 'private'], true)) {
            throw new RuntimeException('Profil görünürlüğü geçersiz.', 422);
        }
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
            if (!filter_var($targetRank, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]])) {
                throw new RuntimeException('Hedef sıralama pozitif tam sayı olmalıdır.', 422);
            }
            $targetRank = (int) $targetRank;
        } else {
            $targetRank = null;
        }

        $dailyStudyHours = $payload['daily_study_hours'] ?? null;
        if ($dailyStudyHours !== null && $dailyStudyHours !== '') {
            $dailyStudyHours = str_replace(',', '.', trim((string) $dailyStudyHours));
            if (!preg_match('/^\d+(?:\.\d)?$/', $dailyStudyHours) || (float) $dailyStudyHours > 24) {
                throw new RuntimeException('Günlük çalışma saati 0–24 arasında olmalı; örneğin 2,5 veya 2.5 yaz.', 422);
            }
            $dailyStudyHours = (float) $dailyStudyHours;
        } else {
            $dailyStudyHours = null;
        }

        return [
            'first_name' => $this->limitedString($payload, 'first_name', 80),
            'last_name' => $this->limitedString($payload, 'last_name', 80),
            'birth_year' => $birthYear,
            'bio' => $this->limitedString($payload, 'bio', 300),
            'education_status' => $educationStatus,
            'school_name' => $this->limitedString($payload, 'school_name', 180),
            'graduated_high_school' => $this->limitedString($payload, 'graduated_high_school', 180),
            'university' => $this->limitedString($payload, 'university', 180),
            'department' => $this->limitedString($payload, 'department', 180),
            'grade_level' => $this->limitedString($payload, 'grade_level', 40),
            'city' => $this->limitedString($payload, 'city', 100),
            'profile_photo_path' => $this->nullableString($payload, 'profile_photo_path'),
            'profile_visibility' => $visibility,
            'email_public' => $this->booleanValue($payload['email_public'] ?? false),
            'birth_year_public' => $this->booleanValue($payload['birth_year_public'] ?? false),
            'score_type' => $scoreType,
            'target_rank' => $targetRank,
            'target_department' => $this->limitedString($payload, 'target_department', 160),
            'preferred_cities' => $this->stringValue($payload, 'preferred_cities'),
            'university_type' => $universityType,
            'daily_study_hours' => $dailyStudyHours,
            'strong_lessons' => $this->stringValue($payload, 'strong_lessons'),
            'improvement_lessons' => $this->stringValue($payload, 'improvement_lessons'),
        ];
    }

    private function nullableString(array $payload, string $key): ?string
    {
        $value = $this->stringValue($payload, $key);
        return $value === '' ? null : $value;
    }

    private function limitedString(array $payload, string $key, int $maxLength): string
    {
        $value = $this->stringValue($payload, $key);
        if (mb_strlen($value) > $maxLength) {
            throw new RuntimeException("{$key} alanı çok uzun.", 422);
        }
        return $value;
    }

    private function booleanValue(mixed $value): int
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
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
