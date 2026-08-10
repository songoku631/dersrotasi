<?php

declare(strict_types=1);

namespace DersRotasi\Osym;

final class OsymHistoricalValueParser
{
    public function programCode(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            $value = sprintf('%.0f', $value);
        }

        $normalized = trim((string) $value);
        if (preg_match('/^([0-9]{9})(?:\.0+)?$/', $normalized, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    public function score(mixed $value): ?string
    {
        $number = $this->decimal($value);
        if ($number === null || $number <= 0 || $number > 1000) {
            return null;
        }

        return number_format($number, 5, '.', '');
    }

    public function rank(mixed $value): ?int
    {
        $number = $this->integer($value);
        return $number !== null && $number > 0 ? $number : null;
    }

    public function nonNegativeInteger(mixed $value): ?int
    {
        $number = $this->integer($value);
        return $number !== null && $number >= 0 ? $number : null;
    }

    private function decimal(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }

        $normalized = $this->text($value);
        if ($normalized === null) {
            return null;
        }
        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            if (strrpos($normalized, ',') > strrpos($normalized, '.')) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif (str_contains($normalized, ',')) {
            $normalized = str_replace(',', '.', $normalized);
        }

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return floor($value) === $value ? (int) $value : null;
        }

        $normalized = $this->text($value);
        if ($normalized === null) {
            return null;
        }
        if (preg_match('/^[0-9]+$/', $normalized) === 1) {
            return (int) $normalized;
        }
        if (preg_match('/^[0-9]{1,3}(?:[.,][0-9]{3})+$/', $normalized) === 1) {
            return (int) str_replace([',', '.'], '', $normalized);
        }

        return null;
    }

    private function text(mixed $value): ?string
    {
        $normalized = trim(str_replace("\u{00A0}", ' ', (string) $value));
        $normalized = str_replace(' ', '', $normalized);
        if ($normalized === '' || preg_match('/^(?:\.{1,}|-{1,}|NULL)$/iu', $normalized) === 1) {
            return null;
        }

        return $normalized;
    }
}
