<?php

declare(strict_types=1);

namespace DersRotasi\Yokatlas;

use DersRotasi\Import\EducationLanguageNormalizer;
use InvalidArgumentException;

final class HistoricalUniversityMapper
{
    /**
     * @return array<string, mixed>|null
     */
    public function map(array $item, int $targetYear, string $fetchedAt): ?array
    {
        $guideYear = $this->positiveInteger($item['yil'] ?? null, 'yil');
        $offset = $guideYear - $targetYear;
        if (!in_array($offset, [1, 2, 3, 4], true)) {
            throw new InvalidArgumentException(
                "{$targetYear} yılı, resmi {$guideYear} kılavuz yanıtındaki historical alanlarla eşlenemiyor."
            );
        }

        [$scoreField, $rankField, $quotaField, $placedField] = match ($offset) {
            1 => ['minPuan', 'basariSirasi', 'gk1', 'gkY1'],
            2 => ['minPuan1', 'basariSirasi1', 'gk2', null],
            3 => ['minPuan2', 'basariSirasi2', 'gk3', null],
            4 => ['minPuan3', 'basariSirasi3', null, null],
        };

        $baseScore = $this->nullableDecimal($item[$scoreField] ?? null);
        $baseRank = $this->nullableInteger($item[$rankField] ?? null, false);
        $quota = $quotaField === null
            ? null
            : $this->nullableInteger($item[$quotaField] ?? null, true);
        $placedCount = $placedField === null
            ? null
            : $this->nullableInteger($item[$placedField] ?? null, true);

        if ($baseScore === null && $baseRank === null && ($quota === null || $quota === 0)) {
            return null;
        }

        $programCode = trim((string) ($item['kilavuzKodu'] ?? ''));
        if (preg_match('/^[0-9]{9}$/', $programCode) !== 1) {
            throw new InvalidArgumentException('kilavuzKodu tam olarak 9 rakam olmalıdır.');
        }

        $universityName = $this->requiredText($item, 'universiteAdi');
        $departmentName = $this->requiredText($item, 'birimAdi', 'birimGrupAdi');
        $universityType = $this->universityType($item['universiteTuru'] ?? null);
        $scoreType = $this->scoreType($item['puanTuru'] ?? null);
        $duration = $this->nullableInteger($item['ogrenimSuresi'] ?? null, true);
        $city = $this->city($item, $universityType);
        $sourceUrl = 'https://yokatlas.yok.gov.tr/'
            . (($duration === 2 || $scoreType === 'tyt') ? 'onlisans.php' : 'lisans.php')
            . '?y=' . $programCode;
        $sourceName = sprintf(
            'YÖK Atlas %d (historical fields in %d guide)',
            $targetYear,
            $guideYear
        );

        return [
            'program_code' => $programCode,
            'university_name' => $universityName,
            'faculty_name' => trim((string) ($item['fymkAdi'] ?? '')),
            'department_name' => $departmentName,
            'city' => $city,
            'university_type' => $universityType,
            'score_type' => $scoreType,
            'education_type' => $this->educationType($item['ogrenimTuruAdi'] ?? null),
            'education_language' => EducationLanguageNormalizer::normalize(
                $departmentName,
                isset($item['ogrenimDiliAdi']) ? (string) $item['ogrenimDiliAdi'] : null,
            ),
            'scholarship_type' => $this->scholarshipType(
                $item['bursOraniAdi'] ?? null,
                $universityType,
            ),
            'base_score' => $baseScore,
            'base_rank' => $baseRank,
            'rank_source_name' => $baseRank === null ? null : $sourceName,
            'rank_source_url' => $baseRank === null ? null : $sourceUrl,
            'rank_updated_at' => $baseRank === null ? null : $fetchedAt,
            'quota' => $quota,
            'placed_count' => $placedCount,
            'duration_years' => $duration,
            'year' => $targetYear,
            'source_name' => $sourceName,
            'source_url' => $sourceUrl,
            'guide_year' => $guideYear,
            'score_field' => $scoreField,
            'rank_field' => $rankField,
            'quota_field' => $quotaField,
            'metadata_basis' => "current_guide_{$guideYear}",
        ];
    }

    private function requiredText(array $item, string $field, ?string $fallback = null): string
    {
        $value = trim((string) ($item[$field] ?? ''));
        if ($value === '' && $fallback !== null) {
            $value = trim((string) ($item[$fallback] ?? ''));
        }
        if ($value === '') {
            throw new InvalidArgumentException("{$field} alanı boş.");
        }
        return $value;
    }

    private function city(array $item, string $universityType): string
    {
        foreach (['ilAdi', 'uniIlAdi', 'fymkIlAdi'] as $field) {
            $city = trim((string) ($item[$field] ?? ''));
            if ($city !== '') {
                return $city;
            }
        }
        if ($universityType === 'yabanci') {
            return 'YURTDIŞI';
        }
        throw new InvalidArgumentException('Program şehri resmi yanıtta bulunamadı.');
    }

    private function universityType(mixed $value): string
    {
        $value = $this->upper((string) $value);
        return match (true) {
            $value === 'DEVLET' => 'devlet',
            $value === 'VAKIF' => 'vakif',
            str_contains($value, 'KKTC') => 'kktc',
            str_contains($value, 'YABANCI'), str_contains($value, 'YURT') => 'yabanci',
            default => throw new InvalidArgumentException("Bilinmeyen üniversite türü: {$value}"),
        };
    }

    private function scoreType(mixed $value): string
    {
        return match ($this->upper((string) $value)) {
            'SAY' => 'say',
            'EA' => 'ea',
            'SÖZ' => 'soz',
            'DİL' => 'dil',
            'TYT' => 'tyt',
            default => throw new InvalidArgumentException('Bilinmeyen puan türü.'),
        };
    }

    private function educationType(mixed $value): string
    {
        $value = $this->upper((string) $value);
        return match (true) {
            str_contains($value, 'İKİNCİ') => 'ikinci_ogretim',
            str_contains($value, 'UZAKTAN') => 'uzaktan',
            str_contains($value, 'AÇIK') => 'acikogretim',
            str_contains($value, 'ÖRGÜN') => 'orgun',
            default => 'diger',
        };
    }

    private function scholarshipType(mixed $value, string $universityType): string
    {
        $value = $this->upper((string) $value);
        if ($value === '') {
            return $universityType === 'devlet' ? 'ucretsiz' : 'diger';
        }
        return match (true) {
            str_contains($value, '%50'), str_contains($value, '50 İNDİR') => 'yuzde_50',
            str_contains($value, '%25'), str_contains($value, '25 İNDİR') => 'yuzde_25',
            str_contains($value, 'BURSLU') => 'burslu',
            str_contains($value, 'ÜCRETSİZ') => 'ucretsiz',
            str_contains($value, 'ÜCRETLİ') => 'ucretli',
            default => 'diger',
        };
    }

    private function nullableInteger(mixed $value, bool $allowZero): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        if (is_int($value)) {
            $number = $value;
        } elseif (is_float($value) && floor($value) === $value) {
            $number = (int) $value;
        } else {
            $text = trim((string) $value);
            if (in_array(mb_strtolower($text, 'UTF-8'), ['dolmadı', '-', '—', '---', 'veri yok'], true)) {
                return null;
            }
            $normalized = str_replace(["\u{00A0}", ' ', '.', ','], '', $text);
            if (!ctype_digit($normalized)) {
                throw new InvalidArgumentException("Geçersiz tam sayı değeri: {$text}");
            }
            $number = (int) $normalized;
        }
        if ($number < 0 || (!$allowZero && $number === 0)) {
            return null;
        }
        return $number;
    }

    private function positiveInteger(mixed $value, string $field): int
    {
        $number = $this->nullableInteger($value, false);
        if ($number === null) {
            throw new InvalidArgumentException("{$field} pozitif tam sayı olmalıdır.");
        }
        return $number;
    }

    private function nullableDecimal(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $text = str_replace(["\u{00A0}", ' '], '', trim((string) $value));
        if (in_array(mb_strtolower($text, 'UTF-8'), ['dolmadı', '-', '—', '---', 'veri yok'], true)) {
            return null;
        }
        if (str_contains($text, ',') && str_contains($text, '.')) {
            $text = strrpos($text, ',') > strrpos($text, '.')
                ? str_replace(',', '.', str_replace('.', '', $text))
                : str_replace(',', '', $text);
        } elseif (str_contains($text, ',')) {
            $text = str_replace(',', '.', $text);
        }
        if (!is_numeric($text) || (float) $text <= 0) {
            return null;
        }
        return number_format((float) $text, 5, '.', '');
    }

    private function upper(string $value): string
    {
        return mb_strtoupper(trim($value), 'UTF-8');
    }
}
