<?php

declare(strict_types=1);

namespace DersRotasi\Repositories;

use PDO;

final class YksRankDataRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function points(int $year, string $scoreType): array
    {
        $map = ['SAY' => 'say', 'EA' => 'ea', 'SÖZ' => 'soz', 'DİL' => 'dil', 'TYT' => 'tyt'];
        if (!isset($map[$scoreType])) {
            return [];
        }
        $statement = $this->pdo->prepare(
            'SELECT base_score, base_rank FROM universities '
            . 'WHERE year = :year AND score_type = :score_type '
            . 'AND base_score IS NOT NULL AND base_rank IS NOT NULL '
            . 'AND base_score BETWEEN 100 AND 600 AND base_rank BETWEEN 1 AND 5000000 '
            . 'ORDER BY base_score ASC'
        );
        $statement->execute(['year' => $year, 'score_type' => $map[$scoreType]]);
        return $statement->fetchAll();
    }
}
