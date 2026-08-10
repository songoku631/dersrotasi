<?php

declare(strict_types=1);

namespace DersRotasi\Osym;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

final class OsymSpreadsheetParser
{
    public function __construct(private readonly OsymHistoricalValueParser $values)
    {
    }

    /**
     * @param array<string, int|string> $source
     * @return array{rows: array<string, array<string, mixed>>, duplicates: list<array<string, mixed>>, metadata: array<string, mixed>}
     */
    public function parse(string $path, array $source): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("ÖSYM kaynak dosyası bulunamadı: {$path}");
        }

        $kind = (string) ($source['kind'] ?? '');
        if (!in_array($kind, ['result', 'guide'], true)) {
            throw new RuntimeException('ÖSYM kaynak türü result veya guide olmalıdır.');
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new OsymSpreadsheetReadFilter($kind === 'guide' ? 13 : 10));
        $book = $reader->load($path);
        try {
            $sheet = $book->getActiveSheet();
            $columns = $this->detectColumns($sheet, $source);
            $seenLines = [];
            $candidateRows = [];
            $invalidProgramCodes = 0;
            $highestRow = $sheet->getHighestDataRow();
            for ($row = $columns['header_last_row'] + 1; $row <= $highestRow; $row++) {
                $rawCode = $this->cell($sheet, $columns['program_code'], $row);
                if ($this->isBlank($rawCode)) {
                    continue;
                }
                $programCode = $this->values->programCode($rawCode);
                if ($programCode === null) {
                    $invalidProgramCodes++;
                    continue;
                }
                $seenLines[$programCode][] = $row;
                if (count($seenLines[$programCode]) > 1) {
                    unset($candidateRows[$programCode]);
                    continue;
                }

                $candidateRows[$programCode] = $kind === 'result'
                    ? $this->resultRow($sheet, $columns, $row, $programCode, $source)
                    : $this->guideRow($sheet, $columns, $row, $programCode, $source);
            }

            $duplicates = [];
            foreach ($seenLines as $programCode => $lines) {
                if (count($lines) > 1) {
                    $duplicates[] = [
                        'program_code' => $programCode,
                        'source_file' => (string) $source['filename'],
                        'source_rows' => $lines,
                    ];
                }
            }

            return [
                'rows' => $candidateRows,
                'duplicates' => $duplicates,
                'metadata' => [
                    'source_file' => (string) $source['filename'],
                    'kind' => $kind,
                    'table' => (int) $source['table'],
                    'historical_year' => (int) $source['historical_year'],
                    'sheet' => $sheet->getTitle(),
                    'highest_row' => $highestRow,
                    'program_rows' => count($seenLines),
                    'unique_rows' => count($candidateRows),
                    'invalid_program_codes' => $invalidProgramCodes,
                    'duplicate_program_codes' => count($duplicates),
                    'columns' => $columns,
                ],
            ];
        } finally {
            $book->disconnectWorksheets();
            unset($book);
            gc_collect_cycles();
        }
    }

    /**
     * @param list<array{rows: array<string, array<string, mixed>>, duplicates: list<array<string, mixed>>, metadata: array<string, mixed>}> $tables
     * @return array{rows: array<string, array<string, mixed>>, duplicates: list<array<string, mixed>>, metadata: list<array<string, mixed>>}
     */
    public function mergeTables(array $tables): array
    {
        $rows = [];
        $duplicates = [];
        $metadata = [];
        foreach ($tables as $table) {
            $duplicates = [...$duplicates, ...$table['duplicates']];
            $metadata[] = $table['metadata'];
            foreach ($table['rows'] as $programCode => $row) {
                if (array_key_exists($programCode, $rows)) {
                    $duplicates[] = [
                        'program_code' => $programCode,
                        'source_file' => $rows[$programCode]['source_file'] . ' | ' . $row['source_file'],
                        'source_rows' => [$rows[$programCode]['source_row'], $row['source_row']],
                    ];
                    unset($rows[$programCode]);
                    continue;
                }
                $rows[$programCode] = $row;
            }
        }

        return ['rows' => $rows, 'duplicates' => $duplicates, 'metadata' => $metadata];
    }

    /**
     * @param array<string, int|string> $source
     * @return array<string, int>
     */
    private function detectColumns(Worksheet $sheet, array $source): array
    {
        $maxColumn = (string) $source['kind'] === 'guide' ? 13 : 10;
        $headerRows = min(8, $sheet->getHighestDataRow());
        $headers = [];
        for ($column = 1; $column <= $maxColumn; $column++) {
            $parts = [];
            for ($row = 1; $row <= $headerRows; $row++) {
                $value = trim((string) $this->cell($sheet, $column, $row));
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
            $headers[$column] = $this->header(implode(' ', $parts));
        }

        $kind = (string) $source['kind'];
        $programCode = $this->findColumn($headers, ['PROGRAM', 'KODU']);
        $headerLastRow = $this->findHeaderLastRow($sheet, $programCode, $headerRows);
        if ($kind === 'result') {
            return [
                'header_last_row' => $headerLastRow,
                'program_code' => $programCode,
                'university_type' => $this->findColumn($headers, ['UNIVERSITE', 'TURU']),
                'university' => $this->findColumn($headers, ['UNIVERSITE', 'ADI']),
                'faculty' => $this->findColumn($headers, ['FAKULTE', 'ADI']),
                'program' => $this->findColumn($headers, ['PROGRAM', 'ADI']),
                'score_type' => $this->findColumn($headers, ['PUAN', 'TURU']),
                'quota' => $this->findColumn($headers, ['KONTENJAN']),
                'placed_count' => $this->findColumn($headers, ['YERLESEN']),
                'score' => $this->findColumn($headers, ['EN', 'KUCUK', 'PUAN']),
            ];
        }

        $historicalYear = (int) $source['historical_year'];
        return [
            'header_last_row' => $headerLastRow,
            'program_code' => $programCode,
            'program' => $this->findColumn($headers, ['PROGRAM', 'ADI']),
            'score_type' => $this->findColumn($headers, ['PUAN', 'TURU']),
            'rank' => $this->findColumn($headers, ["{$historicalYear}-YKS", 'BASARI', 'SIRASI']),
            'score' => $this->findColumn($headers, ["{$historicalYear}-YKS", 'EN', 'KUCUK', 'PUAN']),
        ];
    }

    /** @param array<int, string> $headers */
    private function findColumn(array $headers, array $needles): int
    {
        foreach ($headers as $column => $header) {
            $matches = true;
            foreach ($needles as $needle) {
                if (!str_contains($header, $this->header($needle))) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                return $column;
            }
        }

        throw new RuntimeException('ÖSYM çalışma sayfasında beklenen kolon bulunamadı: ' . implode(' + ', $needles));
    }

    private function findHeaderLastRow(Worksheet $sheet, int $programCodeColumn, int $headerRows): int
    {
        $last = 0;
        for ($row = 1; $row <= $headerRows; $row++) {
            $value = $this->header((string) $this->cell($sheet, $programCodeColumn, $row));
            if (str_contains($value, 'PROGRAM') || str_contains($value, 'KODU')) {
                $last = $row;
            }
        }
        if ($last < 1) {
            throw new RuntimeException('ÖSYM çalışma sayfası header satırı bulunamadı.');
        }

        return $last;
    }

    /** @param array<string, int> $columns @param array<string, int|string> $source */
    private function resultRow(
        Worksheet $sheet,
        array $columns,
        int $row,
        string $programCode,
        array $source,
    ): array {
        return [
            'program_code' => $programCode,
            'score' => $this->values->score($this->cell($sheet, $columns['score'], $row)),
            'rank' => null,
            'quota' => $this->values->nonNegativeInteger($this->cell($sheet, $columns['quota'], $row)),
            'placed_count' => $this->values->nonNegativeInteger(
                $this->cell($sheet, $columns['placed_count'], $row),
            ),
            'university_name' => trim((string) $this->cell($sheet, $columns['university'], $row)),
            'faculty_name' => trim((string) $this->cell($sheet, $columns['faculty'], $row)),
            'program_name' => trim((string) $this->cell($sheet, $columns['program'], $row)),
            'score_type' => trim((string) $this->cell($sheet, $columns['score_type'], $row)),
            ...$this->provenance($source, $row),
        ];
    }

    /** @param array<string, int> $columns @param array<string, int|string> $source */
    private function guideRow(
        Worksheet $sheet,
        array $columns,
        int $row,
        string $programCode,
        array $source,
    ): array {
        return [
            'program_code' => $programCode,
            'score' => $this->values->score($this->cell($sheet, $columns['score'], $row)),
            'rank' => $this->values->rank($this->cell($sheet, $columns['rank'], $row)),
            'quota' => null,
            'placed_count' => null,
            'program_name' => trim((string) $this->cell($sheet, $columns['program'], $row)),
            'score_type' => trim((string) $this->cell($sheet, $columns['score_type'], $row)),
            ...$this->provenance($source, $row),
        ];
    }

    /** @param array<string, int|string> $source */
    private function provenance(array $source, int $row): array
    {
        return [
            'source' => (string) $source['label'],
            'source_file' => (string) $source['filename'],
            'source_url' => (string) $source['url'],
            'source_row' => $row,
        ];
    }

    private function cell(Worksheet $sheet, int $column, int $row): mixed
    {
        return $sheet->getCell([$column, $row])->getCalculatedValue();
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }

    private function header(string $value): string
    {
        $value = mb_strtoupper($value, 'UTF-8');
        $value = strtr($value, ['Ç' => 'C', 'Ğ' => 'G', 'İ' => 'I', 'I' => 'I', 'Ö' => 'O', 'Ş' => 'S', 'Ü' => 'U']);
        return trim((string) preg_replace('/[^A-Z0-9-]+/u', ' ', $value));
    }
}

final class OsymSpreadsheetReadFilter implements IReadFilter
{
    public function __construct(private readonly int $maximumColumn)
    {
    }

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        return Coordinate::columnIndexFromString($columnAddress) <= $this->maximumColumn;
    }
}
