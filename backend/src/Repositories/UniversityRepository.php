<?php

declare(strict_types=1);

namespace DersRotasi\Repositories;

use PDO;
use RuntimeException;

final class UniversityRepository
{
    private const SORTS = [
        'rank_asc' => 'u.base_rank IS NULL, u.base_rank ASC',
        'rank_desc' => 'u.base_rank IS NULL, u.base_rank DESC',
        'score_desc' => 'u.base_score IS NULL, u.base_score DESC',
        'score_asc' => 'u.base_score IS NULL, u.base_score ASC',
        'university_asc' => 'u.university_name ASC',
        'university_desc' => 'u.university_name DESC',
        'department_asc' => 'u.department_name ASC',
        'department_desc' => 'u.department_name DESC',
    ];
    private const ENUM_FILTERS = [
        'score_type' => ['say', 'ea', 'soz', 'dil', 'tyt'],
        'university_type' => ['devlet', 'vakif', 'kktc', 'yabanci'],
        'education_type' => ['orgun', 'ikinci_ogretim', 'uzaktan', 'acikogretim', 'diger'],
        'scholarship_type' => ['ucretsiz', 'burslu', 'yuzde_50', 'yuzde_25', 'ucretli', 'diger'],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function paginate(array $filters, ?string $firebaseUid = null): array
    {
        $page = $this->positiveInt($filters['page'] ?? 1, 'page');
        $limit = $this->positiveInt($filters['limit'] ?? 20, 'limit');
        if ($limit > 100) {
            throw new RuntimeException('Sayfa başına en fazla 100 kayıt istenebilir.', 422);
        }

        $sort = trim((string) ($filters['sort'] ?? 'rank_asc'));
        if (!isset(self::SORTS[$sort])) {
            throw new RuntimeException('Sıralama seçeneği geçersiz.', 422);
        }

        [$where, $params] = $this->buildFilters($filters);
        $favoritesOnly = filter_var($filters['favorites_only'] ?? false, FILTER_VALIDATE_BOOL);
        if ($favoritesOnly && $firebaseUid === null) {
            throw new RuntimeException('Favorileri görüntülemek için giriş yapmalısınız.', 401);
        }

        $favoriteJoin = '';
        $favoriteSelect = '0 AS is_favorite';
        if ($firebaseUid !== null) {
            $favoriteJoin = ' LEFT JOIN favorites f ON f.university_id = u.id AND f.firebase_uid = :firebase_uid';
            $favoriteSelect = 'CASE WHEN f.id IS NULL THEN 0 ELSE 1 END AS is_favorite';
            $params['firebase_uid'] = $firebaseUid;
            if ($favoritesOnly) {
                $where[] = 'f.id IS NOT NULL';
            }
        }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM universities u' . $favoriteJoin . $whereSql);
        $this->bind($count, $params);
        $count->execute();
        $total = (int) $count->fetchColumn();

        $sql = 'SELECT u.*, ' . $favoriteSelect . ' FROM universities u'
            . $favoriteJoin . $whereSql
            . ' ORDER BY ' . self::SORTS[$sort] . ', u.id ASC LIMIT :limit OFFSET :offset';
        $statement = $this->pdo->prepare($sql);
        $this->bind($statement, $params);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $statement->execute();

        return [
            'items' => $statement->fetchAll(),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => $total === 0 ? 0 : (int) ceil($total / $limit),
            ],
            'available_years' => $this->availableYears(),
        ];
    }

    public function find(int $id, ?string $firebaseUid = null): ?array
    {
        $favoriteSelect = '0 AS is_favorite';
        $join = '';
        $params = ['id' => $id];
        if ($firebaseUid !== null) {
            $favoriteSelect = 'CASE WHEN f.id IS NULL THEN 0 ELSE 1 END AS is_favorite';
            $join = ' LEFT JOIN favorites f ON f.university_id = u.id AND f.firebase_uid = :firebase_uid';
            $params['firebase_uid'] = $firebaseUid;
        }

        $statement = $this->pdo->prepare(
            'SELECT u.*, ' . $favoriteSelect . ' FROM universities u' . $join . ' WHERE u.id = :id LIMIT 1'
        );
        $statement->execute($params);
        $row = $statement->fetch();

        return $row ?: null;
    }

    public function filters(): array
    {
        $columns = [
            'cities' => 'city', 'score_types' => 'score_type',
            'university_types' => 'university_type', 'education_types' => 'education_type',
            'education_languages' => 'education_language', 'scholarship_types' => 'scholarship_type',
            'years' => 'year',
        ];
        $result = [];
        foreach ($columns as $key => $column) {
            $expression = $column;
            if ($key === 'education_languages') {
                $expression = "COALESCE(NULLIF(TRIM(education_language), ''), 'Türkçe')";
            }
            $statement = $this->pdo->query(
                "SELECT DISTINCT {$expression} AS {$column} FROM universities WHERE {$expression} IS NOT NULL AND {$expression} <> '' ORDER BY {$column}"
            );
            $result[$key] = array_column($statement->fetchAll(), $column);
            if ($key === 'education_languages') {
                $result[$key] = array_values(array_unique([...$result[$key], 'Türkçe']));
            }
        }

        return $result;
    }

    public function suggestionCandidates(array $filters, int $limit): array
    {
        [$where, $params] = $this->buildFilters($filters);
        $where[] = 'u.base_rank IS NOT NULL';
        $sql = 'SELECT u.* FROM universities u WHERE ' . implode(' AND ', $where)
            . ' ORDER BY ABS(u.base_rank - :user_rank) ASC, u.base_rank ASC LIMIT :limit';
        $statement = $this->pdo->prepare($sql);
        $this->bind($statement, $params);
        $statement->bindValue(':user_rank', (int) $filters['user_rank'], PDO::PARAM_INT);
        $statement->bindValue(':limit', min(300, max(30, $limit * 12)), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    private function buildFilters(array $filters): array
    {
        $where = [];
        $params = [];
        foreach (['search', 'university', 'department', 'city'] as $name) {
            $value = trim((string) ($filters[$name] ?? ''));
            if ($value === '') {
                continue;
            }
            $columnMap = [
                'university' => 'u.university_name', 'department' => 'u.department_name',
                'city' => 'u.city',
            ];
            if ($name === 'search') {
                $where[] = '(u.university_name LIKE :search OR u.department_name LIKE :search OR u.faculty_name LIKE :search OR u.city LIKE :search)';
            } else {
                $where[] = $columnMap[$name] . " LIKE :{$name}";
            }
            $params[$name] = '%' . $value . '%';
        }

        $educationLanguage = trim((string) ($filters['education_language'] ?? ''));
        if ($educationLanguage !== '') {
            $where[] = "COALESCE(NULLIF(TRIM(u.education_language), ''), 'Türkçe') = :education_language";
            $params['education_language'] = $educationLanguage;
        }

        foreach (self::ENUM_FILTERS as $name => $allowed) {
            $value = trim((string) ($filters[$name] ?? ''));
            if ($value === '') {
                continue;
            }
            if (!in_array($value, $allowed, true)) {
                throw new RuntimeException("{$name} filtresi geçersiz.", 422);
            }
            $where[] = "u.{$name} = :{$name}";
            $params[$name] = $value;
        }

        foreach (['year', 'min_rank', 'max_rank'] as $name) {
            $value = $filters[$name] ?? '';
            if ($value === '' || $value === null) {
                continue;
            }
            $number = $this->positiveInt($value, $name);
            $column = match ($name) {
                'year' => 'u.year = :year',
                'min_rank' => 'u.base_rank >= :min_rank',
                default => 'u.base_rank <= :max_rank',
            };
            $where[] = $column;
            $params[$name] = $number;
        }

        return [$where, $params];
    }

    private function availableYears(): array
    {
        return array_map('intval', $this->pdo->query(
            'SELECT DISTINCT year FROM universities ORDER BY year DESC'
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    private function positiveInt(mixed $value, string $name): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new RuntimeException("{$name} pozitif tam sayı olmalıdır.", 422);
        }

        return (int) $value;
    }

    private function bind(\PDOStatement $statement, array $params): void
    {
        foreach ($params as $name => $value) {
            $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }
}
