<?php

declare(strict_types=1);

namespace DersRotasi\Historical;

use DersRotasi\Import\EducationLanguageNormalizer;

final class HistoricalProgramMatcher
{
    private const SCORE_DELTA_LIMIT = 100.0;
    private const QUOTA_RATIO_LIMIT = 4.0;

    /**
     * @param list<array<string, mixed>> $currentRows
     * @param list<array<string, mixed>> $historicalRows
     * @return array{matches: list<array<string, mixed>>, ambiguous: list<array<string, mixed>>, unmatched: list<array<string, mixed>>, manual_review: list<array<string, mixed>>}
     */
    public function analyze(array $currentRows, array $historicalRows, int $historicalYear): array
    {
        $historicalBySignature = [];
        $historicalBySignatureWithoutDuration = [];
        foreach ($historicalRows as $historicalRow) {
            $normalized = $this->normalized($historicalRow);
            $candidate = [
                'row' => $historicalRow,
                'normalized' => $normalized,
            ];
            $historicalBySignature[$this->signature($normalized)][] = $candidate;
            $historicalBySignatureWithoutDuration[$this->signatureWithoutDuration($normalized)][] = $candidate;
        }

        $provisional = [];
        $ambiguous = [];
        $unmatched = [];
        $manualReview = [];
        foreach ($currentRows as $currentRow) {
            $currentCode = (string) ($currentRow['program_code'] ?? '');
            $currentNormalized = $this->normalized($currentRow);
            $missingFields = $this->missingRequiredFields($currentNormalized);
            if ($missingFields !== []) {
                if ($missingFields === ['duration']) {
                    $reviewCandidates = $this->reviewCandidates(
                        $currentRow,
                        $currentNormalized,
                        $historicalBySignatureWithoutDuration,
                    );
                    if ($reviewCandidates !== []) {
                        $manualReview[] = [
                            'current_program_code' => $currentCode,
                            'historical_year' => $historicalYear,
                            'university_name' => (string) ($currentRow['university_name'] ?? ''),
                            'faculty_name' => (string) ($currentRow['faculty_name'] ?? ''),
                            'program_name' => (string) ($currentRow['department_name'] ?? ''),
                            'reason' => 'current_duration_missing_not_automatic',
                            'candidates' => $reviewCandidates,
                        ];
                    }
                }
                $unmatched[] = $this->unmatched(
                    $currentRow,
                    $historicalYear,
                    'missing_required_fields',
                    ['fields' => $missingFields],
                );
                continue;
            }

            $signatureCandidates = $historicalBySignature[$this->signature($currentNormalized)] ?? [];
            $eligible = [];
            $rejected = [];
            foreach ($signatureCandidates as $candidate) {
                $historicalRow = $candidate['row'];
                if ((string) ($historicalRow['program_code'] ?? '') === $currentCode) {
                    continue;
                }
                $consistency = $this->consistency($currentRow, $historicalRow);
                if (!$consistency['accepted']) {
                    $rejected[] = [
                        'historical_program_code' => (string) ($historicalRow['program_code'] ?? ''),
                        'reasons' => $consistency['reasons'],
                    ];
                    continue;
                }
                $eligible[] = [
                    'row' => $historicalRow,
                    'normalized' => $candidate['normalized'],
                    'consistency' => $consistency,
                ];
            }

            if (count($eligible) > 1) {
                $ambiguous[] = $this->ambiguous(
                    $currentRow,
                    $historicalYear,
                    'multiple_historical_candidates',
                    $eligible,
                );
                continue;
            }
            if ($eligible === []) {
                $unmatched[] = $this->unmatched(
                    $currentRow,
                    $historicalYear,
                    $signatureCandidates === [] ? 'no_normalized_candidate' : 'consistency_rejected',
                    ['rejected_candidates' => $rejected],
                );
                continue;
            }

            $candidate = $eligible[0];
            $historicalCode = (string) $candidate['row']['program_code'];
            $provisional[$currentCode] = $this->match(
                $currentRow,
                $candidate['row'],
                $currentNormalized,
                $historicalYear,
                $candidate['consistency'],
            );
        }

        $currentCodesByHistoricalCode = [];
        foreach ($provisional as $currentCode => $match) {
            $currentCodesByHistoricalCode[$match['historical_program_code']][] = $currentCode;
        }
        foreach ($currentCodesByHistoricalCode as $historicalCode => $currentCodes) {
            if (count($currentCodes) < 2) {
                continue;
            }
            foreach ($currentCodes as $currentCode) {
                $match = $provisional[$currentCode];
                unset($provisional[$currentCode]);
                $ambiguous[] = [
                    'current_program_code' => $currentCode,
                    'historical_year' => $historicalYear,
                    'university_name' => $match['university_name'],
                    'faculty_name' => $match['faculty_name'],
                    'program_name' => $match['program_name'],
                    'reason' => 'historical_candidate_shared_by_multiple_current_programs',
                    'candidates' => [[
                        'historical_program_code' => $historicalCode,
                        'competing_current_program_codes' => $currentCodes,
                    ]],
                ];
            }
        }

        ksort($provisional);
        usort($ambiguous, $this->sorter(...));
        usort($unmatched, $this->sorter(...));
        usort($manualReview, $this->sorter(...));

        return [
            'matches' => array_values($provisional),
            'ambiguous' => $ambiguous,
            'unmatched' => $unmatched,
            'manual_review' => $manualReview,
        ];
    }

    /** @return array<string, string|int> */
    public function normalized(array $row): array
    {
        $city = $this->text((string) ($row['city'] ?? ''));

        return [
            'university' => $this->university((string) ($row['university_name'] ?? ''), $city),
            'faculty' => $this->faculty((string) ($row['faculty_name'] ?? '')),
            'program' => $this->text((string) ($row['department_name'] ?? $row['program_name'] ?? '')),
            'language' => $this->text(EducationLanguageNormalizer::normalize(
                (string) ($row['department_name'] ?? $row['program_name'] ?? ''),
                isset($row['education_language']) ? (string) $row['education_language'] : null,
            )),
            'scholarship' => $this->enum((string) ($row['scholarship_type'] ?? '')),
            'education_type' => $this->enum((string) ($row['education_type'] ?? '')),
            'score_type' => $this->enum((string) ($row['score_type'] ?? '')),
            'city' => $city,
            'duration' => $this->positiveInt($row['duration_years'] ?? null),
        ];
    }

    /** @param array<string, string|int> $normalized */
    private function signature(array $normalized): string
    {
        return implode("\x1F", array_map('strval', [
            $normalized['university'],
            $normalized['faculty'],
            $normalized['program'],
            $normalized['language'],
            $normalized['scholarship'],
            $normalized['education_type'],
            $normalized['score_type'],
            $normalized['city'],
            $normalized['duration'],
        ]));
    }

    /** @param array<string, string|int> $normalized */
    private function signatureWithoutDuration(array $normalized): string
    {
        unset($normalized['duration']);
        return implode("\x1F", array_map('strval', array_values($normalized)));
    }

    /**
     * @param array<string, string|int> $currentNormalized
     * @param array<string, list<array{row: array<string, mixed>, normalized: array<string, string|int>}>> $historicalIndex
     * @return list<array<string, mixed>>
     */
    private function reviewCandidates(
        array $current,
        array $currentNormalized,
        array $historicalIndex,
    ): array {
        $currentCode = (string) ($current['program_code'] ?? '');
        $candidates = [];
        foreach ($historicalIndex[$this->signatureWithoutDuration($currentNormalized)] ?? [] as $candidate) {
            $historical = $candidate['row'];
            if ((string) ($historical['program_code'] ?? '') === $currentCode) {
                continue;
            }
            $consistency = $this->consistency($current, $historical);
            if (!$consistency['accepted']) {
                continue;
            }
            $candidates[] = [
                'historical_program_code' => (string) ($historical['program_code'] ?? ''),
                'historical_duration' => $candidate['normalized']['duration'],
                'base_rank' => $historical['base_rank'] ?? null,
                'base_score' => $historical['base_score'] ?? null,
                'quota' => $historical['quota'] ?? null,
            ];
        }
        return $candidates;
    }

    /** @param array<string, string|int> $normalized @return list<string> */
    private function missingRequiredFields(array $normalized): array
    {
        $missing = [];
        foreach ($normalized as $field => $value) {
            if ($value === '' || ($field === 'duration' && (int) $value < 1)) {
                $missing[] = $field;
            }
        }
        return $missing;
    }

    /** @return array{accepted: bool, reasons: list<string>, score_delta: ?float, quota_ratio: ?float} */
    private function consistency(array $current, array $historical): array
    {
        $reasons = [];
        $currentScore = $this->positiveFloat($current['base_score'] ?? null);
        $historicalScore = $this->positiveFloat($historical['base_score'] ?? null);
        $scoreDelta = $currentScore !== null && $historicalScore !== null
            ? abs($currentScore - $historicalScore)
            : null;
        if ($scoreDelta !== null && $scoreDelta > self::SCORE_DELTA_LIMIT) {
            $reasons[] = 'base_score_delta_exceeds_limit';
        }

        $currentQuota = $this->positiveInt($current['quota'] ?? null);
        $historicalQuota = $this->positiveInt($historical['quota'] ?? null);
        $quotaRatio = $currentQuota > 0 && $historicalQuota > 0
            ? max($currentQuota, $historicalQuota) / min($currentQuota, $historicalQuota)
            : null;
        if ($quotaRatio !== null && $quotaRatio > self::QUOTA_RATIO_LIMIT) {
            $reasons[] = 'quota_ratio_exceeds_limit';
        }

        return [
            'accepted' => $reasons === [],
            'reasons' => $reasons,
            'score_delta' => $scoreDelta === null ? null : round($scoreDelta, 5),
            'quota_ratio' => $quotaRatio === null ? null : round($quotaRatio, 3),
        ];
    }

    /** @param array<string, string|int> $normalized @param array<string, mixed> $consistency */
    private function match(
        array $current,
        array $historical,
        array $normalized,
        int $historicalYear,
        array $consistency,
    ): array {
        return [
            'current_program_code' => (string) $current['program_code'],
            'historical_program_code' => (string) $historical['program_code'],
            'historical_year' => $historicalYear,
            'confidence' => 'high',
            'verification_status' => 'verified',
            'match_method' => 'strict_normalized_v1',
            'university_name' => (string) $current['university_name'],
            'faculty_name' => (string) $current['faculty_name'],
            'program_name' => (string) ($current['department_name'] ?? ''),
            'reason' => 'all_required_normalized_fields_equal_and_candidate_unique',
            'evidence' => [
                'normalized' => $normalized,
                'current_base_score' => $this->positiveFloat($current['base_score'] ?? null),
                'historical_base_score' => $this->positiveFloat($historical['base_score'] ?? null),
                'score_delta' => $consistency['score_delta'],
                'current_quota' => $this->positiveInt($current['quota'] ?? null) ?: null,
                'historical_quota' => $this->positiveInt($historical['quota'] ?? null) ?: null,
                'quota_ratio' => $consistency['quota_ratio'],
            ],
        ];
    }

    /** @param list<array<string, mixed>> $candidates */
    private function ambiguous(array $current, int $historicalYear, string $reason, array $candidates): array
    {
        return [
            'current_program_code' => (string) ($current['program_code'] ?? ''),
            'historical_year' => $historicalYear,
            'university_name' => (string) ($current['university_name'] ?? ''),
            'faculty_name' => (string) ($current['faculty_name'] ?? ''),
            'program_name' => (string) ($current['department_name'] ?? ''),
            'reason' => $reason,
            'candidates' => array_map(static fn (array $candidate): array => [
                'historical_program_code' => (string) $candidate['row']['program_code'],
                'base_rank' => $candidate['row']['base_rank'] ?? null,
                'base_score' => $candidate['row']['base_score'] ?? null,
                'quota' => $candidate['row']['quota'] ?? null,
            ], $candidates),
        ];
    }

    private function unmatched(array $current, int $historicalYear, string $reason, array $details): array
    {
        return [
            'current_program_code' => (string) ($current['program_code'] ?? ''),
            'historical_year' => $historicalYear,
            'university_name' => (string) ($current['university_name'] ?? ''),
            'faculty_name' => (string) ($current['faculty_name'] ?? ''),
            'program_name' => (string) ($current['department_name'] ?? ''),
            'reason' => $reason,
            ...$details,
        ];
    }

    private function university(string $value, string $normalizedCity): string
    {
        if (preg_match('/^(.*)\s+\(([^()]*)\)\s*$/u', trim($value), $matches) === 1
            && $this->text($matches[2]) === $normalizedCity) {
            $value = $matches[1];
        }
        return $this->text($value);
    }

    private function faculty(string $value): string
    {
        $value = $this->text($value);
        $value = (string) preg_replace('/\bfak\b/u', 'fakultesi', $value);
        $value = (string) preg_replace('/\bmyo\b/u', 'meslek yuksekokulu', $value);
        return str_replace('yuksek okulu', 'yuksekokulu', $value);
    }

    private function text(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, [
            'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'i̇' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u',
            'â' => 'a', 'î' => 'i', 'û' => 'u', 'é' => 'e', '&' => ' ve ',
        ]);
        $value = (string) preg_replace('/[^a-z0-9]+/u', ' ', $value);
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function enum(string $value): string
    {
        return strtolower(trim($value));
    }

    private function positiveInt(mixed $value): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : 0;
    }

    private function positiveFloat(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    private function sorter(array $left, array $right): int
    {
        return [$left['current_program_code'], $left['historical_year']]
            <=> [$right['current_program_code'], $right['historical_year']];
    }
}
