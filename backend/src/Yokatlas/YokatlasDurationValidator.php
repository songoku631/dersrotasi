<?php

declare(strict_types=1);

namespace DersRotasi\Yokatlas;

final class YokatlasDurationValidator
{
    /** @return array<string, mixed> */
    public function validate(array $response, string $programCode, int $guideYear): array
    {
        $items = is_array($response['content'] ?? null) ? $response['content'] : [];
        $matching = array_values(array_filter(
            $items,
            static fn (mixed $item): bool => is_array($item)
                && (string) ($item['kilavuzKodu'] ?? '') === $programCode,
        ));
        if ($matching === []) {
            return ['status' => 'unmatched', 'reason' => 'Program kodu resmî yanıtta bulunamadı.'];
        }
        if (count($matching) !== 1) {
            return ['status' => 'ambiguous', 'reason' => 'Program kodu için birden fazla resmî kayıt döndü.'];
        }

        $item = $matching[0];
        if ((int) ($item['yil'] ?? 0) !== $guideYear) {
            return ['status' => 'year_mismatch', 'reason' => 'Resmî yanıt beklenen kılavuz yılına ait değil.'];
        }
        $duration = $this->positiveInteger($item['ogrenimSuresi'] ?? null);
        if ($duration === null || $duration > 10) {
            return ['status' => 'duration_missing', 'reason' => 'Resmî öğrenim süresi pozitif tam sayı değil.'];
        }

        return [
            'status' => 'valid',
            'reason' => null,
            'duration_years' => $duration,
            'guide_year' => $guideYear,
            'university_name' => trim((string) ($item['universiteAdi'] ?? '')),
            'faculty_name' => trim((string) ($item['fymkAdi'] ?? '')),
            'program_name' => trim((string) ($item['birimAdi'] ?? $item['birimGrupAdi'] ?? '')),
        ];
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_float($value) && floor($value) === $value) {
            return $value > 0 ? (int) $value : null;
        }
        $text = trim((string) $value);
        return ctype_digit($text) && (int) $text > 0 ? (int) $text : null;
    }
}
