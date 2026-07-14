<?php

declare(strict_types=1);

use DersRotasi\Config\Env;
use DersRotasi\Database\Connection;
use DersRotasi\Import\EducationLanguageNormalizer;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Bu araç yalnızca komut satırından çalıştırılabilir.\n");
    exit(1);
}

const EXPECTED_HEADERS = [
    'program_code', 'university_name', 'faculty_name', 'department_name', 'city',
    'university_type', 'score_type', 'education_type', 'education_language',
    'scholarship_type', 'base_score', 'base_rank', 'quota', 'placed_count',
    'duration_years', 'year', 'source_name', 'source_url',
];
const ENUMS = [
    'university_type' => ['devlet', 'vakif', 'kktc', 'yabanci'],
    'score_type' => ['say', 'ea', 'soz', 'dil', 'tyt'],
    'education_type' => ['orgun', 'ikinci_ogretim', 'uzaktan', 'acikogretim', 'diger'],
    'scholarship_type' => ['ucretsiz', 'burslu', 'yuzde_50', 'yuzde_25', 'ucretli', 'diger'],
];

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
}

function detectDelimiter(string $headerLine): string
{
    foreach ([',', ';'] as $delimiter) {
        if (count(str_getcsv($headerLine, $delimiter, '"', '\\')) === count(EXPECTED_HEADERS)) {
            return $delimiter;
        }
    }

    throw new RuntimeException('CSV ayıracı algılanamadı; virgül veya noktalı virgül kullanın.');
}

function requiredString(array $row, string $field): string
{
    $value = trim((string) ($row[$field] ?? ''));
    if ($value === '') {
        throw new InvalidArgumentException("{$field} alanı zorunludur.");
    }

    return $value;
}

function enumValue(array $row, string $field): string
{
    $value = requiredString($row, $field);
    if (!in_array($value, ENUMS[$field], true)) {
        throw new InvalidArgumentException("{$field} değeri geçersiz: {$value}");
    }

    return $value;
}

function nullableInteger(array $row, string $field, int $maximum = PHP_INT_MAX): ?int
{
    $value = trim((string) ($row[$field] ?? ''));
    if ($value === '') {
        return null;
    }

    $normalized = str_replace(["\u{00A0}", ' ', '.', ','], '', $value);
    if (!ctype_digit($normalized)) {
        throw new InvalidArgumentException("{$field} pozitif tam sayı olmalıdır.");
    }

    $number = (int) $normalized;
    if ($number < 0 || $number > $maximum) {
        throw new InvalidArgumentException("{$field} izin verilen aralığın dışındadır.");
    }

    return $number;
}

function nullableDecimal(array $row, string $field): ?string
{
    $value = trim((string) ($row[$field] ?? ''));
    if ($value === '') {
        return null;
    }

    $value = str_replace(["\u{00A0}", ' '], '', $value);
    if (str_contains($value, ',') && str_contains($value, '.')) {
        $value = strrpos($value, ',') > strrpos($value, '.')
            ? str_replace(',', '.', str_replace('.', '', $value))
            : str_replace(',', '', $value);
    } elseif (str_contains($value, ',')) {
        $value = str_replace(',', '.', $value);
    }

    if (!preg_match('/^\d+(?:\.\d{1,5})?$/', $value)) {
        throw new InvalidArgumentException("{$field} geçerli bir sayı olmalıdır.");
    }

    return number_format((float) $value, 5, '.', '');
}

function validatedRow(array $row): array
{
    foreach ($row as $value) {
        if (preg_match('//u', (string) $value) !== 1) {
            throw new InvalidArgumentException('Satır geçerli UTF-8 değil.');
        }
    }

    $year = nullableInteger($row, 'year', 9999);
    if ($year !== 2025) {
        throw new InvalidArgumentException('year alanı bu içe aktarma için 2025 olmalıdır.');
    }

    $sourceUrl = trim((string) ($row['source_url'] ?? ''));
    if ($sourceUrl !== '' && filter_var($sourceUrl, FILTER_VALIDATE_URL) === false) {
        throw new InvalidArgumentException('source_url geçerli bir HTTP(S) adresi olmalıdır.');
    }
    if ($sourceUrl !== '' && !in_array(parse_url($sourceUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
        throw new InvalidArgumentException('source_url yalnızca HTTP(S) olabilir.');
    }

    return [
        'program_code' => requiredString($row, 'program_code'),
        'university_name' => requiredString($row, 'university_name'),
        'faculty_name' => trim((string) ($row['faculty_name'] ?? '')),
        'department_name' => requiredString($row, 'department_name'),
        'city' => requiredString($row, 'city'),
        'university_type' => enumValue($row, 'university_type'),
        'score_type' => enumValue($row, 'score_type'),
        'education_type' => enumValue($row, 'education_type'),
        'education_language' => EducationLanguageNormalizer::normalize(
            requiredString($row, 'department_name'),
            $row['education_language'] ?? null,
        ),
        'scholarship_type' => enumValue($row, 'scholarship_type'),
        'base_score' => nullableDecimal($row, 'base_score'),
        'base_rank' => nullableInteger($row, 'base_rank'),
        'quota' => nullableInteger($row, 'quota'),
        'placed_count' => nullableInteger($row, 'placed_count'),
        'duration_years' => nullableInteger($row, 'duration_years', 20),
        'year' => $year,
        'source_name' => requiredString($row, 'source_name'),
        'source_url' => $sourceUrl !== '' ? $sourceUrl : null,
    ];
}

$filePath = $argv[1] ?? '';
if ($filePath === '') {
    fail('Kullanım: php scripts/import_universities.php <csv-dosyası>');
}
if (!is_file($filePath) || !is_readable($filePath)) {
    fail('CSV dosyası bulunamadı veya okunamıyor.');
}

$handle = fopen($filePath, 'rb');
if ($handle === false) {
    fail('CSV dosyası açılamadı.');
}

$startedAt = microtime(true);
$firstLine = fgets($handle);
if ($firstLine === false) {
    fclose($handle);
    fail('CSV dosyası boş.');
}
$firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine) ?? $firstLine;

try {
    $delimiter = detectDelimiter($firstLine);
    $headers = str_getcsv(rtrim($firstLine, "\r\n"), $delimiter, '"', '\\');
    $headers = array_map(static fn ($value) => trim((string) $value), $headers);
    if ($headers !== EXPECTED_HEADERS) {
        throw new RuntimeException('CSV başlıkları şablonla birebir eşleşmiyor.');
    }

    $root = dirname(__DIR__);
    Dotenv::createImmutable($root)->safeLoad();
    $pdo = Connection::make(new Env($_ENV));
    $exists = $pdo->prepare('SELECT 1 FROM universities WHERE program_code = :program_code LIMIT 1');
    $upsert = $pdo->prepare(<<<SQL
INSERT INTO universities (
  program_code, university_name, faculty_name, department_name, city,
  university_type, score_type, education_type, education_language,
  scholarship_type, base_score, base_rank, quota, placed_count,
  duration_years, year, source_name, source_url
) VALUES (
  :program_code, :university_name, :faculty_name, :department_name, :city,
  :university_type, :score_type, :education_type, :education_language,
  :scholarship_type, :base_score, :base_rank, :quota, :placed_count,
  :duration_years, :year, :source_name, :source_url
)
ON DUPLICATE KEY UPDATE
  university_name = VALUES(university_name), faculty_name = VALUES(faculty_name),
  department_name = VALUES(department_name), city = VALUES(city),
  university_type = VALUES(university_type), score_type = VALUES(score_type),
  education_type = VALUES(education_type), education_language = VALUES(education_language),
  scholarship_type = VALUES(scholarship_type), base_score = VALUES(base_score),
  base_rank = VALUES(base_rank), quota = VALUES(quota), placed_count = VALUES(placed_count),
  duration_years = VALUES(duration_years), year = VALUES(year),
  source_name = VALUES(source_name), source_url = VALUES(source_url),
  updated_at = CURRENT_TIMESTAMP
SQL);

    $counts = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
    $lineNumber = 1;
    $pdo->beginTransaction();

    while (($values = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
        $lineNumber++;
        if ($values === [null] || (count($values) === 1 && trim((string) $values[0]) === '')) {
            $counts['skipped']++;
            continue;
        }

        $counts['total']++;
        try {
            if (count($values) !== count($headers)) {
                throw new InvalidArgumentException('Kolon sayısı başlık satırıyla eşleşmiyor.');
            }
            $row = validatedRow(array_combine($headers, $values));
            $exists->execute(['program_code' => $row['program_code']]);
            $isUpdate = (bool) $exists->fetchColumn();
            $upsert->execute($row);
            $counts[$isUpdate ? 'updated' : 'inserted']++;
        } catch (InvalidArgumentException $exception) {
            $counts['errors']++;
            fwrite(STDERR, "Satır {$lineNumber}: {$exception->getMessage()}\n");
        }
    }

    $pdo->commit();
    fclose($handle);
    $elapsed = number_format(microtime(true) - $startedAt, 2, '.', '');
    echo "Toplam satır: {$counts['total']}\n";
    echo "Eklenen kayıt: {$counts['inserted']}\n";
    echo "Güncellenen kayıt: {$counts['updated']}\n";
    echo "Atlanan kayıt: {$counts['skipped']}\n";
    echo "Hatalı kayıt: {$counts['errors']}\n";
    echo "İşlem süresi: {$elapsed} saniye\n";
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fclose($handle);
    fwrite(STDERR, 'İçe aktarma tamamlanamadı: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
