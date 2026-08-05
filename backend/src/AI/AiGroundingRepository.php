<?php

declare(strict_types=1);

namespace DersRotasi\AI;

use DersRotasi\Repositories\FavoriteRepository;
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
        $rank = $this->intent->detectRank($normalized);
        $city = $this->matchValue($normalized, $this->distinctValues('city'));
        $universities = $this->matchUniversities($normalized);
        $department = $this->matchDepartment($normalized);
        $scoreType = $this->intent->detectScoreType($normalized);
        $filters = array_filter([
            'rank' => $rank, 'city' => $city, 'universities' => $universities,
            'department' => $department, 'score_type' => $scoreType,
        ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');

        if ($filters === []) {
            return $this->result(true, false, 'universities', [], []);
        }

        $where = ['u.year = (SELECT MAX(year) FROM universities)'];
        $params = [];
        if ($rank !== null) {
            $where[] = 'u.base_rank BETWEEN :min_rank AND :max_rank';
            $params['min_rank'] = max(1, (int) floor($rank * 0.60));
            $params['max_rank'] = (int) ceil($rank * 1.50);
        }
        if ($city !== null) {
            $where[] = 'u.city = :city';
            $params['city'] = $city;
        }
        if ($department !== null) {
            $where[] = 'u.department_name LIKE :department';
            $params['department'] = '%' . $department . '%';
        }
        if ($scoreType !== null) {
            $where[] = 'u.score_type = :score_type';
            $params['score_type'] = $scoreType;
        }
        if ($universities !== []) {
            $conditions = [];
            foreach ($universities as $index => $university) {
                $name = 'university_' . $index;
                $conditions[] = 'u.university_name = :' . $name;
                $params[$name] = $university;
            }
            $where[] = '(' . implode(' OR ', $conditions) . ')';
        }

        $order = 'u.base_rank IS NULL, u.base_rank ASC';
        if ($rank !== null) {
            $order = 'u.base_rank IS NULL, '
                . 'ABS(CAST(u.base_rank AS SIGNED) - :target_rank) ASC, u.base_rank ASC';
            $params['target_rank'] = $rank;
        }

        $statement = $this->pdo->prepare(
            'SELECT u.id, u.program_code, u.university_name, u.faculty_name, '
            . 'u.department_name, u.city, u.university_type, u.score_type, '
            . 'u.education_type, u.education_language, u.scholarship_type, '
            . 'u.base_score, u.base_rank, u.quota, u.duration_years, u.year, '
            . 'u.source_name, u.source_url FROM universities u WHERE '
            . implode(' AND ', $where) . ' ORDER BY ' . $order
            . ' LIMIT ' . self::RESULT_LIMIT
        );
        foreach ($params as $name => $value) {
            $statement->bindValue(
                ':' . $name,
                $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }
        $statement->execute();
        $items = $statement->fetchAll();

        if ($rank !== null) {
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

        return $this->result(true, true, 'universities', $filters, $items);
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
            . "WHERE {$column} IS NOT NULL AND {$column} <> '' ORDER BY {$column}"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    private function matchValue(string $message, array $values): ?string
    {
        foreach ($values as $value) {
            if (str_contains($message, $this->intent->normalize((string) $value))) {
                return (string) $value;
            }
        }
        return null;
    }

    private function matchUniversities(string $message): array
    {
        $matches = [];
        $ignored = ['universitesi', 'universite', 'vakif', 'devlet', 'istanbul', 'ankara'];
        foreach ($this->distinctValues('university_name') as $university) {
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
                if (preg_match('/\b' . preg_quote($token, '/') . '\b/', $message)) {
                    $matches[] = (string) $university;
                    break;
                }
            }
            if (count($matches) >= 4) {
                break;
            }
        }
        return $matches;
    }

    private function matchDepartment(string $message): ?string
    {
        foreach (self::DEPARTMENT_TERMS as $needle => $databaseTerm) {
            if (str_contains($message, $needle)) {
                return $databaseTerm;
            }
        }
        return null;
    }

    private function sanitize(array $items): array
    {
        $allowed = array_flip([
            'id', 'program_code', 'university_name', 'faculty_name', 'department_name',
            'city', 'university_type', 'score_type', 'education_type',
            'education_language', 'scholarship_type', 'base_score', 'base_rank',
            'quota', 'duration_years', 'year', 'source_name', 'source_url', 'evaluation',
        ]);
        return array_map(
            static fn (array $item): array => array_intersect_key($item, $allowed),
            array_slice($items, 0, self::RESULT_LIMIT)
        );
    }

}
