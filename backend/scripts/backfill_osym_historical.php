<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Bu araç yalnız komut satırından çalıştırılabilir.\n");
    exit(1);
}

if (!extension_loaded('zip')) {
    if (getenv('DERSROTASI_OSYM_ZIP_REEXEC') === '1') {
        fwrite(STDERR, "XLSX okumak için PHP zip extension etkin olmalıdır.\n");
        exit(1);
    }
    $extension = rtrim((string) ini_get('extension_dir'), '/\\')
        . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php_zip.dll' : 'zip.so');
    if (!is_file($extension)) {
        fwrite(STDERR, "XLSX okumak için PHP zip extension bulunamadı.\n");
        exit(1);
    }
    putenv('DERSROTASI_OSYM_ZIP_REEXEC=1');
    $command = [PHP_BINARY, '-d', 'extension=zip', '-d', 'memory_limit=1024M', __FILE__, ...array_slice($argv, 1)];
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, getcwd() ?: null);
    if (!is_resource($process)) {
        fwrite(STDERR, "PHP zip extension ile yeniden başlatılamadı.\n");
        exit(1);
    }
    exit(proc_close($process));
}

ini_set('memory_limit', '1024M');

use DersRotasi\Config\Env;
use DersRotasi\Database\Connection;
use DersRotasi\Osym\OsymBackfillReportStorage;
use DersRotasi\Osym\OsymFileCache;
use DersRotasi\Osym\OsymHistoricalBackfillRepository;
use DersRotasi\Osym\OsymHistoricalBackfillService;
use DersRotasi\Osym\OsymHistoricalSourceCatalog;
use DersRotasi\Osym\OsymHistoricalValueParser;
use DersRotasi\Osym\OsymSpreadsheetParser;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

function osymUsage(int $exitCode = 0): never
{
    $stream = $exitCode === 0 ? STDOUT : STDERR;
    fwrite($stream, implode(PHP_EOL, [
        'Kullanım:',
        '  php backend/scripts/backfill_osym_historical.php --dry-run',
        '  php backend/scripts/backfill_osym_historical.php --apply',
        '  php backend/scripts/backfill_osym_historical.php --dry-run --refresh',
        '',
        'Varsayılan mod dry-run. --apply açıkça verilmeden DB yazılmaz.',
        '--refresh cache dosyalarını resmî URL’den tekrar indirir ve checksum değişimini raporlar.',
    ]) . PHP_EOL);
    exit($exitCode);
}

/** @return array{apply: bool, refresh: bool} */
function osymOptions(array $arguments): array
{
    $options = ['apply' => false, 'refresh' => false];
    foreach ($arguments as $argument) {
        if ($argument === '--help') {
            osymUsage();
        }
        if ($argument === '--dry-run') {
            continue;
        }
        if ($argument === '--apply') {
            $options['apply'] = true;
            continue;
        }
        if ($argument === '--refresh') {
            $options['refresh'] = true;
            continue;
        }
        throw new RuntimeException("Bilinmeyen argüman: {$argument}");
    }
    return $options;
}

/** @param array<string, mixed> $result @param array<string, mixed> $guide */
function assertOsymRegression(array $result, array $guide): void
{
    $code = '203110477';
    $resultRow = $result[$code] ?? null;
    $guideRow = $guide[$code] ?? null;
    if ($resultRow === null
        || $guideRow === null
        || $resultRow['score'] !== '555.35802'
        || $guideRow['score'] !== '555.35802'
        || $guideRow['rank'] !== 30
        || $resultRow['quota'] !== 10
        || $resultRow['placed_count'] !== 10) {
        throw new RuntimeException('203110477 resmî ÖSYM regression doğrulaması başarısız; işlem durduruldu.');
    }
}

/** @param array<string, mixed> $report */
function printOsymSummary(array $report, array $paths): void
{
    fwrite(STDOUT, 'Mod: ' . ($report['mode'] === 'apply' ? 'APPLY' : 'DRY-RUN') . PHP_EOL);
    foreach ([2023, 2024] as $year) {
        $counts = $report['counts'][$year];
        fwrite(STDOUT, sprintf(
            "%d: examined=%d matched=%d score=%d rank=%d quota=%d placed=%d missing=%d conflicts=%d unchanged=%d\n",
            $year,
            $counts['examined'],
            $counts['program_code_matched'],
            $counts['score_candidates'],
            $counts['rank_candidates'],
            $counts['quota_candidates'],
            $counts['placed_count_candidates'],
            $counts['missing_source'],
            $counts['conflicts'],
            $counts['unchanged'],
        ));
    }
    $totals = $report['totals'];
    fwrite(STDOUT, sprintf(
        "Toplam: rows=%d score=%d rank=%d quota=%d placed=%d conflicts=%d missing=%d\n",
        $totals['rows_to_update'],
        $totals['score_cells'],
        $totals['rank_cells'],
        $totals['quota_cells'],
        $totals['placed_count_cells'],
        $totals['conflicts'],
        $totals['missing_source'],
    ));
    fwrite(STDOUT, "JSON: {$paths['json']}\nCSV: {$paths['csv']}\n");
}

$repository = null;
try {
    $options = osymOptions(array_slice($argv, 1));
    $backendRoot = dirname(__DIR__);
    Dotenv::createImmutable($backendRoot)->safeLoad();
    $env = new Env($_ENV);
    if ($env->appEnv() !== 'local'
        || $env->instanceConnectionName() !== null
        || !in_array($env->dbHost(), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('ÖSYM backfill yalnız local TCP veritabanında çalışabilir.');
    }

    $cache = new OsymFileCache($backendRoot);
    $parser = new OsymSpreadsheetParser(new OsymHistoricalValueParser());
    $catalog = (new OsymHistoricalSourceCatalog())->years();
    $datasets = [];
    $sourceFiles = [];
    $parserMetadata = [];
    $sourceDuplicates = [];
    foreach ($catalog as $year => $groups) {
        foreach ($groups as $kind => $sources) {
            $parsedTables = [];
            foreach ($sources as $source) {
                $cached = $cache->ensure($source, $options['refresh']);
                $parsed = $parser->parse((string) $cached['path'], $source);
                $parsedTables[] = $parsed;
                $sourceFiles[] = [
                    'historical_year' => $year,
                    'kind' => $kind,
                    'table' => $source['table'],
                    'source' => $source['label'],
                    'source_file' => $source['filename'],
                    'url' => $source['url'],
                    'sha256' => $cached['sha256'],
                    'size' => $cached['size'],
                    'from_cache' => $cached['from_cache'],
                    'remote_changed' => $cached['remote_changed'],
                ];
            }
            $merged = $parser->mergeTables($parsedTables);
            $datasets[$year][$kind] = $merged['rows'];
            $parserMetadata[$year][$kind] = $merged['metadata'];
            $sourceDuplicates = [...$sourceDuplicates, ...$merged['duplicates']];
        }
    }
    assertOsymRegression($datasets[2023]['result'], $datasets[2023]['guide']);
    if ($options['apply'] && $sourceDuplicates !== []) {
        throw new RuntimeException('Duplicate resmî program kodu bulundu; apply durduruldu.');
    }
    if ($options['apply'] && array_filter(
        $sourceFiles,
        static fn (array $file): bool => $file['remote_changed'] === true,
    ) !== []) {
        throw new RuntimeException(
            'En az bir resmî dosyanın checksum değeri değişti; önce yeni kaynaklarla dry-run incelenmelidir.'
        );
    }

    $repository = new OsymHistoricalBackfillRepository(Connection::make($env));
    $schema = $repository->assertSchemaCompatible();
    if ($options['apply']) {
        $repository->beginWrite();
    } else {
        $repository->beginReadOnly();
    }
    $before = $repository->integritySnapshot();
    $targetBefore = $repository->programHistory('203110477');
    $plan = (new OsymHistoricalBackfillService())->buildPlan($repository->historicalRows(), $datasets);
    $applyResult = null;
    if ($options['apply']) {
        $applyResult = $repository->applyUpdates($plan['updates']);
        $after = $repository->integritySnapshot();
        $repository->assertIntegrityUnchanged($before, $after);
        $targetAfter = $repository->programHistory('203110477');
        $repository->commit();
        foreach ($plan['changes'] as &$change) {
            $change['status'] = 'updated';
        }
        unset($change);
    } else {
        $after = $repository->integritySnapshot();
        $repository->assertIntegrityUnchanged($before, $after);
        $targetAfter = $targetBefore;
        $repository->rollBack();
    }

    $report = [
        'version' => 1,
        'mode' => $options['apply'] ? 'apply' : 'dry_run',
        'generated_at' => date(DATE_ATOM),
        'database' => [
            'environment' => $env->appEnv(),
            'host' => $env->dbHost(),
            'name' => $env->dbName(),
            'schema' => $schema,
        ],
        'source_files' => $sourceFiles,
        'parser_metadata' => $parserMetadata,
        'source_duplicates' => $sourceDuplicates,
        'counts' => $plan['counts'],
        'totals' => $plan['totals'],
        'apply_result' => $applyResult,
        'integrity_before' => $before,
        'integrity_after' => $after,
        'target_203110477' => [
            'before' => $targetBefore,
            'after' => $targetAfter,
            'official_2023_result' => $datasets[2023]['result']['203110477'],
            'official_2023_guide' => $datasets[2023]['guide']['203110477'],
        ],
        'changes' => $plan['changes'],
        'conflicts' => $plan['conflicts'],
    ];
    $paths = (new OsymBackfillReportStorage($backendRoot))->write($report);
    printOsymSummary($report, $paths);
} catch (Throwable $exception) {
    if ($repository instanceof OsymHistoricalBackfillRepository && $repository->inTransaction()) {
        $repository->rollBack();
    }
    fwrite(STDERR, 'HATA: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
