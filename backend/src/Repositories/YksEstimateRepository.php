<?php

declare(strict_types=1);

namespace DersRotasi\Repositories;

use PDO;

final class YksEstimateRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function save(string $firebaseUid, array $input, array $result): array
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO yks_estimates '
            . '(firebase_uid, exam_year, score_type, input_data_json, calculated_nets_json, raw_score, placement_score, '
            . 'estimated_rank_center, estimated_rank_min, estimated_rank_max, confidence, created_at, updated_at) '
            . 'VALUES (:firebase_uid, :exam_year, :score_type, :input_data_json, :calculated_nets_json, :raw_score, :placement_score, '
            . ':estimated_rank_center, :estimated_rank_min, :estimated_rank_max, :confidence, NOW(), NOW())'
        );
        $statement->execute([
            'firebase_uid' => $firebaseUid,
            'exam_year' => $result['year'],
            'score_type' => $result['score_type'],
            'input_data_json' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'calculated_nets_json' => json_encode($result['nets'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'raw_score' => $result['scores']['raw_score'],
            'placement_score' => $result['scores']['placement_score'],
            'estimated_rank_center' => $result['rank_estimate']['center'],
            'estimated_rank_min' => $result['rank_estimate']['min'],
            'estimated_rank_max' => $result['rank_estimate']['max'],
            'confidence' => $result['confidence'],
        ]);

        return ['id' => (int) $this->pdo->lastInsertId(), 'created_at' => date(DATE_ATOM)] + $result;
    }

    public function all(string $firebaseUid, int $limit = 20): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, exam_year AS year, score_type, calculated_nets_json, raw_score, placement_score, '
            . 'estimated_rank_center, estimated_rank_min, estimated_rank_max, confidence, created_at '
            . 'FROM yks_estimates WHERE firebase_uid = :firebase_uid ORDER BY created_at DESC, id DESC LIMIT :limit'
        );
        $statement->bindValue(':firebase_uid', $firebaseUid);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        $items = $statement->fetchAll();
        foreach ($items as &$item) {
            $item['nets'] = json_decode((string) $item['calculated_nets_json'], true) ?: [];
            unset($item['calculated_nets_json']);
        }
        unset($item);
        return $items;
    }
}
