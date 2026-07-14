<?php

declare(strict_types=1);

namespace DersRotasi\Yokatlas;

final class YokatlasResponseValidator
{
    public const SCORE_TOLERANCE = 0.02;

    public function validate(array $response, array $university, string $sourceUrl, string $fetchedAt): array
    {
        $items = isset($response['content']) && is_array($response['content'])
            ? $response['content']
            : [];
        $matching = array_values(array_filter(
            $items,
            static fn (mixed $item): bool => is_array($item)
                && (string) ($item['kilavuzKodu'] ?? '') === (string) $university['program_code']
        ));
        if ($matching === []) {
            return $this->result('unmatched', 'Program kodu resmi yanıtta bulunamadı.');
        }
        if (count($matching) !== 1) {
            return $this->result('parse_error', 'Program kodu için birden fazla resmi kayıt döndü.');
        }

        return $this->validateItem($matching[0], $university, $sourceUrl, $fetchedAt);
    }

    public function validateItem(array $item, array $university, string $sourceUrl, string $fetchedAt): array
    {
        if ((string) ($item['kilavuzKodu'] ?? '') !== (string) $university['program_code']) {
            return $this->result('unmatched', 'Program kodu resmi yanıtla eşleşmedi.');
        }
        if ((int) ($item['yil'] ?? 0) !== (int) $university['year']) {
            return $this->result('year_mismatch', 'Resmi yanıttaki veri yılı 2025 değil.');
        }

        $rank = $this->normalizeRank($item['basariSirasi'] ?? null);
        if ($rank === false) {
            return $this->result('parse_error', 'basariSirasi pozitif tam sayı veya boş değer olarak çözümlenemedi.');
        }
        if ($rank === null) {
            return [
                'status' => 'rank_missing', 'reason' => 'Başarı sırası oluşmamış veya resmi yanıtta boş.',
                'base_rank' => null, 'year' => (int) $university['year'],
                'source_name' => 'YÖK Atlas 2025', 'source_url' => $sourceUrl, 'fetched_at' => $fetchedAt,
            ];
        }

        if (!$this->namesMatch((string) $university['university_name'], (string) ($item['universiteAdi'] ?? ''))
            || !$this->namesMatch((string) $university['department_name'], (string) ($item['birimGrupAdi'] ?? ''))) {
            return [
                'status' => 'name_mismatch',
                'reason' => 'Üniversite veya bölüm adı güvenli eşleşme eşiğini geçmedi.',
                'base_rank' => $rank,
            ];
        }

        $officialScore = $this->normalizeScore($item['minPuan'] ?? null);
        $existingScore = $this->normalizeScore($university['base_score'] ?? null);
        if ($officialScore === null || $existingScore === null
            || abs($officialScore - $existingScore) > self::SCORE_TOLERANCE) {
            return [
                'status' => 'score_mismatch',
                'reason' => 'YÖK Atlas taban puanı mevcut ÖSYM taban puanıyla tolerans içinde eşleşmedi.',
                'official_base_score' => $officialScore,
                'existing_base_score' => $existingScore,
                'base_rank' => $rank,
            ];
        }

        return [
            'status' => 'valid', 'reason' => null, 'base_rank' => $rank,
            'year' => (int) $university['year'], 'source_name' => 'YÖK Atlas 2025',
            'source_url' => $sourceUrl, 'fetched_at' => $fetchedAt,
            'official_base_score' => $officialScore,
        ];
    }

    private function normalizeRank(mixed $value): int|null|false
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '' || in_array(mb_strtolower($text, 'UTF-8'), ['dolmadı', '—', '-', '---', 'veri yok'], true)) {
            return null;
        }
        if (is_int($value) || (is_float($value) && floor($value) === $value)) {
            return (int) $value > 0 ? (int) $value : false;
        }
        if (!preg_match('/^(?:[0-9]+|[0-9]{1,3}(?:[., ]?[0-9]{3})+)$/', $text)) {
            return false;
        }
        $rank = (int) str_replace(['.', ',', ' '], '', $text);
        return $rank > 0 ? $rank : false;
    }

    private function normalizeScore(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $text = str_replace([' ', "\u{00A0}"], '', trim((string) $value));
        if (str_contains($text, ',') && str_contains($text, '.')) {
            $text = strrpos($text, ',') > strrpos($text, '.')
                ? str_replace(',', '.', str_replace('.', '', $text))
                : str_replace(',', '', $text);
        } elseif (str_contains($text, ',')) {
            $text = str_replace(',', '.', $text);
        }
        return is_numeric($text) ? (float) $text : null;
    }

    private function namesMatch(string $existing, string $official): bool
    {
        $left = $this->normalizeName($existing);
        $right = $this->normalizeName($official);
        return $left !== '' && $right !== ''
            && (str_contains($left, $right) || str_contains($right, $left));
    }

    private function normalizeName(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/\([^)]*\)/u', ' ', $value) ?? $value;
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function result(string $status, string $reason): array
    {
        return ['status' => $status, 'reason' => $reason];
    }
}
