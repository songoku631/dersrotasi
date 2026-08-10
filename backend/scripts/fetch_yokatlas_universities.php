<?php

declare(strict_types=1);

use DersRotasi\Config\Env;
use DersRotasi\Yokatlas\YokatlasClient;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

const PAGE_SIZE = 100;
const MAX_PROGRAMS = 25000;
const CSV_HEADERS = [
    'program_code', 'university_name', 'faculty_name', 'department_name', 'city',
    'university_type', 'score_type', 'education_type', 'education_language',
    'scholarship_type', 'base_score', 'base_rank', 'quota', 'placed_count',
    'duration_years', 'year', 'source_name', 'source_url',
];

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Bu araç yalnızca komut satırından çalıştırılabilir.\n");
    exit(1);
}

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function optionValue(string $argument, string $name): ?string
{
    $prefix = '--' . $name . '=';
    return str_starts_with($argument, $prefix) ? substr($argument, strlen($prefix)) : null;
}

function positiveOption(?string $value, string $name, int $minimum, int $maximum): int
{
    if ($value === null || filter_var($value, FILTER_VALIDATE_INT) === false) {
        throw new RuntimeException("--{$name} geçerli bir tam sayı olmalıdır.");
    }
    $number = (int) $value;
    if ($number < $minimum || $number > $maximum) {
        throw new RuntimeException("--{$name} {$minimum}-{$maximum} aralığında olmalıdır.");
    }
    return $number;
}

function requiredText(array $item, string $field, ?string $fallback = null): string
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

function upper(string $value): string
{
    return mb_strtoupper(trim($value), 'UTF-8');
}

function universityType(mixed $value): string
{
    $value = upper((string) $value);
    return match (true) {
        $value === 'DEVLET' => 'devlet',
        $value === 'VAKIF' => 'vakif',
        str_contains($value, 'KKTC') => 'kktc',
        str_contains($value, 'YABANCI'), str_contains($value, 'YURT') => 'yabanci',
        default => throw new InvalidArgumentException("Bilinmeyen üniversite türü: {$value}"),
    };
}

function scoreType(mixed $value): string
{
    return match (upper((string) $value)) {
        'SAY' => 'say',
        'EA' => 'ea',
        'SÖZ' => 'soz',
        'DİL' => 'dil',
        'TYT' => 'tyt',
        default => throw new InvalidArgumentException('Bilinmeyen puan türü.'),
    };
}

function educationType(mixed $value, array &$fallbacks): string
{
    $raw = trim((string) $value);
    $value = upper($raw);
    if (str_contains($value, 'İKİNCİ')) {
        return 'ikinci_ogretim';
    }
    if (str_contains($value, 'UZAKTAN')) {
        return 'uzaktan';
    }
    if (str_contains($value, 'AÇIK')) {
        return 'acikogretim';
    }
    if (str_contains($value, 'ÖRGÜN')) {
        return 'orgun';
    }
    $fallbacks['education_type'][$raw !== '' ? $raw : '<empty>'] = true;
    return 'diger';
}

function scholarshipType(mixed $value, string $universityType, array &$fallbacks): string
{
    $raw = trim((string) $value);
    $value = upper($raw);
    if ($value === '') {
        return $universityType === 'devlet' ? 'ucretsiz' : 'diger';
    }
    if (str_contains($value, '%50') || str_contains($value, '50 İNDİR')) {
        return 'yuzde_50';
    }
    if (str_contains($value, '%25') || str_contains($value, '25 İNDİR')) {
        return 'yuzde_25';
    }
    if (str_contains($value, 'BURSLU')) {
        return 'burslu';
    }
    if (str_contains($value, 'ÜCRETSİZ')) {
        return 'ucretsiz';
    }
    // Unicode default uppercasing turns the final "i" into ASCII "I" rather
    // than locale-specific "İ"; matching the stable stem handles both forms.
    if (str_contains($value, 'ÜCRET')) {
        return 'ucretli';
    }
    $fallbacks['scholarship_type'][$raw] = true;
    return 'diger';
}

function nullableInteger(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_int($value)) {
        return $value >= 0 ? (string) $value : '';
    }
    if (is_float($value) && floor($value) === $value) {
        return $value >= 0 ? (string) (int) $value : '';
    }
    $text = trim((string) $value);
    if ($text === '' || in_array(mb_strtolower($text, 'UTF-8'), ['dolmadı', '-', '—', '---'], true)) {
        return '';
    }
    $normalized = str_replace(["\u{00A0}", ' ', '.', ','], '', $text);
    return ctype_digit($normalized) ? $normalized : '';
}

function nullableDecimal(mixed $value): string
{
    if ($value === null || trim((string) $value) === '') {
        return '';
    }
    if (is_int($value) || is_float($value)) {
        return $value >= 0 ? number_format((float) $value, 5, '.', '') : '';
    }
    $text = trim((string) $value);
    if (in_array(mb_strtolower($text, 'UTF-8'), ['dolmadı', '-', '—', '---'], true)) {
        return '';
    }
    $text = str_replace(["\u{00A0}", ' '], '', $text);
    if (str_contains($text, ',') && str_contains($text, '.')) {
        $text = strrpos($text, ',') > strrpos($text, '.')
            ? str_replace(',', '.', str_replace('.', '', $text))
            : str_replace(',', '', $text);
    } elseif (str_contains($text, ',')) {
        $text = str_replace(',', '.', $text);
    }
    return is_numeric($text) && (float) $text >= 0
        ? number_format((float) $text, 5, '.', '')
        : '';
}

function csvRow(array $item, int $year, array &$fallbacks): array
{
    $programCode = trim((string) ($item['kilavuzKodu'] ?? ''));
    if (preg_match('/^[0-9]{9}$/', $programCode) !== 1) {
        throw new InvalidArgumentException('Program kodu 9 rakam değil.');
    }
    if ((int) ($item['yil'] ?? 0) !== $year) {
        throw new InvalidArgumentException('Kayıt yılı istenen yılla eşleşmiyor.');
    }
    // `yil` is the active guide year. The unsuffixed minPuan/basariSirasi
    // fields describe the preceding placement year.
    $placementYear = $year - 1;

    $universityType = universityType($item['universiteTuru'] ?? null);
    $scoreType = scoreType($item['puanTuru'] ?? null);
    $city = '';
    foreach (['ilAdi', 'uniIlAdi', 'fymkIlAdi'] as $cityField) {
        $city = trim((string) ($item[$cityField] ?? ''));
        if ($city !== '') {
            break;
        }
    }
    if ($city === '' && $universityType === 'yabanci') {
        $city = 'YURTDIŞI';
    }
    if ($city === '') {
        throw new InvalidArgumentException('Program şehri resmî yanıtta bulunamadı.');
    }
    $duration = nullableInteger($item['ogrenimSuresi'] ?? null);
    $isAssociate = upper((string) ($item['birimTuruAdi'] ?? '')) === 'ONLISANS'
        || $scoreType === 'tyt'
        || $duration === '2';
    $sourceUrl = 'https://yokatlas.yok.gov.tr/'
        . ($isAssociate ? 'onlisans.php' : 'lisans.php')
        . '?y=' . $programCode;

    return [
        $programCode,
        requiredText($item, 'universiteAdi'),
        trim((string) ($item['fymkAdi'] ?? '')),
        requiredText($item, 'birimAdi', 'birimGrupAdi'),
        $city,
        $universityType,
        $scoreType,
        educationType($item['ogrenimTuruAdi'] ?? null, $fallbacks),
        trim((string) ($item['ogrenimDiliAdi'] ?? '')),
        scholarshipType($item['bursOraniAdi'] ?? null, $universityType, $fallbacks),
        nullableDecimal($item['minPuan'] ?? null),
        nullableInteger($item['basariSirasi'] ?? null),
        nullableInteger($item['kontenjan'] ?? null),
        '',
        $duration,
        (string) $placementYear,
        'YÖK Atlas ' . $placementYear,
        $sourceUrl,
    ];
}

$options = [
    'year' => 2026,
    'limit' => 100,
    'delay_ms' => 1000,
    'write' => false,
    'output' => null,
];

try {
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--dry-run') {
            $options['write'] = false;
        } elseif ($argument === '--write') {
            $options['write'] = true;
        } elseif (($value = optionValue($argument, 'year')) !== null) {
            $options['year'] = positiveOption($value, 'year', 2025, 2100);
        } elseif (($value = optionValue($argument, 'limit')) !== null) {
            $options['limit'] = positiveOption($value, 'limit', 1, MAX_PROGRAMS);
        } elseif (($value = optionValue($argument, 'delay-ms')) !== null) {
            $options['delay_ms'] = positiveOption($value, 'delay-ms', 1000, 60000);
        } elseif (($value = optionValue($argument, 'output')) !== null) {
            if (preg_match('#^storage[\\/]imports[\\/][A-Za-z0-9_.-]+\.csv$#', $value) !== 1) {
                throw new RuntimeException('--output storage/imports altında bir CSV olmalıdır.');
            }
            $options['output'] = str_replace('\\', '/', $value);
        } else {
            throw new RuntimeException('Bilinmeyen seçenek: ' . $argument);
        }
    }

    $options['output'] ??= 'storage/imports/universities_' . ($options['year'] - 1) . '.csv';

    $root = dirname(__DIR__);
    Dotenv::createImmutable($root)->safeLoad();
    $env = new Env($_ENV);
    $client = new YokatlasClient($env->yokatlasUserAgent(), $options['delay_ms'], $env->sslCaBundle());
    $robots = $client->checkRobots();

    $outputPath = $root . '/' . $options['output'];
    $temporaryPath = $outputPath . '.part';
    $handle = null;
    if ($options['write']) {
        if (is_file($outputPath) || is_file($temporaryPath)) {
            throw new RuntimeException('Çıktı veya .part dosyası zaten var; mevcut dosyanın üzerine yazılmadı.');
        }
        $handle = fopen($temporaryPath, 'xb');
        if ($handle === false || fputcsv($handle, CSV_HEADERS) === false) {
            throw new RuntimeException('Geçici CSV çıktısı oluşturulamadı.');
        }
    }

    $processed = 0;
    $page = 0;
    $officialTotal = null;
    $officialPages = null;
    $errors = [];
    $unsupported = [];
    $unsupportedCount = 0;
    $fallbacks = ['education_type' => [], 'scholarship_type' => []];
    $programCodes = [];
    $universityNames = [];
    while ($processed < $options['limit']) {
        $response = $client->fetchPage($options['year'], $page, PAGE_SIZE);
        if ($response['status'] !== 200) {
            throw new RuntimeException("YÖK Atlas sayfa {$page} HTTP {$response['status']} döndürdü.");
        }
        $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded['content'] ?? null)
            || (int) ($decoded['number'] ?? -1) !== $page
            || (int) ($decoded['size'] ?? 0) !== PAGE_SIZE
            || (int) ($decoded['totalElements'] ?? 0) < 1
            || (int) ($decoded['totalPages'] ?? 0) < 1
            || count($decoded['content']) > PAGE_SIZE) {
            throw new RuntimeException("YÖK Atlas sayfa {$page} metadata doğrulamasından geçmedi.");
        }
        $officialTotal ??= (int) $decoded['totalElements'];
        $officialPages ??= (int) $decoded['totalPages'];
        if ($officialTotal !== (int) $decoded['totalElements']
            || $officialPages !== (int) $decoded['totalPages']) {
            throw new RuntimeException('YÖK Atlas sayfalama toplamı işlem sırasında değişti.');
        }

        foreach ($decoded['content'] as $item) {
            if ($processed >= $options['limit'] || $processed >= $officialTotal) {
                break;
            }
            try {
                if (!is_array($item)) {
                    throw new InvalidArgumentException('Program kaydı nesne değil.');
                }
                if (trim((string) ($item['puanTuru'] ?? '')) === '') {
                    $unsupportedCount++;
                    if (count($unsupported) < 20) {
                        $unsupported[] = [
                            'program_code' => trim((string) ($item['kilavuzKodu'] ?? '')),
                            'reason' => 'Resmî puan türü boş.',
                        ];
                    }
                    $processed++;
                    continue;
                }
                $row = csvRow($item, $options['year'], $fallbacks);
                if (isset($programCodes[$row[0]])) {
                    throw new InvalidArgumentException('Tekrarlanan program kodu: ' . $row[0]);
                }
                $programCodes[$row[0]] = true;
                $universityNames[$row[1]] = true;
                if ($handle !== null && fputcsv($handle, $row) === false) {
                    throw new RuntimeException('CSV satırı yazılamadı.');
                }
            } catch (InvalidArgumentException $exception) {
                if (count($errors) < 20) {
                    $errors[] = ['page' => $page, 'reason' => $exception->getMessage()];
                }
            }
            $processed++;
        }

        $page++;
        if ($processed >= $officialTotal || !empty($decoded['last'])) {
            break;
        }
    }

    if (is_resource($handle)) {
        fclose($handle);
        $handle = null;
    }
    $expected = min($options['limit'], (int) $officialTotal);
    if ($errors !== [] || count($programCodes) !== $expected - $unsupportedCount) {
        throw new RuntimeException('Export doğrulaması başarısız: ' . json_encode([
            'expected' => $expected,
            'valid' => count($programCodes),
            'unsupported_count' => $unsupportedCount,
            'unsupported' => $unsupported,
            'errors' => $errors,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    if ($options['write'] && !rename($temporaryPath, $outputPath)) {
        throw new RuntimeException('Doğrulanan geçici CSV son dosya adına taşınamadı.');
    }

    echo json_encode([
        'mode' => $options['write'] ? 'write' : 'dry-run',
        'guide_year' => $options['year'],
        'placement_year' => $options['year'] - 1,
        'robots' => $robots,
        'official_total' => $officialTotal,
        'processed_programs' => count($programCodes),
        'skipped_unsupported' => $unsupportedCount,
        'unsupported' => $unsupported,
        'distinct_universities' => count($universityNames),
        'pages_requested' => $page,
        'fallbacks' => array_map('array_keys', $fallbacks),
        'output' => $options['write'] ? $options['output'] : null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $exception) {
    if (isset($handle) && is_resource($handle)) {
        fclose($handle);
    }
    fail('YÖK Atlas üniversite exportu tamamlanamadı: ' . $exception->getMessage());
}
