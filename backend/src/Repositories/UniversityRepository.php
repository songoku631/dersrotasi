<?php

declare(strict_types=1);

namespace DersRotasi\Repositories;

use PDO;
use RuntimeException;

final class UniversityRepository
{
    private const TURKISH_COLLATION = 'utf8mb4_tr_0900_as_cs';
    private const AUTOCOMPLETE_COLLATION = 'utf8mb4_tr_0900_ai_ci';
    private const EDUCATION_LANGUAGE_SQL = "CASE
        WHEN education_language IS NULL
          OR TRIM(education_language) = ''
          OR CHAR_LENGTH(TRIM(education_language)) < 3
          OR TRIM(education_language) = 'Arap'
        THEN 'Türkçe'
        ELSE TRIM(education_language)
    END";
    private const SORTS = [
        'rank_asc' => '(u.base_rank IS NULL OR u.base_rank <= 0), u.base_rank ASC',
        'rank_desc' => '(u.base_rank IS NULL OR u.base_rank <= 0), u.base_rank DESC',
        'score_desc' => 'u.base_score IS NULL, u.base_score DESC',
        'score_asc' => 'u.base_score IS NULL, u.base_score ASC',
        'rank_2025_asc' => '(ranking_2025 IS NULL OR ranking_2025 <= 0), ranking_2025 ASC',
        'rank_2025_desc' => '(ranking_2025 IS NULL OR ranking_2025 <= 0), ranking_2025 DESC',
        'rank_2024_asc' => '(u24.base_rank IS NULL OR u24.base_rank <= 0), u24.base_rank ASC',
        'rank_2024_desc' => '(u24.base_rank IS NULL OR u24.base_rank <= 0), u24.base_rank DESC',
        'rank_2023_asc' => '(u23.base_rank IS NULL OR u23.base_rank <= 0), u23.base_rank ASC',
        'rank_2023_desc' => '(u23.base_rank IS NULL OR u23.base_rank <= 0), u23.base_rank DESC',
        'score_2025_desc' => 'score_2025 IS NULL, score_2025 DESC',
        'score_2025_asc' => 'score_2025 IS NULL, score_2025 ASC',
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
        $limit = $this->positiveInt($filters['limit'] ?? 50, 'limit');
        if ($limit > 100) {
            throw new RuntimeException('Sayfa başına en fazla 100 kayıt istenebilir.', 422);
        }

        $sort = trim((string) ($filters['sort'] ?? 'rank_2025_asc'));
        if (!isset(self::SORTS[$sort])) {
            throw new RuntimeException('Sıralama seçeneği geçersiz.', 422);
        }

        $ranking2025 = 'CASE WHEN u25.id IS NOT NULL THEN u25.base_rank ELSE u26.base_rank END';
        $score2025 = 'CASE WHEN u25.id IS NOT NULL THEN u25.base_score ELSE u26.base_score END';
        $rankFilterColumn = $this->rankFilterColumnForSort($sort, $ranking2025);
        [$where, $params] = $this->buildFilters($filters, false, $rankFilterColumn);
        array_unshift($where, $this->latestProgramRowSql());
        $favoritesOnly = filter_var($filters['favorites_only'] ?? false, FILTER_VALIDATE_BOOL);
        if ($favoritesOnly && $firebaseUid === null) {
            throw new RuntimeException('Favorileri görüntülemek için giriş yapmalısınız.', 401);
        }

        $historyJoins = ' LEFT JOIN universities u25 ON u25.program_code = u.program_code AND u25.year = 2025'
            . ' LEFT JOIN universities u26 ON u26.program_code = u.program_code AND u26.year = 2026 AND u25.id IS NULL'
            . ' LEFT JOIN universities u24 ON u24.program_code = u.program_code AND u24.year = 2024'
            . ' LEFT JOIN universities u23 ON u23.program_code = u.program_code AND u23.year = 2023';
        $favoriteJoin = '';
        $favoriteSelect = '0 AS is_favorite, NULL AS favorite_id';
        if ($firebaseUid !== null) {
            $favoriteJoin = ' LEFT JOIN ('
                . 'SELECT fu.program_code, MIN(f.university_id) AS favorite_id FROM favorites f '
                . 'INNER JOIN universities fu ON fu.id = f.university_id '
                . 'WHERE f.firebase_uid = :firebase_uid GROUP BY fu.program_code'
                . ') f ON f.program_code = u.program_code';
            $favoriteSelect = 'CASE WHEN f.favorite_id IS NULL THEN 0 ELSE 1 END AS is_favorite, '
                . 'f.favorite_id AS favorite_id';
            $params['firebase_uid'] = $firebaseUid;
            if ($favoritesOnly) {
                $where[] = 'f.favorite_id IS NOT NULL';
            }
        }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $count = $this->pdo->prepare(
            'SELECT COUNT(*) FROM universities u' . $historyJoins . $favoriteJoin . $whereSql
        );
        $this->bind($count, $params);
        $count->execute();
        $total = (int) $count->fetchColumn();

        $sql = 'SELECT u.*, u.year AS source_year, '
            . self::educationLanguageSql('u') . ' AS education_language, '
            . $favoriteSelect . ', '
            . $ranking2025 . ' AS ranking_2025, u24.base_rank AS ranking_2024, u23.base_rank AS ranking_2023, '
            . $score2025 . ' AS score_2025, u24.base_score AS score_2024, u23.base_score AS score_2023, '
            . 'CASE WHEN u25.id IS NOT NULL THEN u25.quota ELSE u26.quota END AS quota_2025, '
            . 'u24.quota AS quota_2024, u23.quota AS quota_2023 '
            . 'FROM universities u' . $historyJoins
            . $favoriteJoin . $whereSql
            . ' ORDER BY ' . self::SORTS[$sort] . ', u.id ASC LIMIT :limit OFFSET :offset';
        $statement = $this->pdo->prepare($sql);
        $this->bind($statement, $params);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $statement->execute();

        return [
            'items' => array_map(
                [new UniversityHistoryPresenter(), 'present'],
                $statement->fetchAll()
            ),
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
            'SELECT u.*, ' . self::educationLanguageSql('u') . ' AS education_language, '
            . $favoriteSelect . ' FROM universities u' . $join . ' WHERE u.id = :id LIMIT 1'
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
                $expression = self::educationLanguageSql();
            }
            $statement = $this->pdo->query(
                "SELECT DISTINCT {$expression} AS {$column} FROM universities WHERE {$expression} IS NOT NULL AND {$expression} <> '' ORDER BY {$column}"
            );
            $result[$key] = array_column($statement->fetchAll(), $column);
            if ($key === 'education_languages') {
                $result[$key] = array_values(array_unique($result[$key]));
            } elseif ($key === 'years') {
                $result[$key] = array_values(array_unique(array_map(
                    static fn (mixed $year): int => (int) $year === 2026 ? 2025 : (int) $year,
                    $result[$key]
                )));
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public function options(
        string $type,
        string $query = '',
        array|string $university = [],
        string $exactValue = '',
        int $limit = 20
    ): array {
        $columns = [
            'university' => 'u.university_name',
            'department' => 'u.department_name',
        ];
        if (!isset($columns[$type])) {
            throw new RuntimeException('Seçenek türü geçersiz.', 422);
        }
        if ($limit < 1 || $limit > 20) {
            throw new RuntimeException('En fazla 20 seçenek istenebilir.', 422);
        }

        $query = trim($query);
        $universities = $this->stringList($university, 'university');
        $exactValue = trim($exactValue);
        foreach ([$query, $exactValue] as $value) {
            if (strlen($value) > 300) {
                throw new RuntimeException('Seçenek araması çok uzun.', 422);
            }
        }

        $column = $columns[$type];
        $where = ["TRIM({$column}) <> ''"];
        $params = [];
        if ($type === 'department' && $universities !== []) {
            $where[] = $this->inCondition(
                'u.university_name', 'option_university', $universities, $params
            );
        }
        if ($exactValue !== '') {
            $where[] = "{$column} = :exact_value";
            $params['exact_value'] = $exactValue;
        } elseif ($query !== '') {
            $params['query'] = $query;
            $strictWhere = [...$where, self::strictAutocompleteSql($column)
                . ' LIKE CONCAT(\'%\', ' . self::strictAutocompleteSql(':query') . ", '%')"];
            $strictMatches = $this->fetchOptions($column, $strictWhere, $params, $limit);
            if ($strictMatches !== []) {
                return $strictMatches;
            }
            $where[] = self::autocompleteSql($column)
                . ' LIKE CONCAT(\'%\', ' . self::autocompleteSql(':query') . ", '%')";
        }

        return $this->fetchOptions($column, $where, $params, $limit);
    }

    /**
     * @return list<string>
     */
    private function fetchOptions(string $column, array $where, array $params, int $limit): array
    {
        $statement = $this->pdo->prepare(
            "SELECT DISTINCT {$column} AS value FROM universities u WHERE "
            . implode(' AND ', $where)
            . " ORDER BY {$column} COLLATE " . self::AUTOCOMPLETE_COLLATION . ' ASC LIMIT :limit'
        );
        $this->bind($statement, $params);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function suggestionCandidates(array $filters, int $limit): array
    {
        [$where, $params] = $this->buildFilters($filters);
        $where[] = 'u.base_rank IS NOT NULL';
        $sql = 'SELECT u.*, ' . self::educationLanguageSql('u') . ' AS education_language '
            . 'FROM universities u WHERE ' . implode(' AND ', $where)
            . ' ORDER BY ABS(u.base_rank - :user_rank) ASC, u.base_rank ASC LIMIT :limit';
        $statement = $this->pdo->prepare($sql);
        $this->bind($statement, $params);
        $statement->bindValue(':user_rank', (int) $filters['user_rank'], PDO::PARAM_INT);
        $statement->bindValue(':limit', min(300, max(30, $limit * 12)), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    private function buildFilters(
        array $filters,
        bool $includeYear = true,
        string $rankColumn = 'u.base_rank'
    ): array
    {
        $where = [];
        $params = [];
        foreach (['university', 'department'] as $name) {
            $rawValue = $filters[$name] ?? '';
            $columnMap = [
                'university' => 'u.university_name', 'department' => 'u.department_name',
            ];
            if (is_array($rawValue)) {
                $values = $this->stringList($rawValue, $name);
                if ($values !== []) {
                    $where[] = $this->inCondition($columnMap[$name], $name, $values, $params);
                }
                continue;
            }

            $value = trim((string) $rawValue);
            if ($value === '') {
                continue;
            }
            $where[] = sprintf(
                "LOWER(%s COLLATE %s) LIKE LOWER(:%s COLLATE %s) ESCAPE '='",
                $columnMap[$name],
                self::TURKISH_COLLATION,
                $name,
                self::TURKISH_COLLATION
            );
            $params[$name] = '%' . $this->escapeLike($value) . '%';
        }

        $cities = $this->stringList($filters['city'] ?? [], 'city');
        if ($cities !== []) {
            $where[] = $this->inCondition('u.city', 'city', $cities, $params);
        }

        $educationLanguages = $this->stringList(
            $filters['education_language'] ?? [],
            'education_language'
        );
        if ($educationLanguages !== []) {
            $where[] = $this->inCondition(
                self::educationLanguageSql('u'),
                'education_language',
                $educationLanguages,
                $params
            );
        }

        foreach (self::ENUM_FILTERS as $name => $allowed) {
            $values = $this->stringList($filters[$name] ?? [], $name);
            if ($values === []) {
                continue;
            }
            foreach ($values as $value) {
                if (!in_array($value, $allowed, true)) {
                    throw new RuntimeException("{$name} filtresi geçersiz.", 422);
                }
            }
            $where[] = $this->inCondition("u.{$name}", $name, $values, $params);
        }

        if ($includeYear) {
            $years = [];
            foreach ($this->stringList($filters['year'] ?? [], 'year') as $value) {
                $years[$this->positiveInt($value, 'year')] = true;
            }
            if ($years !== []) {
                $where[] = $this->inCondition('u.year', 'year', array_keys($years), $params);
            }
        }

        foreach (['min_rank', 'max_rank'] as $name) {
            $value = $filters[$name] ?? '';
            if ($value === '' || $value === null) {
                continue;
            }
            $number = $this->positiveInt($value, $name);
            $column = match ($name) {
                'min_rank' => $rankColumn . ' >= :min_rank',
                default => $rankColumn . ' <= :max_rank',
            };
            $where[] = $column;
            $params[$name] = $number;
        }

        return [$where, $params];
    }

    private function availableYears(): array
    {
        $years = array_map('intval', $this->pdo->query(
            'SELECT DISTINCT year FROM universities ORDER BY year DESC'
        )->fetchAll(PDO::FETCH_COLUMN));

        return array_values(array_unique(array_map(
            static fn (int $year): int => $year === 2026 ? 2025 : $year,
            $years
        )));
    }

    private function latestProgramRowSql(): string
    {
        $priority = static fn (string $alias): string => "CASE {$alias}.year "
            . 'WHEN 2025 THEN 0 WHEN 2026 THEN 1 WHEN 2024 THEN 2 WHEN 2023 THEN 3 ELSE 9 END';

        return 'u.year IN (2023, 2024, 2025, 2026) AND NOT EXISTS ('
            . 'SELECT 1 FROM universities newer WHERE newer.program_code = u.program_code '
            . 'AND newer.year IN (2023, 2024, 2025, 2026) AND '
            . $priority('newer') . ' < ' . $priority('u') . ')';
    }

    private function rankFilterColumnForSort(string $sort, string $ranking2025): string
    {
        return match (true) {
            str_starts_with($sort, 'rank_2024_') => 'u24.base_rank',
            str_starts_with($sort, 'rank_2023_') => 'u23.base_rank',
            default => $ranking2025,
        };
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

    private function escapeLike(string $value): string
    {
        return str_replace(['=', '%', '_'], ['==', '=%', '=_'], $value);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value, string $name): array
    {
        $items = is_array($value) ? $value : [$value];
        if (count($items) > 100) {
            throw new RuntimeException("{$name} filtresinde en fazla 100 değer kullanılabilir.", 422);
        }

        $values = [];
        foreach ($items as $item) {
            if (!is_string($item) && !is_numeric($item)) {
                throw new RuntimeException("{$name} filtresi geçersiz.", 422);
            }
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }
            if (strlen($item) > 300) {
                throw new RuntimeException("{$name} filtresi çok uzun.", 422);
            }
            $values[$item] = true;
        }

        return array_keys($values);
    }

    private function inCondition(
        string $column,
        string $prefix,
        array $values,
        array &$params
    ): string {
        $placeholders = [];
        foreach ($values as $index => $value) {
            $name = $prefix . '_' . $index;
            $placeholders[] = ':' . $name;
            $params[$name] = $value;
        }

        return $column . ' IN (' . implode(', ', $placeholders) . ')';
    }

    private static function educationLanguageSql(string $alias = ''): string
    {
        $column = $alias === '' ? 'education_language' : $alias . '.education_language';

        return str_replace('education_language', $column, self::EDUCATION_LANGUAGE_SQL);
    }

    private static function autocompleteSql(string $value): string
    {
        $normalized = "LOWER({$value} COLLATE " . self::AUTOCOMPLETE_COLLATION . ')';
        foreach ([
            'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u',
            'â' => 'a', 'î' => 'i', 'û' => 'u',
        ] as $from => $to) {
            $normalized = "REPLACE({$normalized}, '{$from}', '{$to}')";
        }

        return "REGEXP_REPLACE({$normalized}, '[^[:alnum:]]+', '')";
    }

    private static function strictAutocompleteSql(string $value): string
    {
        return "REGEXP_REPLACE(LOWER({$value} COLLATE " . self::TURKISH_COLLATION
            . "), '[^[:alnum:]]+', '')";
    }
}
