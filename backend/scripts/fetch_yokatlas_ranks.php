<?php

declare(strict_types=1);

use DersRotasi\Config\Env;
use DersRotasi\Database\Connection;
use DersRotasi\Yokatlas\YokatlasClient;
use DersRotasi\Yokatlas\YokatlasResponseValidator;
use DersRotasi\Yokatlas\YokatlasStopException;
use DersRotasi\Yokatlas\YokatlasStorage;
use DersRotasi\Yokatlas\YokatlasUniversityRepository;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

const YOKATLAS_PAGE_SIZE = 100;
const YOKATLAS_MAX_PROGRAMS = 21602;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Bu araç yalnızca komut satırında çalıştırılabilir.\n");
    exit(1);
}

set_exception_handler(static function (Throwable $exception): never {
    $message = $exception instanceof PDOException
        ? 'Veritabanı işlemi güvenli biçimde tamamlanamadı.'
        : $exception->getMessage();
    fwrite(STDERR, 'YÖK Atlas aracı başlatılamadı: ' . $message . PHP_EOL);
    exit(1);
});

function usage(): never
{
    fwrite(STDERR, implode(PHP_EOL, [
        'Kullanım:',
        '  php scripts/fetch_yokatlas_ranks.php --year=2025 --dry-run --limit=200',
        '  php scripts/fetch_yokatlas_ranks.php --year=2025 --program-code=123456789 --dry-run',
        '  php scripts/fetch_yokatlas_ranks.php --year=2025 --resume --apply',
        '',
        'Seçenekler: --dry-run --apply --limit=N --offset=N --resume --delay-ms=N',
        '            --program-code=KOD --only-missing --include-existing --output=DİZİN',
        'Varsayılan: dry-run, only-missing, limit=100, sayfa boyutu=100, delay-ms=1000.',
    ]) . PHP_EOL);
    exit(1);
}

function optionValue(string $argument, string $name): ?string
{
    $prefix = '--' . $name . '=';
    return str_starts_with($argument, $prefix) ? substr($argument, strlen($prefix)) : null;
}

function integerOption(?string $value, string $name, int $minimum, int $maximum): int
{
    if ($value === null || filter_var($value, FILTER_VALIDATE_INT) === false) {
        throw new RuntimeException("--{$name} geçerli tam sayı olmalıdır.");
    }
    $number = (int) $value;
    if ($number < $minimum || $number > $maximum) {
        throw new RuntimeException("--{$name} {$minimum}-{$maximum} aralığında olmalıdır.");
    }
    return $number;
}

function programSourceUrl(array $program): string
{
    $isAssociate = (int) ($program['duration_years'] ?? 0) === 2 || $program['score_type'] === 'tyt';
    return 'https://yokatlas.yok.gov.tr/' . ($isAssociate ? 'onlisans.php' : 'lisans.php')
        . '?y=' . $program['program_code'];
}

function incrementHttpError(array &$errors, int|string $status): void
{
    $key = (string) $status;
    $errors[$key] = ($errors[$key] ?? 0) + 1;
}

function sanitizedOfficialItem(array $item): array
{
    static $allowed = [
        'kilavuzKodu' => true, 'yil' => true, 'basariSirasi' => true,
        'minBasariSirasi' => true, 'minPuan' => true, 'universiteAdi' => true,
        'birimGrupAdi' => true, 'birimTuruAdi' => true, 'puanTuru' => true,
    ];
    return array_intersect_key($item, $allowed);
}

function sanitizedPage(array $response, int $expectedPage, int $expectedSize): array
{
    foreach (['content', 'number', 'size', 'totalElements', 'totalPages'] as $field) {
        if (!array_key_exists($field, $response)) {
            throw new RuntimeException("Resmi sayfalı yanıtta {$field} alanı bulunamadı.");
        }
    }
    if (!is_array($response['content'])
        || (int) $response['number'] !== $expectedPage
        || (int) $response['size'] !== $expectedSize
        || (int) $response['totalElements'] < 0
        || (int) $response['totalPages'] < 1) {
        throw new RuntimeException('Resmi sayfalama metadata değerleri beklenen yapıda değil.');
    }

    $content = [];
    foreach ($response['content'] as $item) {
        if (!is_array($item)) {
            throw new RuntimeException('Resmi sayfada geçersiz program kaydı bulundu.');
        }
        $content[] = sanitizedOfficialItem($item);
    }
    if (count($content) > $expectedSize) {
        throw new RuntimeException('Resmi sayfa güvenli sayfa boyutunu aştı.');
    }
    return [
        'content' => $content,
        'number' => (int) $response['number'],
        'size' => (int) $response['size'],
        'totalElements' => (int) $response['totalElements'],
        'totalPages' => (int) $response['totalPages'],
        'first' => (bool) ($response['first'] ?? $expectedPage === 0),
        'last' => (bool) ($response['last'] ?? false),
    ];
}

$options = [
    'year' => 2025, 'dry_run' => true, 'apply' => false, 'limit' => 100, 'offset' => 0,
    'resume' => false, 'delay_ms' => 1000, 'program_code' => null,
    'only_missing' => true, 'output' => null,
];
$explicitDryRun = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--dry-run') {
        $explicitDryRun = true;
        $options['dry_run'] = true;
    } elseif ($argument === '--apply') {
        $options['apply'] = true;
        $options['dry_run'] = false;
    } elseif ($argument === '--resume') {
        $options['resume'] = true;
    } elseif ($argument === '--only-missing') {
        $options['only_missing'] = true;
    } elseif ($argument === '--include-existing') {
        $options['only_missing'] = false;
    } elseif (($value = optionValue($argument, 'year')) !== null) {
        $options['year'] = integerOption($value, 'year', 2025, 2025);
    } elseif (($value = optionValue($argument, 'limit')) !== null) {
        $options['limit'] = integerOption($value, 'limit', 1, YOKATLAS_MAX_PROGRAMS);
    } elseif (($value = optionValue($argument, 'offset')) !== null) {
        $options['offset'] = integerOption($value, 'offset', 0, YOKATLAS_MAX_PROGRAMS);
    } elseif (($value = optionValue($argument, 'delay-ms')) !== null) {
        $options['delay_ms'] = integerOption($value, 'delay-ms', 1000, 60000);
    } elseif (($value = optionValue($argument, 'program-code')) !== null) {
        if (preg_match('/^[0-9]{9}$/', $value) !== 1) {
            throw new RuntimeException('--program-code tam olarak 9 rakam olmalıdır.');
        }
        $options['program_code'] = $value;
        $options['limit'] = 1;
        $options['offset'] = 0;
    } elseif (($value = optionValue($argument, 'output')) !== null) {
        if (preg_match('#^storage[\\/]yokatlas[\\/]reports(?:[\\/][A-Za-z0-9_.-]+)*$#', $value) !== 1) {
            throw new RuntimeException('--output backend/storage/yokatlas/reports altında olmalıdır.');
        }
        $options['output'] = $value;
    } else {
        usage();
    }
}
if ($explicitDryRun && $options['apply']) {
    throw new RuntimeException('--dry-run ve --apply birlikte kullanılamaz.');
}

$startedAt = microtime(true);
$root = dirname(__DIR__);
if (file_exists($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}
$env = new Env($_ENV);
if ($options['apply'] && $env->appEnv() === 'production') {
    throw new RuntimeException('Bu araç production ortamında apply modunda çalıştırılamaz.');
}
$outputDirectory = $options['output'] !== null ? $root . '/' . str_replace('\\', '/', $options['output']) : null;
$storage = new YokatlasStorage($root, $outputDirectory);
$repository = new YokatlasUniversityRepository(Connection::make($env));
if ($options['apply']) {
    $repository->assertApplySchemaReady();
}
$client = new YokatlasClient($env->yokatlasUserAgent(), $options['delay_ms'], $env->sslCaBundle());
$validator = new YokatlasResponseValidator();
$stateMode = $options['apply'] ? 'apply' : 'dry-run';

$counts = [
    'total_examined' => 0, 'successfully_found' => 0, 'base_rank_found' => 0,
    'base_rank_missing' => 0, 'unmatched_program' => 0, 'score_mismatch' => 0,
    'year_mismatch' => 0, 'name_mismatch' => 0, 'parse_error' => 0,
    'page_requests' => 0, 'pages_processed' => 0, 'page_cache_used' => 0,
    'records_to_update' => 0, 'unchanged_records' => 0, 'filtered_existing' => 0,
    'existing_rank_conflicts' => 0, 'updated_records' => 0, 'concurrent_conflicts' => 0,
];
$httpErrors = [];
$items = [];
$updates = [];
$stoppedReason = null;
$robots = ['status' => 'not_checked', 'allowed' => false];
$officialTotalElements = null;
$officialTotalPages = null;
$networkPageSeconds = 0.0;
$nextIndex = $options['offset'];

if ($options['resume'] && $options['program_code'] === null) {
    $state = $storage->readBulkState($options['year'], $stateMode);
    if ($state !== null) {
        if ((int) ($state['page_size'] ?? 0) !== YOKATLAS_PAGE_SIZE) {
            throw new RuntimeException('Resume state sayfa boyutu mevcut araç sürümüyle uyumlu değil.');
        }
        $nextIndex = max(0, (int) ($state['next_index'] ?? 0));
    }
}
$startIndex = $nextIndex;

$handleOfficialItem = static function (
    array $official,
    ?array $program,
    bool $fromCache,
    string $fetchedAt
) use (&$counts, &$items, &$updates, $options, $validator): void {
    $programCode = trim((string) ($official['kilavuzKodu'] ?? ''));
    $counts['total_examined']++;
    if (preg_match('/^[0-9]{9}$/', $programCode) !== 1) {
        $counts['parse_error']++;
        $items[] = ['program_code' => $programCode, 'status' => 'parse_error', 'reason' => 'Resmi program kodu geçersiz.'];
        return;
    }
    if ($program === null) {
        $counts['unmatched_program']++;
        $items[] = [
            'program_code' => $programCode, 'status' => 'unmatched',
            'reason' => 'Program kodu yerel ÖSYM kayıtlarında bulunamadı.',
            'from_cache' => $fromCache,
        ];
        return;
    }
    if ($options['only_missing'] && $program['base_rank'] !== null) {
        $counts['filtered_existing']++;
        $counts['unchanged_records']++;
        $items[] = [
            'program_code' => $programCode, 'status' => 'filtered_existing',
            'base_rank' => (int) $program['base_rank'], 'year' => (int) $program['year'],
            'reason' => 'Mevcut başarı sırası dolu olduğu için atlandı.', 'from_cache' => $fromCache,
        ];
        return;
    }

    $sourceUrl = programSourceUrl($program);
    $validation = $validator->validateItem($official, $program, $sourceUrl, $fetchedAt);
    $status = $validation['status'];
    if ($status !== 'unmatched') {
        $counts['successfully_found']++;
    }
    if (isset($validation['base_rank']) && $validation['base_rank'] !== null) {
        $counts['base_rank_found']++;
    }
    match ($status) {
        'rank_missing' => $counts['base_rank_missing']++,
        'unmatched' => $counts['unmatched_program']++,
        'score_mismatch' => $counts['score_mismatch']++,
        'year_mismatch' => $counts['year_mismatch']++,
        'name_mismatch' => $counts['name_mismatch']++,
        'parse_error' => $counts['parse_error']++,
        default => null,
    };

    if ($status === 'valid') {
        $existingRank = $program['base_rank'] === null ? null : (int) $program['base_rank'];
        if ($existingRank === null) {
            $counts['records_to_update']++;
            $updates[] = [
                'id' => (int) $program['id'], 'program_code' => $programCode,
                'base_rank' => $validation['base_rank'], 'year' => (int) $program['year'],
                'source_name' => $validation['source_name'], 'source_url' => $validation['source_url'],
                'fetched_at' => $validation['fetched_at'],
            ];
        } elseif ($existingRank === $validation['base_rank']) {
            $counts['unchanged_records']++;
        } else {
            $counts['existing_rank_conflicts']++;
            $status = 'existing_rank_conflict';
            $validation['reason'] = 'Mevcut başarı sırası farklı; otomatik üzerine yazılmadı.';
        }
    }

    $items[] = array_merge([
        'program_code' => $programCode, 'status' => $status,
        'year' => (int) $program['year'], 'source_url' => $sourceUrl,
        'fetched_at' => $fetchedAt, 'from_cache' => $fromCache,
        'existing_base_score' => $program['base_score'],
    ], $validation);
};

try {
    $robots = $client->checkRobots();

    if ($options['program_code'] !== null) {
        $programCode = $options['program_code'];
        $cached = $storage->readCache($options['year'], $programCode);
        $fromCache = $cached !== null && isset($cached['response']['content']);
        if ($fromCache) {
            $responseData = $cached['response'];
            $fetchedAt = (string) ($cached['fetched_at'] ?? gmdate('Y-m-d H:i:s'));
        } else {
            $requestStarted = microtime(true);
            $response = $client->fetchProgram($programCode, $options['year']);
            $networkPageSeconds += microtime(true) - $requestStarted;
            $counts['page_requests']++;
            if ($response['status'] !== 200) {
                incrementHttpError($httpErrors, $response['status']);
                throw new YokatlasStopException('Tek program isteği başarılı olmadı.', $response['status']);
            }
            $decoded = json_decode($response['body'], true);
            if (!is_array($decoded)) {
                throw new RuntimeException('Tek program JSON yanıtı çözümlenemedi.');
            }
            $responseData = ['content' => array_map('sanitizedOfficialItem', $decoded['content'] ?? [])];
            $fetchedAt = gmdate('Y-m-d H:i:s');
            $storage->writeCache($options['year'], $programCode, [
                'program_code' => $programCode, 'year' => $options['year'],
                'source_name' => 'YÖK Atlas 2025', 'fetched_at' => $fetchedAt,
                'response' => $responseData,
            ]);
            unset($decoded, $response);
        }
        $codes = array_map(static fn (array $item): string => (string) ($item['kilavuzKodu'] ?? ''), $responseData['content']);
        $programMap = $repository->programsByCodes($options['year'], $codes);
        foreach ($responseData['content'] as $official) {
            $code = (string) ($official['kilavuzKodu'] ?? '');
            $handleOfficialItem($official, $programMap[$code] ?? null, $fromCache, $fetchedAt);
        }
        $counts['pages_processed'] = 1;
        $nextIndex = 1;
    } else {
        $remaining = $options['limit'];
        while ($remaining > 0) {
            $page = intdiv($nextIndex, YOKATLAS_PAGE_SIZE);
            $withinPage = $nextIndex % YOKATLAS_PAGE_SIZE;
            $cachedPage = $storage->readPageCache($options['year'], $page, YOKATLAS_PAGE_SIZE);
            $fromCache = $cachedPage !== null;
            if ($fromCache) {
                $pageData = $cachedPage;
                $counts['page_cache_used']++;
            } else {
                $requestStarted = microtime(true);
                $response = $client->fetchPage($options['year'], $page, YOKATLAS_PAGE_SIZE);
                $networkPageSeconds += microtime(true) - $requestStarted;
                $counts['page_requests']++;
                if ($response['status'] !== 200) {
                    incrementHttpError($httpErrors, $response['status']);
                    throw new YokatlasStopException("Sayfa {$page} HTTP {$response['status']} döndürdü.", $response['status']);
                }
                $decoded = json_decode($response['body'], true);
                if (!is_array($decoded)) {
                    throw new RuntimeException("Sayfa {$page} JSON yanıtı çözümlenemedi.");
                }
                $pageData = sanitizedPage($decoded, $page, YOKATLAS_PAGE_SIZE);
                $pageData['fetched_at'] = gmdate('Y-m-d H:i:s');
                $storage->writePageCache($options['year'], $page, YOKATLAS_PAGE_SIZE, $pageData);
                unset($decoded, $response);
            }

            $officialTotalElements = (int) $pageData['totalElements'];
            $officialTotalPages = (int) $pageData['totalPages'];
            if ($nextIndex >= $officialTotalElements) {
                break;
            }
            $available = count($pageData['content']);
            if ($available === 0 || $withinPage >= $available) {
                if (!empty($pageData['last'])) {
                    break;
                }
                throw new RuntimeException("Sayfa {$page} beklenen kayıt aralığını içermiyor.");
            }

            $take = min($remaining, $available - $withinPage);
            $pageItems = [];
            $codes = [];
            for ($index = $withinPage; $index < $withinPage + $take; $index++) {
                $official = $pageData['content'][$index];
                $pageItems[] = $official;
                $codes[] = (string) ($official['kilavuzKodu'] ?? '');
            }
            $programMap = $repository->programsByCodes($options['year'], $codes);
            $fetchedAt = (string) ($pageData['fetched_at'] ?? gmdate('Y-m-d H:i:s'));
            foreach ($pageItems as $official) {
                $code = (string) ($official['kilavuzKodu'] ?? '');
                $handleOfficialItem($official, $programMap[$code] ?? null, $fromCache, $fetchedAt);
                $nextIndex++;
                $remaining--;
            }
            unset($pageItems, $programMap, $pageData);
            $counts['pages_processed']++;

            if ($options['dry_run']) {
                $storage->writeBulkState($options['year'], $stateMode, [
                    'version' => 2, 'page_size' => YOKATLAS_PAGE_SIZE,
                    'next_index' => $nextIndex, 'processed_at' => gmdate(DATE_ATOM),
                    'mode' => $stateMode,
                ]);
            }
            if ($officialTotalElements !== null && $nextIndex >= $officialTotalElements) {
                break;
            }
        }
    }

    if ($options['apply'] && $updates !== []) {
        echo count($updates) . " kayıt yalnızca başarı sırası alanlarında güncellenecek.\n";
        echo "Devam etmek için EVET yazın: ";
        $answer = fgets(STDIN);
        if ($answer === false || strtoupper(trim($answer)) !== 'EVET') {
            $stoppedReason = 'Apply kullanıcı tarafından iptal edildi; veritabanı değişmedi.';
        } else {
            $applyResult = $repository->apply($updates);
            $counts['updated_records'] = $applyResult['updated'];
            $counts['concurrent_conflicts'] = $applyResult['concurrent_conflicts'];
            if ($options['program_code'] === null) {
                $storage->writeBulkState($options['year'], $stateMode, [
                    'version' => 2, 'page_size' => YOKATLAS_PAGE_SIZE,
                    'next_index' => $nextIndex, 'processed_at' => gmdate(DATE_ATOM),
                    'mode' => $stateMode,
                ]);
            }
        }
    } elseif ($options['apply'] && $options['program_code'] === null && $stoppedReason === null) {
        $storage->writeBulkState($options['year'], $stateMode, [
            'version' => 2, 'page_size' => YOKATLAS_PAGE_SIZE,
            'next_index' => $nextIndex, 'processed_at' => gmdate(DATE_ATOM),
            'mode' => $stateMode,
        ]);
    }
} catch (YokatlasStopException $exception) {
    $stoppedReason = $exception->getMessage();
    incrementHttpError($httpErrors, $exception->httpStatus ?? 'connection');
} catch (Throwable $exception) {
    $stoppedReason = $exception instanceof PDOException
        ? 'Veritabanı işlemi güvenli biçimde tamamlanamadı.'
        : $exception->getMessage();
}

$averagePageRequestSeconds = $counts['page_requests'] > 0
    ? $networkPageSeconds / $counts['page_requests']
    : null;
$estimatedTotalPages = $officialTotalPages ?? (int) ceil(YOKATLAS_MAX_PROGRAMS / YOKATLAS_PAGE_SIZE);
$estimatedTotalSeconds = $averagePageRequestSeconds !== null
    ? $estimatedTotalPages * $averagePageRequestSeconds
    : $estimatedTotalPages * ($options['delay_ms'] / 1000);

$report = [
    'generated_at' => gmdate(DATE_ATOM), 'mode' => $options['dry_run'] ? 'dry-run' : 'apply',
    'year' => $options['year'], 'data_source' => 'YÖK Atlas 2025',
    'source_endpoint' => 'https://yokatlas.yok.gov.tr/api/tercih-kilavuz/search',
    'page_size' => YOKATLAS_PAGE_SIZE, 'start_index' => $startIndex, 'next_index' => $nextIndex,
    'official_total_elements' => $officialTotalElements, 'official_total_pages' => $officialTotalPages,
    'robots' => $robots, 'delay_ms' => $options['delay_ms'],
    'only_missing' => $options['only_missing'], 'stopped_reason' => $stoppedReason,
    'counts' => $counts, 'http_errors' => $httpErrors, 'items' => $items,
    'performance' => [
        'average_page_request_seconds' => $averagePageRequestSeconds !== null
            ? round($averagePageRequestSeconds, 3) : null,
        'estimated_full_page_requests' => $estimatedTotalPages,
        'estimated_full_minutes' => round($estimatedTotalSeconds / 60, 2),
        'average_requests_per_program' => round($estimatedTotalPages / YOKATLAS_MAX_PROGRAMS, 6),
    ],
    'duration_seconds' => round(microtime(true) - $startedAt, 3),
];
$paths = $storage->writeReports($report);
echo 'Toplam incelenen: ' . $counts['total_examined'] . PHP_EOL;
echo 'Başarıyla bulunan: ' . $counts['successfully_found'] . PHP_EOL;
echo 'Başarı sırası bulunan: ' . $counts['base_rank_found'] . PHP_EOL;
echo 'Başarı sırası bulunmayan: ' . $counts['base_rank_missing'] . PHP_EOL;
echo 'Eşleşmeyen program: ' . $counts['unmatched_program'] . PHP_EOL;
echo 'Conflict: ' . ($counts['score_mismatch'] + $counts['name_mismatch'] + $counts['year_mismatch'] + $counts['existing_rank_conflicts']) . PHP_EOL;
echo 'İşlenen sayfa: ' . $counts['pages_processed'] . PHP_EOL;
echo 'Yeni sayfa isteği: ' . $counts['page_requests'] . PHP_EOL;
echo 'Cache kullanılan sayfa: ' . $counts['page_cache_used'] . PHP_EOL;
echo 'Güncellenecek: ' . $counts['records_to_update'] . PHP_EOL;
echo 'Gerçekten güncellenen: ' . $counts['updated_records'] . PHP_EOL;
echo 'Tahmini tam istek: ' . $estimatedTotalPages . PHP_EOL;
echo 'Tahmini tam süre: ' . round($estimatedTotalSeconds / 60, 2) . ' dakika' . PHP_EOL;
echo 'İşlem süresi: ' . $report['duration_seconds'] . ' saniye' . PHP_EOL;
echo 'JSON rapor: ' . $paths['json'] . PHP_EOL;
echo 'CSV rapor: ' . $paths['csv'] . PHP_EOL;
if ($stoppedReason !== null) {
    fwrite(STDERR, 'Durma nedeni: ' . $stoppedReason . PHP_EOL);
    exit(2);
}
