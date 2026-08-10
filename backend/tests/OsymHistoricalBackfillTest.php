<?php

declare(strict_types=1);

use DersRotasi\Osym\OsymHistoricalBackfillRepository;
use DersRotasi\Osym\OsymHistoricalBackfillService;
use DersRotasi\Osym\OsymHistoricalValueParser;
use DersRotasi\Osym\OsymSpreadsheetParser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require dirname(__DIR__) . '/vendor/autoload.php';

function osymCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, int|string> */
function osymTestSource(string $kind, int $table, string $filename): array
{
    return [
        'historical_year' => 2023,
        'kind' => $kind,
        'table' => $table,
        'filename' => $filename,
        'url' => 'https://dokuman.osym.gov.tr/test/' . $filename,
        'label' => "Test ÖSYM {$kind} Tablo-{$table}",
    ];
}

function writeResultFixture(string $path, bool $duplicate = false): void
{
    $book = new Spreadsheet();
    $sheet = $book->getActiveSheet();
    $sheet->setCellValue('A1', 'TABLO-4 2023 Yılı Merkezi Yerleştirme Programları');
    $sheet->setCellValue('G2', 'Genel Yerleştirme');
    $headers = [
        'Program Kodu', 'Üniversite Türü', 'Üniversite Adı', 'Fakülte/Yüksekokul Adı',
        'Program Adı', 'Puan Türü', 'Kontenjan', 'Yerleşen', 'En Küçük Puan', 'En Büyük Puan',
    ];
    foreach ($headers as $index => $header) {
        $sheet->setCellValue([$index + 1, 3], $header);
    }
    $row = ['203110477', 'VAKIF', 'İSTANBUL MEDİPOL ÜNİVERSİTESİ', 'Uluslararası Tıp Fakültesi',
        'Tıp (İngilizce) (Burslu)', 'SAY', 10, 10, 555.35802, 562.08261];
    foreach ($row as $index => $value) {
        $sheet->setCellValue([$index + 1, 4], $value);
        if ($duplicate) {
            $sheet->setCellValue([$index + 1, 5], $value);
        }
    }
    (new Xlsx($book))->save($path);
    $book->disconnectWorksheets();
}

function writeGuideFixture(string $path, int $table): void
{
    $book = new Spreadsheet();
    $sheet = $book->getActiveSheet();
    $rankColumn = $table === 3 ? 11 : 12;
    $scoreColumn = $rankColumn + 1;
    $sheet->setCellValue([$rankColumn, 1], '2023-YKS');
    $sheet->setCellValue([$scoreColumn, 1], '2023-YKS');
    $sheet->setCellValue('A2', 'PROGRAM');
    $sheet->setCellValue('B2', 'PROGRAM ADI (2)');
    $sheet->setCellValue('D2', 'PUAN TÜRÜ');
    $sheet->setCellValue([$rankColumn, 2], 'BAŞARI');
    $sheet->setCellValue([$scoreColumn, 2], 'EN KÜÇÜK');
    $sheet->setCellValue('A3', 'KODU (1)');
    $sheet->setCellValue([$rankColumn, 3], 'SIRASI');
    $sheet->setCellValue([$scoreColumn, 3], 'PUANI');
    $sheet->setCellValue('A4', '203110477');
    $sheet->setCellValue('B4', 'Tıp (İngilizce) (Burslu)');
    $sheet->setCellValue('D4', 'SAY');
    $sheet->setCellValue([$rankColumn, 4], 30);
    $sheet->setCellValue([$scoreColumn, 4], 555.35802);
    (new Xls($book))->save($path);
    $book->disconnectWorksheets();
}

$values = new OsymHistoricalValueParser();
osymCheck($values->programCode(203110477.0) === '203110477', 'Numeric program code normalize edilmedi.');
osymCheck($values->programCode('203110477.0') === '203110477', 'String program code normalize edilmedi.');
osymCheck($values->programCode('20311047') === null, '8 haneli program code kabul edildi.');
osymCheck($values->score('555,35802') === '555.35802', 'Virgüllü score parse edilmedi.');
foreach (['', '...', '-', '0', 0, null] as $invalidScore) {
    osymCheck($values->score($invalidScore) === null, 'Geçersiz score kabul edildi.');
}
osymCheck($values->rank('1.234.567') === 1234567, 'Gruplu rank parse edilmedi.');
foreach (['', '...', '-', '0', '12,5', null] as $invalidRank) {
    osymCheck($values->rank($invalidRank) === null, 'Geçersiz rank kabul edildi.');
}

$root = sys_get_temp_dir() . '/dersrotasi_osym_test_' . bin2hex(random_bytes(5));
mkdir($root, 0770, true);
$resultPath = $root . '/result.xlsx';
$duplicatePath = $root . '/duplicate.xlsx';
$guide3Path = $root . '/guide3.xls';
$guide4Path = $root . '/guide4.xls';
writeResultFixture($resultPath);
writeResultFixture($duplicatePath, true);
writeGuideFixture($guide3Path, 3);
writeGuideFixture($guide4Path, 4);

$parser = new OsymSpreadsheetParser($values);
$result = $parser->parse($resultPath, osymTestSource('result', 4, 'result.xlsx'));
$guide3 = $parser->parse($guide3Path, osymTestSource('guide', 3, 'guide3.xls'));
$guide4 = $parser->parse($guide4Path, osymTestSource('guide', 4, 'guide4.xls'));
$duplicate = $parser->parse($duplicatePath, osymTestSource('result', 4, 'duplicate.xlsx'));
osymCheck($result['rows']['203110477']['score'] === '555.35802', 'Result En Küçük Puan parse edilmedi.');
osymCheck($result['rows']['203110477']['placed_count'] === 10, 'Result Yerleşen parse edilmedi.');
osymCheck($guide3['rows']['203110477']['rank'] === 30, 'Tablo-3 rank kolonu bulunamadı.');
osymCheck($guide4['rows']['203110477']['rank'] === 30, 'Tablo-4 rank kolonu bulunamadı.');
osymCheck($guide3['rows']['203110477']['score'] === '555.35802', 'Tablo-3 score kolonu bulunamadı.');
osymCheck($guide4['rows']['203110477']['score'] === '555.35802', 'Tablo-4 score kolonu bulunamadı.');
osymCheck(count($duplicate['duplicates']) === 1 && $duplicate['rows'] === [], 'Duplicate source algılanmadı.');

$sourceRows = [
    2023 => ['result' => $result['rows'], 'guide' => $guide4['rows']],
    2024 => ['result' => [], 'guide' => []],
];
$databaseRow = [
    'id' => 1, 'program_code' => '203110477', 'year' => 2023,
    'base_score' => null, 'base_rank' => null, 'quota' => 10, 'placed_count' => null,
];
$service = new OsymHistoricalBackfillService();
$plan = $service->buildPlan([$databaseRow], $sourceRows);
osymCheck($plan['totals']['score_cells'] === 1, '203110477 score candidate oluşmadı.');
osymCheck($plan['totals']['rank_cells'] === 1, '203110477 rank candidate oluşmadı.');
osymCheck($plan['totals']['quota_cells'] === 0, 'Non-NULL quota candidate yapıldı.');
osymCheck($plan['totals']['placed_count_cells'] === 1, '203110477 placed_count candidate oluşmadı.');

$conflictRow = $databaseRow;
$conflictRow['base_score'] = '500.00000';
$conflictPlan = $service->buildPlan([$conflictRow], $sourceRows);
osymCheck($conflictPlan['totals']['conflicts'] === 1, 'Non-NULL DB conflict algılanmadı.');
osymCheck($conflictPlan['totals']['score_cells'] === 0, 'Conflict score update adayı yapıldı.');

$conflictingGuide = $guide4['rows'];
$conflictingGuide['203110477']['score'] = '554.00000';
$officialConflict = $service->buildPlan([$databaseRow], [
    2023 => ['result' => $result['rows'], 'guide' => $conflictingGuide],
    2024 => ['result' => [], 'guide' => []],
]);
osymCheck($officialConflict['totals']['conflicts'] === 1, 'Resmî kaynak conflict algılanmadı.');
osymCheck($officialConflict['totals']['score_cells'] === 0, 'Resmî conflict score candidate yapıldı.');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE universities (id INTEGER PRIMARY KEY, program_code TEXT, year INTEGER, base_score NUMERIC NULL, base_rank INTEGER NULL, quota INTEGER NULL, placed_count INTEGER NULL)');
$pdo->exec("INSERT INTO universities VALUES (1, '203110477', 2023, NULL, NULL, 10, NULL)");
$repository = new OsymHistoricalBackfillRepository($pdo);
$pdo->beginTransaction();
$applied = $repository->applyUpdates($plan['updates']);
$pdo->commit();
osymCheck($applied['updated_rows'] === 1 && $applied['updated_cells'] === 3, 'NULL-only update uygulanmadı.');
$stored = $pdo->query('SELECT * FROM universities WHERE id=1')->fetch(PDO::FETCH_ASSOC);
osymCheck((float) $stored['base_score'] === 555.35802 && (int) $stored['base_rank'] === 30, 'Regression değerleri yazılmadı.');
osymCheck((int) $stored['quota'] === 10 && (int) $stored['placed_count'] === 10, 'Quota koruması/placed update yanlış.');

$secondPlan = $service->buildPlan([$stored], $sourceRows);
osymCheck($secondPlan['totals']['rows_to_update'] === 0, 'İkinci plan idempotent değil.');
$pdo->beginTransaction();
try {
    $repository->applyUpdates($plan['updates']);
    throw new RuntimeException('NULL-only SQL guard eski planı kabul etti.');
} catch (RuntimeException $exception) {
    $pdo->rollBack();
    osymCheck(str_contains($exception->getMessage(), 'NULL-only guard'), 'Beklenmeyen repository hatası.');
}

foreach ([$resultPath, $duplicatePath, $guide3Path, $guide4Path] as $path) {
    unlink($path);
}
rmdir($root);

echo "OsymHistoricalBackfillTest: OK\n";
