<?php

declare(strict_types=1);

namespace DersRotasi\Yokatlas;

use DersRotasi\Import\EducationLanguageNormalizer;
use InvalidArgumentException;

final class CurrentUniversityMapper
{
    /** @return array<string, mixed> */
    public function map(array $item, int $year, string $fetchedAt): array
    {
        if ((int) ($item['yil'] ?? 0) !== $year) {
            throw new InvalidArgumentException('Resmî kayıt yılı beklenen yerleştirme yılıyla eşleşmiyor.');
        }

        $programCode = trim((string) ($item['kilavuzKodu'] ?? ''));
        if (preg_match('/^[0-9]{9}$/', $programCode) !== 1) {
            throw new InvalidArgumentException('Program kodu tam olarak 9 rakam olmalıdır.');
        }

        $universityName = $this->requiredText($item, 'universiteAdi');
        $departmentName = $this->requiredText($item, 'birimAdi', 'birimGrupAdi');
        $universityType = $this->universityType($item['universiteTuru'] ?? null);
        $scoreType = $this->scoreType($item['puanTuru'] ?? null);
        $duration = $this->nullableInteger($item['ogrenimSuresi'] ?? null, true);
        $baseScore = $this->nullableDecimal($item['minPuan'] ?? null);
        $baseRank = $this->nullableInteger($item['basariSirasi'] ?? null, false);
        $sourceUrl = 'https://yokatlas.yok.gov.tr/'
            . (($duration === 2 || $scoreType === 'tyt') ? 'onlisans.php' : 'lisans.php')
            . '?y=' . $programCode;
        $sourceName = "YÖK Atlas {$year}";

        return [
            'program_code' => $programCode,
            'identity_hash' => hash('sha256', $programCode),
            'university_name' => $universityName,
            'faculty_name' => trim((string) ($item['fymkAdi'] ?? '')),
            'department_name' => $departmentName,
            'city' => $this->city($item, $universityType),
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
            'quota' => $this->nullableInteger($item['kontenjan'] ?? null, true),
            'placed_count' => $this->nullableInteger($item['gkY'] ?? null, true),
            'duration_years' => $duration,
            'year' => $year,
            'source_year' => $year,
            'source_name' => $sourceName,
            'source_url' => $sourceUrl,
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
        if (preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException("{$field} geçerli UTF-8 değil.");
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
        throw new InvalidArgumentException('Program şehri resmî yanıtta bulunamadı.');
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
            default => throw new InvalidArgumentException('Bilinmeyen veya boş puan türü.'),
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
            str_contains($value, 'ÜCRET') => 'ucretli',
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
        return $number < 0 || (!$allowZero && $number === 0) ? null : $number;
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
