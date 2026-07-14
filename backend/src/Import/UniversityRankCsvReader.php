<?php

declare(strict_types=1);

namespace DersRotasi\Import;

use InvalidArgumentException;
use RuntimeException;

final class UniversityRankCsvReader
{
    public const EXPECTED_HEADERS = [
        'program_code', 'base_rank', 'year', 'source_name', 'source_url',
    ];
    public const SUPPORTED_YEARS = [2025];
    public const INVALID_BASE_RANK = 1001;

    public function read(string $filePath): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException('CSV dosyası bulunamadı veya okunamıyor.');
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('CSV dosyası açılamadı.');
        }

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                throw new RuntimeException('CSV dosyası boş.');
            }
            $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine) ?? $firstLine;
            $delimiter = $this->detectDelimiter($firstLine);
            $headers = str_getcsv(rtrim($firstLine, "\r\n"), $delimiter, '"', '\\');
            $headers = array_map(static fn (mixed $value): string => trim((string) $value), $headers);
            if ($headers !== self::EXPECTED_HEADERS) {
                throw new RuntimeException('CSV başlıkları şablonla birebir eşleşmiyor.');
            }

            $rows = [];
            $occurrences = [];
            $counts = [
                'total_rows' => 0,
                'valid_rows' => 0,
                'processable_rows' => 0,
                'duplicate_program_codes' => 0,
                'invalid_base_rank' => 0,
                'invalid_rows' => 0,
            ];
            $details = ['duplicates' => [], 'invalid_rows' => []];
            $lineNumber = 1;

            while (($values = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                $lineNumber++;
                if ($values === [null] || (count($values) === 1 && trim((string) $values[0]) === '')) {
                    continue;
                }
                $counts['total_rows']++;
                if (count($values) !== count($headers)) {
                    $counts['invalid_rows']++;
                    $details['invalid_rows'][] = [
                        'line' => $lineNumber,
                        'reason' => 'Kolon sayısı başlık satırıyla eşleşmiyor.',
                    ];
                    continue;
                }

                $raw = array_combine($headers, $values);
                $programCode = trim((string) ($raw['program_code'] ?? ''));
                if (preg_match('/^[0-9]{9}$/', $programCode) === 1) {
                    $occurrences[$programCode][] = $lineNumber;
                }

                try {
                    $row = $this->validateRow($raw);
                    $row['line'] = $lineNumber;
                    $counts['valid_rows']++;
                    $rows[$row['program_code']] = $row;
                } catch (InvalidArgumentException $exception) {
                    $counts['invalid_rows']++;
                    if ($exception->getCode() === self::INVALID_BASE_RANK) {
                        $counts['invalid_base_rank']++;
                    }
                    $details['invalid_rows'][] = [
                        'line' => $lineNumber,
                        'program_code' => $programCode !== '' ? $programCode : null,
                        'reason' => $exception->getMessage(),
                    ];
                }
            }

            foreach ($occurrences as $programCode => $lines) {
                if (count($lines) < 2) {
                    continue;
                }
                unset($rows[$programCode]);
                $details['duplicates'][] = ['program_code' => $programCode, 'lines' => $lines];
            }
            $counts['duplicate_program_codes'] = count($details['duplicates']);
            $counts['processable_rows'] = count($rows);

            return ['rows' => array_values($rows), 'counts' => $counts, 'details' => $details];
        } finally {
            fclose($handle);
        }
    }

    public function validateRow(array $row): array
    {
        foreach ($row as $value) {
            if (preg_match('//u', (string) $value) !== 1) {
                throw new InvalidArgumentException('Satır geçerli UTF-8 değil.');
            }
        }

        $programCode = trim((string) ($row['program_code'] ?? ''));
        if (preg_match('/^[0-9]{9}$/', $programCode) !== 1) {
            throw new InvalidArgumentException('program_code tam olarak 9 rakamdan oluşmalıdır.');
        }

        $baseRank = $this->positiveRank((string) ($row['base_rank'] ?? ''));
        $yearText = trim((string) ($row['year'] ?? ''));
        if (!ctype_digit($yearText) || !in_array((int) $yearText, self::SUPPORTED_YEARS, true)) {
            throw new InvalidArgumentException('year yalnızca 2025 olabilir.');
        }

        $sourceName = trim((string) ($row['source_name'] ?? ''));
        $sourceNameLength = preg_match_all('/./us', $sourceName, $matches);
        if ($sourceName === '' || $sourceNameLength === false || $sourceNameLength > 255) {
            throw new InvalidArgumentException('source_name zorunludur ve en fazla 255 karakter olabilir.');
        }

        $sourceUrl = trim((string) ($row['source_url'] ?? ''));
        if ($sourceUrl !== '') {
            $scheme = strtolower((string) parse_url($sourceUrl, PHP_URL_SCHEME));
            if (filter_var($sourceUrl, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true)) {
                throw new InvalidArgumentException('source_url geçerli bir HTTP(S) adresi olmalıdır.');
            }
        }

        return [
            'program_code' => $programCode,
            'base_rank' => $baseRank,
            'year' => (int) $yearText,
            'source_name' => $sourceName,
            'source_url' => $sourceUrl !== '' ? $sourceUrl : null,
        ];
    }

    private function positiveRank(string $value): int
    {
        $value = trim(str_replace("\u{00A0}", ' ', $value));
        $plainInteger = preg_match('/^[0-9]+$/', $value) === 1;
        $groupedInteger = preg_match('/^(?:[0-9]{1,3}(?:\.[0-9]{3})+|[0-9]{1,3}(?:,[0-9]{3})+|[0-9]{1,3}(?: [0-9]{3})+)$/', $value) === 1;
        if (!$plainInteger && !$groupedInteger) {
            throw new InvalidArgumentException('base_rank pozitif tam sayı olmalıdır.', self::INVALID_BASE_RANK);
        }
        $normalized = str_replace(['.', ',', ' '], '', $value);
        if ($normalized === '' || !ctype_digit($normalized)) {
            throw new InvalidArgumentException('base_rank pozitif tam sayı olmalıdır.', self::INVALID_BASE_RANK);
        }
        $rank = (int) $normalized;
        if ($rank < 1 || $rank > 4294967295) {
            throw new InvalidArgumentException('base_rank izin verilen aralığın dışındadır.', self::INVALID_BASE_RANK);
        }

        return $rank;
    }

    private function detectDelimiter(string $headerLine): string
    {
        foreach ([',', ';'] as $delimiter) {
            $headers = str_getcsv(rtrim($headerLine, "\r\n"), $delimiter, '"', '\\');
            if (array_map('trim', $headers) === self::EXPECTED_HEADERS) {
                return $delimiter;
            }
        }

        throw new RuntimeException('CSV ayıracı veya başlıkları geçersiz; şablonu kullanın.');
    }
}
