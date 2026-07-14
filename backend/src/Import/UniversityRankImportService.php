<?php

declare(strict_types=1);

namespace DersRotasi\Import;

use RuntimeException;
use Throwable;

final class UniversityRankImportService
{
    public function __construct(private readonly UniversityRankStore $store)
    {
    }

    public function run(array $rows, bool $apply, callable $confirm): array
    {
        $result = [
            'counts' => [
                'records_to_update' => 0,
                'unchanged_records' => 0,
                'unmatched_program_codes' => 0,
                'conflicting_existing_values' => 0,
                'updated_records' => 0,
            ],
            'details' => ['updates' => [], 'unchanged' => [], 'unmatched' => [], 'conflicts' => []],
            'applied' => false,
            'cancelled' => false,
        ];

        $this->store->beginTransaction();
        try {
            $plannedUpdates = [];
            foreach ($rows as $row) {
                $existing = $this->store->find($row['program_code'], $row['year']);
                if ($existing === null) {
                    $result['counts']['unmatched_program_codes']++;
                    $result['details']['unmatched'][] = [
                        'line' => $row['line'],
                        'program_code' => $row['program_code'],
                        'year' => $row['year'],
                    ];
                    continue;
                }

                $oldRank = $existing['base_rank'] === null ? null : (int) $existing['base_rank'];
                if ($oldRank === $row['base_rank']) {
                    $result['counts']['unchanged_records']++;
                    $result['details']['unchanged'][] = [
                        'line' => $row['line'],
                        'program_code' => $row['program_code'],
                        'year' => $row['year'],
                        'base_rank' => $oldRank,
                    ];
                    continue;
                }

                $change = [
                    'line' => $row['line'],
                    'program_code' => $row['program_code'],
                    'year' => $row['year'],
                    'old_base_rank' => $oldRank,
                    'new_base_rank' => $row['base_rank'],
                ];
                $result['counts']['records_to_update']++;
                $result['details']['updates'][] = $change;
                if ($oldRank !== null) {
                    $result['counts']['conflicting_existing_values']++;
                    $result['details']['conflicts'][] = $change;
                }
                $plannedUpdates[] = ['existing' => $existing, 'row' => $row];
            }

            if (!$apply) {
                $this->store->rollBack();
                return $result;
            }
            if ($plannedUpdates !== [] && !$confirm($result)) {
                $this->store->rollBack();
                $result['cancelled'] = true;
                return $result;
            }

            foreach ($plannedUpdates as $planned) {
                $existing = $planned['existing'];
                $row = $planned['row'];
                $this->store->updateRank(
                    (int) $existing['id'],
                    $row['program_code'],
                    $row['year'],
                    $row['base_rank'],
                    $row['source_name'],
                    $row['source_url']
                );
                $result['counts']['updated_records']++;
            }
            $this->store->commit();
            $result['applied'] = true;

            return $result;
        } catch (Throwable $exception) {
            if ($this->store->inTransaction()) {
                $this->store->rollBack();
            }
            throw new RuntimeException('Başarı sırası import transaction işlemi geri alındı.', 0, $exception);
        }
    }
}
