<?php

declare(strict_types=1);

namespace DersRotasi\Osym;

final class OsymHistoricalSourceCatalog
{
    /**
     * @return array<int, array{result: list<array<string, int|string>>, guide: list<array<string, int|string>>}>
     */
    public function years(): array
    {
        return [
            2023 => [
                'result' => [
                    $this->source(
                        2023,
                        'result',
                        3,
                        '2023_result_table3.xlsx',
                        'https://dokuman.osym.gov.tr/pdfdokuman/2023/YKS/YERLESTIRME/tablo3yd_22082023.xlsx',
                        'ÖSYM 2023 YKS Yerleştirme Sonuçları Tablo-3',
                    ),
                    $this->source(
                        2023,
                        'result',
                        4,
                        '2023_result_table4.xlsx',
                        'https://dokuman.osym.gov.tr/pdfdokuman/2023/YKS/YERLESTIRME/tablo4yd_22082023.xlsx',
                        'ÖSYM 2023 YKS Yerleştirme Sonuçları Tablo-4',
                    ),
                ],
                'guide' => [
                    $this->source(
                        2023,
                        'guide',
                        3,
                        '2024_guide_table3.xls',
                        'https://dokuman.osym.gov.tr/pdfdokuman/2024/YKS/TERCIH/tablo3_25072024d.xls',
                        'ÖSYM 2024 YKS Kılavuzu Tablo-3 (2023 sonuç kolonları)',
                    ),
                    $this->source(
                        2023,
                        'guide',
                        4,
                        '2024_guide_table4.xls',
                        'https://dokuman.osym.gov.tr/pdfdokuman/2024/YKS/TERCIH/tablo4_25072024d.xls',
                        'ÖSYM 2024 YKS Kılavuzu Tablo-4 (2023 sonuç kolonları)',
                    ),
                ],
            ],
            2024 => [
                'result' => [
                    $this->source(
                        2024,
                        'result',
                        3,
                        '2024_result_table3.xlsx',
                        'https://dokuman.osym.gov.tr/pdfdokuman/2024/YKS/YERLESTIRME/tablo-3minmax_d27082024.xlsx',
                        'ÖSYM 2024 YKS Yerleştirme Sonuçları Tablo-3',
                    ),
                    $this->source(
                        2024,
                        'result',
                        4,
                        '2024_result_table4.xlsx',
                        'https://dokuman.osym.gov.tr/pdfdokuman/2024/YKS/YERLESTIRME/tablo-4minmax_b27082024.xlsx',
                        'ÖSYM 2024 YKS Yerleştirme Sonuçları Tablo-4',
                    ),
                ],
                'guide' => [
                    $this->source(
                        2024,
                        'guide',
                        3,
                        '2025_guide_table3.xls',
                        'https://dokuman.osym.gov.tr/pdfdokuman/2025/YKS/TERC%C4%B0H/tablo3_01082025.xls',
                        'ÖSYM 2025 YKS Kılavuzu Tablo-3 (2024 sonuç kolonları)',
                    ),
                    $this->source(
                        2024,
                        'guide',
                        4,
                        '2025_guide_table4.xls',
                        'https://dokuman.osym.gov.tr/pdfdokuman/2025/YKS/TERC%C4%B0H/tablo4_01082025d.xls',
                        'ÖSYM 2025 YKS Kılavuzu Tablo-4 (2024 sonuç kolonları)',
                    ),
                ],
            ],
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function source(
        int $historicalYear,
        string $kind,
        int $table,
        string $filename,
        string $url,
        string $label,
    ): array {
        return [
            'historical_year' => $historicalYear,
            'kind' => $kind,
            'table' => $table,
            'filename' => $filename,
            'url' => $url,
            'label' => $label,
        ];
    }
}
