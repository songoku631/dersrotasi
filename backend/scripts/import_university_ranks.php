<?php

declare(strict_types=1);

use DersRotasi\Config\Env;
use DersRotasi\Database\Connection;
use DersRotasi\Import\PdoUniversityRankStore;
use DersRotasi\Import\UniversityRankCsvReader;
use DersRotasi\Import\UniversityRankImportService;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Bu araç yalnızca komut satırından çalıştırılabilir.\n");
    exit(1);
}

function rankImportUsage(): never
{
    fwrite(STDERR, implode(PHP_EOL, [
        'Kullanım:',
        '  php scripts/import_university_ranks.php <csv-dosyası> --dry-run',
        '  php scripts/import_university_ranks.php <csv-dosyası> --apply [--yes]',
        '',
        '--dry-run  Eşleşmeleri analiz eder ve transaction işlemini geri alır.',
        '--apply    Yalnızca açık onaydan sonra izin verilen başarı sırası alanlarını günceller.',
        '--yes      Etkileşimsiz ortamda --apply onayını açıkça verir.',
    ]) . PHP_EOL);
    exit(1);
}

function ensureReportDirectory(string $directory): void
{
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('Rapor dizini hazırlanamadı.');
    }
    if (!is_writable($directory)) {
        throw new RuntimeException('Rapor dizinine yazılamıyor.');
    }
}

function writeRankImportReport(string $directory, array $report): string
{
    $suffix = substr(hash('sha256', microtime(true) . random_int(1, PHP_INT_MAX)), 0, 8);
    $name = 'university_rank_import_' . date('Ymd_His') . '_' . $suffix . '.json';
    $path = $directory . DIRECTORY_SEPARATOR . $name;
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Import raporu yazılamadı.');
    }

    return $path;
}

function printRankImportSummary(array $report, string $reportPath): void
{
    $counts = $report['counts'];
    echo 'Çalışma modu: ' . $report['mode'] . PHP_EOL;
    echo 'CSV toplam satır: ' . $counts['total_rows'] . PHP_EOL;
    echo 'Geçerli satır: ' . $counts['valid_rows'] . PHP_EOL;
    echo 'Güncellenecek kayıt: ' . $counts['records_to_update'] . PHP_EOL;
    echo 'Değişmeden kalacak kayıt: ' . $counts['unchanged_records'] . PHP_EOL;
    echo 'Eşleşmeyen program kodu: ' . $counts['unmatched_program_codes'] . PHP_EOL;
    echo 'Tekrarlanan program kodu: ' . $counts['duplicate_program_codes'] . PHP_EOL;
    echo 'Geçersiz başarı sırası: ' . $counts['invalid_base_rank'] . PHP_EOL;
    echo 'Çakışan mevcut değer: ' . $counts['conflicting_existing_values'] . PHP_EOL;
    echo 'Gerçekten güncellenen kayıt: ' . $counts['updated_records'] . PHP_EOL;
    echo 'İşlem süresi: ' . $report['duration_seconds'] . ' saniye' . PHP_EOL;
    echo 'Durum: ' . $report['status'] . PHP_EOL;
    echo 'Rapor: ' . $reportPath . PHP_EOL;
}

$arguments = array_slice($argv, 1);
$filePath = '';
$dryRun = false;
$apply = false;
$yes = false;
foreach ($arguments as $argument) {
    if ($argument === '--dry-run') {
        $dryRun = true;
    } elseif ($argument === '--apply') {
        $apply = true;
    } elseif ($argument === '--yes') {
        $yes = true;
    } elseif (str_starts_with($argument, '--')) {
        rankImportUsage();
    } elseif ($filePath === '') {
        $filePath = $argument;
    } else {
        rankImportUsage();
    }
}
if ($filePath === '' || $dryRun === $apply || ($yes && !$apply)) {
    rankImportUsage();
}

$startedAt = microtime(true);
$root = dirname(__DIR__);
$reportsDirectory = $root . '/storage/reports';

try {
    ensureReportDirectory($reportsDirectory);
    $batch = (new UniversityRankCsvReader())->read($filePath);

    if (file_exists($root . '/.env')) {
        Dotenv::createImmutable($root)->safeLoad();
    }
    $store = new PdoUniversityRankStore(Connection::make(new Env($_ENV)));
    $store->assertSchemaReady();

    $confirmation = static function (array $analysis) use ($yes): bool {
        if ($yes) {
            return true;
        }
        echo PHP_EOL . $analysis['counts']['records_to_update'] . " kayıt güncellenecek.\n";
        echo "Devam etmek için EVET yazın: ";
        $answer = fgets(STDIN);
        return $answer !== false && strtoupper(trim($answer)) === 'EVET';
    };

    $result = (new UniversityRankImportService($store))->run($batch['rows'], $apply, $confirmation);
    $status = $dryRun
        ? 'dry_run_tamamlandi_degisim_yok'
        : ($result['cancelled'] ? 'kullanici_tarafindan_iptal_edildi' : 'uygulandi');
    $report = [
        'generated_at' => date(DATE_ATOM),
        'mode' => $dryRun ? 'dry-run' : 'apply',
        'status' => $status,
        'input_file' => basename($filePath),
        'supported_years' => UniversityRankCsvReader::SUPPORTED_YEARS,
        'updated_columns' => ['base_rank', 'rank_source_name', 'rank_source_url', 'rank_updated_at'],
        'counts' => array_merge($batch['counts'], $result['counts']),
        'details' => array_merge($batch['details'], $result['details']),
        'duration_seconds' => round(microtime(true) - $startedAt, 3),
    ];
    $reportPath = writeRankImportReport($reportsDirectory, $report);
    printRankImportSummary($report, $reportPath);
    exit($result['cancelled'] ? 2 : 0);
} catch (Throwable $exception) {
    $safeMessage = $exception instanceof PDOException
        ? 'Veritabanına güvenli bağlantı kurulamadı.'
        : $exception->getMessage();
    $failureReport = [
        'generated_at' => date(DATE_ATOM),
        'mode' => $dryRun ? 'dry-run' : 'apply',
        'status' => 'basarisiz_degisimler_geri_alindi',
        'input_file' => basename($filePath),
        'error' => $safeMessage,
        'duration_seconds' => round(microtime(true) - $startedAt, 3),
    ];
    try {
        ensureReportDirectory($reportsDirectory);
        $reportPath = writeRankImportReport($reportsDirectory, $failureReport);
        fwrite(STDERR, 'Rapor: ' . $reportPath . PHP_EOL);
    } catch (Throwable) {
        // Ana hata mesajını koru; rapor yazma ayrıntısını kullanıcıya açma.
    }
    fwrite(STDERR, 'Başarı sırası importu tamamlanamadı: ' . $safeMessage . PHP_EOL);
    exit(1);
}
