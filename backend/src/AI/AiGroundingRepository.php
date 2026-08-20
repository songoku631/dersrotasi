<?php

declare(strict_types=1);

namespace DersRotasi\AI;

use DersRotasi\Repositories\FavoriteRepository;
use DersRotasi\Repositories\UniversityRepository;
use DersRotasi\Services\PreferenceEvaluationService;
use PDO;

final class AiGroundingRepository implements AiGroundingProvider
{
    private const RESULT_LIMIT = 24;
    private const DEPARTMENT_TERMS = [
        'bilgisayar' => 'Bilgisayar', 'yazilim' => 'Yazılım',
        'endustri' => 'Endüstri', 'elektrik' => 'Elektrik',
        'elektronik' => 'Elektronik', 'makine' => 'Makine',
        'insaat' => 'İnşaat', 'mimarlik' => 'Mimarlık',
        'muhendis' => 'Mühendis', 'tip' => 'Tıp',
        'dis hekim' => 'Diş Hekim', 'eczacilik' => 'Eczacılık',
        'hukuk' => 'Hukuk', 'psikoloji' => 'Psikoloji',
        'isletme' => 'İşletme', 'iktisat' => 'İktisat',
        'ogretmen' => 'Öğretmen',
    ];
    private const SCHOLARSHIP_TERMS = [
        'yuzde 50' => 'yuzde_50', '%50' => 'yuzde_50',
        'yuzde 25' => 'yuzde_25', '%25' => 'yuzde_25',
        'tam burslu' => 'burslu', 'burslu' => 'burslu',
        'ucretsiz' => 'ucretsiz', 'ucretli' => 'ucretli',
    ];
    private const EDUCATION_TYPE_TERMS = [
        'ikinci ogretim' => 'ikinci_ogretim',
        'uzaktan' => 'uzaktan',
        'acikogretim' => 'acikogretim',
        'acik ogretim' => 'acikogretim',
        'orgun' => 'orgun',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AiIntent $intent = new AiIntent()
    ) {
    }

    public function find(string $message, ?string $firebaseUid): array
    {
        if ($this->intent->requestsFavorites($message)) {
            $items = $firebaseUid === null
                ? []
                : (new FavoriteRepository($this->pdo))->all($firebaseUid);
            return $this->result(true, true, 'favorites', ['favorites' => true], $items);
        }

        if (!$this->intent->requiresDatabase($message)) {
            return $this->result(false, false, null, [], []);
        }

        $normalized = $this->intent->normalize($message);
        $repository = new UniversityRepository($this->pdo);
        $catalog = $repository->filters();
        $rank = $this->intent->detectRank($normalized);
        $city = $this->matchValue($normalized, $catalog['cities'] ?? [], true);
        $universities = $this->matchUniversities($normalized, $city);
        $department = $this->matchDepartment($normalized);
        $scoreType = $this->intent->detectScoreType($normalized);
        if ($scoreType === null && preg_match('/\bmuhendis[a-z]*/u', $normalized)) {
            $scoreType = 'say';
        }
        $scholarshipType = $this->matchTerm($normalized, self::SCHOLARSHIP_TERMS);
        $educationType = $this->matchTerm($normalized, self::EDUCATION_TYPE_TERMS);
        $educationLanguage = $this->matchValue(
            $normalized,
            $catalog['education_languages'] ?? []
        );
        $detectedYear = $this->detectYear($normalized, $catalog['years'] ?? []);
        $queryYear = $detectedYear ?? 2026;
        $filters = array_filter([
            'rank' => $rank,
            'city' => $city,
            'universities' => $universities,
            'department' => $department,
            'score_type' => $scoreType,
            'scholarship_type' => $scholarshipType,
            'education_type' => $educationType,
            'education_language' => $educationLanguage,
            'year' => $detectedYear,
        ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');

        if ($filters === []) {
            return $this->result(true, false, 'universities', [], []);
        }

        $queryFilters = [
            'page' => 1,
            'limit' => self::RESULT_LIMIT,
            'sort' => 'rank_' . $queryYear . ($rank === null ? '_asc' : '_nearest'),
            'city' => $city === null ? [] : [$city],
            'university' => $universities,
            'department' => $department ?? '',
            'score_type' => $scoreType === null ? [] : [$scoreType],
            'scholarship_type' => $scholarshipType === null ? [] : [$scholarshipType],
            'education_type' => $educationType === null ? [] : [$educationType],
            'education_language' => $educationLanguage === null ? [] : [$educationLanguage],
        ];
        if ($rank !== null) {
            $queryFilters['target_rank'] = $rank;
            $queryFilters['min_rank'] = max(1, (int) floor($rank * 0.60));
            $queryFilters['max_rank'] = (int) ceil($rank * 1.50);
        }

        $items = $repository->paginate($queryFilters, $firebaseUid)['items'];
        $items = array_values(array_filter(
            array_map(fn (array $item): array => $this->forYear($item, $queryYear), $items),
            fn (array $item): bool => $this->matchesFilters($item, $filters)
        ));
        if ($rank !== null) {
            usort($items, static function (array $left, array $right) use ($rank): int {
                $leftRank = $left['base_rank'] ?? PHP_INT_MAX;
                $rightRank = $right['base_rank'] ?? PHP_INT_MAX;
                return abs((int) $leftRank - $rank) <=> abs((int) $rightRank - $rank);
            });
            $evaluation = new PreferenceEvaluationService();
            foreach ($items as &$item) {
                $item['evaluation'] = $evaluation->evaluate(
                    $rank,
                    $item['base_rank'] !== null ? (int) $item['base_rank'] : null,
                    (int) $item['year']
                );
            }
            unset($item);
        }

        return $this->result(
            true,
            true,
            'universities',
            $filters,
            array_slice($items, 0, self::RESULT_LIMIT)
        );
    }

    private function result(
        bool $required,
        bool $searched,
        ?string $source,
        array $filters,
        array $items
    ): array {
        return [
            'required' => $required,
            'searched' => $searched,
            'source' => $source,
            'filters' => $filters,
            'items' => $this->sanitize($items),
        ];
    }

    private function distinctValues(string $column): array
    {
        if (!in_array($column, ['city', 'university_name'], true)) {
            return [];
        }
        return $this->pdo->query(
            "SELECT DISTINCT {$column} FROM universities "
            . "WHERE {$column} IS NOT NULL AND {$column} <> '' AND year = 2026 ORDER BY {$column}"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    private function matchValue(
        string $message,
        array $values,
        bool $allowLocationSuffix = false
    ): ?string {
        foreach ($values as $value) {
            if ($this->containsPhrase($message, (string) $value, $allowLocationSuffix)) {
                return (string) $value;
            }
        }
        return null;
    }

    private function matchUniversities(string $message, ?string $city): array
    {
        $matches = [];
        $ignored = ['universitesi', 'universite', 'vakif', 'devlet', 'teknik'];
        if ($city !== null) {
            $ignored[] = $this->intent->normalize($city);
        }
        foreach ($this->distinctValues('university_name') as $university) {
            if ($this->containsPhrase($message, (string) $university)) {
                $matches[] = (string) $university;
                continue;
            }
            $tokens = preg_split(
                '/[^a-z0-9]+/',
                $this->intent->normalize((string) $university),
                -1,
                PREG_SPLIT_NO_EMPTY
            ) ?: [];
            foreach ($tokens as $token) {
                if (strlen($token) < 4 || in_array($token, $ignored, true)) {
                    continue;
                }
                if ($this->containsPhrase($message, $token)) {
                    $matches[] = (string) $university;
                    break;
                }
            }
            if (count($matches) >= 4) {
                break;
            }
        }
        return array_values(array_unique($matches));
    }

    private function matchDepartment(string $message): ?string
    {
        foreach (self::DEPARTMENT_TERMS as $needle => $databaseTerm) {
            if (preg_match(
                '/(?<![a-z0-9])' . preg_quote($needle, '/') . '[a-z]*/u',
                $message
            ) === 1) {
                return $databaseTerm;
            }
        }
        return null;
    }

    private function matchTerm(string $message, array $terms): ?string
    {
        foreach ($terms as $needle => $value) {
            if ($this->containsPhrase($message, $needle)) {
                return $value;
            }
        }
        return null;
    }

    private function detectYear(string $message, array $availableYears): ?int
    {
        if (!preg_match('/\b(20\d{2})\b/', $message, $match)) {
            return null;
        }
        $year = (int) $match[1];
        return in_array($year, array_map('intval', $availableYears), true)
            && in_array($year, [2023, 2024, 2025, 2026], true)
            ? $year
            : null;
    }

    private function containsPhrase(
        string $message,
        string $value,
        bool $allowLocationSuffix = false
    ): bool {
        $normalized = $this->intent->normalize($value);
        if ($normalized === '') {
            return false;
        }
        $suffix = $allowLocationSuffix
            ? "(?:['’]?(?:da|de|ta|te|dan|den|tan|ten))?"
            : '';
        return preg_match(
            '/(?<![a-z0-9])' . preg_quote($normalized, '/') . $suffix . '(?![a-z0-9])/u',
            $message
        ) === 1;
    }

    private function forYear(array $item, int $year): array
    {
        $key = (string) $year;
        $sourceYear = (int) ($item['source_year'] ?? $item['year'] ?? 0);
        $item['year'] = $year;
        if (isset($item['rankings']) && array_key_exists($key, $item['rankings'])) {
            $item['base_rank'] = $item['rankings'][$key];
        }
        if (isset($item['scores']) && array_key_exists($key, $item['scores'])) {
            $item['base_score'] = $item['scores'][$key];
        }
        if (isset($item['quotas']) && array_key_exists($key, $item['quotas'])) {
            $item['quota'] = $item['quotas'][$key];
        }
        if ($year !== $sourceYear) {
            $item['source_name'] = $year . ' tarihsel yerleştirme verisi';
            $item['source_url'] = null;
            $item['rank_source_name'] = $year . ' tarihsel başarı sırası verisi';
            $item['rank_source_url'] = null;
        }
        return $item;
    }

    private function matchesFilters(array $item, array $filters): bool
    {
        if (isset($filters['city']) && (string) ($item['city'] ?? '') !== $filters['city']) {
            return false;
        }
        if (isset($filters['department']) && !str_contains(
            $this->intent->normalize((string) ($item['department_name'] ?? '')),
            $this->intent->normalize((string) $filters['department'])
        )) {
            return false;
        }
        foreach ([
            'score_type' => 'score_type',
            'scholarship_type' => 'scholarship_type',
            'education_type' => 'education_type',
            'education_language' => 'education_language',
        ] as $filter => $field) {
            if (isset($filters[$filter]) && $this->intent->normalize((string) ($item[$field] ?? ''))
                !== $this->intent->normalize((string) $filters[$filter])) {
                return false;
            }
        }
        if (isset($filters['universities']) && !in_array(
            (string) ($item['university_name'] ?? ''),
            $filters['universities'],
            true
        )) {
            return false;
        }
        return true;
    }

    private function sanitize(array $items): array
    {
        $allowed = array_flip([
            'id', 'program_code', 'university_name', 'faculty_name', 'department_name',
            'city', 'university_type', 'score_type', 'education_type',
            'education_language', 'scholarship_type', 'base_score', 'base_rank',
            'quota', 'placed_count', 'duration_years', 'year', 'source_year',
            'source_name', 'source_url', 'rank_source_name', 'rank_source_url',
            'is_favorite', 'favorite_id', 'rankings', 'scores', 'quotas', 'evaluation',
        ]);
        return array_map(
            static fn (array $item): array => array_intersect_key($item, $allowed),
            array_slice($items, 0, self::RESULT_LIMIT)
        );
    }
}
