<?php

declare(strict_types=1);

namespace DersRotasi\Repositories;

use PDO;

final class YksBacktestRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function usableRankRows(): array
    {
        $statement = $this->pdo->query(
            'SELECT program_code, year, score_type, base_score, base_rank '
            . 'FROM universities '
            . "WHERE score_type IN ('say', 'ea', 'soz', 'dil', 'tyt') "
            . 'AND year BETWEEN 2000 AND 2100 '
            . 'AND base_score BETWEEN 100 AND 600 '
            . 'AND base_rank BETWEEN 1 AND 5000000 '
            . 'ORDER BY score_type, year, base_score'
        );
        return $statement->fetchAll();
    }
}
