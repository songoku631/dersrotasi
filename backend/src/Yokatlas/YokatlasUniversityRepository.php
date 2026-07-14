<?php

declare(strict_types=1);

namespace DersRotasi\Yokatlas;

use PDO;
use RuntimeException;
use Throwable;

final class YokatlasUniversityRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function candidates(
        int $year,
        int $limit,
        int $offset,
        bool $onlyMissing,
        ?string $programCode,
        array $cursor = []
    ): array {
        $innerWhere = ['u.year = :year'];
        $params = ['year' => $year];
        if ($programCode !== null) {
            $innerWhere[] = 'u.program_code = :program_code';
            $params['program_code'] = $programCode;
        }
        $columns = 'u.id, u.program_code, u.university_name, u.department_name, u.base_score, '
            . 'u.base_rank, u.score_type, u.university_type, u.duration_years, u.year';
        $outerWhere = [];
        if ($onlyMissing) {
            $outerWhere[] = 'base_rank IS NULL';
        }
        $cursorRow = max(0, (int) ($cursor['sample_row'] ?? 0));
        $cursorScore = max(0, (int) ($cursor['score_order'] ?? 0));
        $cursorType = max(0, (int) ($cursor['type_order'] ?? 0));
        $cursorId = max(0, (int) ($cursor['id'] ?? 0));
        $outerWhere[] = '(sample_row, score_order, type_order, id) '
            . '> (:cursor_row, :cursor_score, :cursor_type, :cursor_id)';
        $params += [
            'cursor_row' => $cursorRow, 'cursor_score' => $cursorScore,
            'cursor_type' => $cursorType, 'cursor_id' => $cursorId,
        ];
        $sql = 'WITH ranked_candidates AS ('
            . 'SELECT ' . $columns . ', ROW_NUMBER() OVER ('
            . 'PARTITION BY u.score_type, u.university_type ORDER BY CRC32(u.program_code), u.id'
            . ') AS sample_row, '
            . "FIELD(u.score_type, 'say', 'ea', 'soz', 'dil', 'tyt') AS score_order, "
            . "FIELD(u.university_type, 'devlet', 'vakif', 'kktc', 'yabanci') AS type_order "
            . 'FROM universities u WHERE ' . implode(' AND ', $innerWhere)
            . ') SELECT * FROM ranked_candidates WHERE ' . implode(' AND ', $outerWhere)
            . ' ORDER BY sample_row, score_order, type_order, id LIMIT :limit OFFSET :offset';

        $statement = $this->pdo->prepare($sql);
        foreach ($params as $name => $value) {
            $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function assertApplySchemaReady(): void
    {
        try {
            $this->pdo->query(
                'SELECT base_rank, rank_source_name, rank_source_url, rank_updated_at '
                . 'FROM universities WHERE 1 = 0'
            );
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Apply için önce 004_add_university_rank_sources.sql migrationı çalıştırılmalıdır.',
                0,
                $exception
            );
        }
    }

    public function programsByCodes(int $year, array $programCodes): array
    {
        $programCodes = array_values(array_unique(array_filter(
            array_map('strval', $programCodes),
            static fn (string $code): bool => preg_match('/^[0-9]{9}$/', $code) === 1
        )));
        if ($programCodes === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($programCodes), '?'));
        $statement = $this->pdo->prepare(
            'SELECT id, program_code, university_name, department_name, base_score, base_rank, '
            . 'score_type, university_type, duration_years, year FROM universities '
            . "WHERE year = ? AND program_code IN ({$placeholders})"
        );
        $statement->execute([$year, ...$programCodes]);
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $result[(string) $row['program_code']] = $row;
        }
        return $result;
    }

    public function apply(array $updates): array
    {
        $result = ['updated' => 0, 'concurrent_conflicts' => 0];
        if ($updates === []) {
            return $result;
        }

        try {
            $this->pdo->beginTransaction();
            $statement = $this->pdo->prepare(
                'UPDATE universities SET '
                . 'base_rank = :base_rank, rank_source_name = :source_name, '
                . 'rank_source_url = :source_url, rank_updated_at = :fetched_at '
                . 'WHERE id = :id AND program_code = :program_code AND year = :year '
                . 'AND base_rank IS NULL'
            );
            foreach ($updates as $update) {
                $statement->execute([
                    'base_rank' => $update['base_rank'],
                    'source_name' => $update['source_name'],
                    'source_url' => $update['source_url'],
                    'fetched_at' => $update['fetched_at'],
                    'id' => $update['id'],
                    'program_code' => $update['program_code'],
                    'year' => $update['year'],
                ]);
                if ($statement->rowCount() === 1) {
                    $result['updated']++;
                } else {
                    $result['concurrent_conflicts']++;
                }
            }
            $this->pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException('YÖK Atlas güncellemeleri geri alındı.', 0, $exception);
        }
    }
}
