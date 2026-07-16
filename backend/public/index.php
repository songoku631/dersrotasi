<?php

declare(strict_types=1);

use DersRotasi\Config\Env;
use DersRotasi\Database\Connection;
use DersRotasi\Http\JsonResponse;
use DersRotasi\Http\Request;
use DersRotasi\Middleware\FirebaseAuthMiddleware;
use DersRotasi\Repositories\FavoriteRepository;
use DersRotasi\Repositories\PreferenceRepository;
use DersRotasi\Repositories\ProfileRepository;
use DersRotasi\Repositories\UniversityRepository;
use DersRotasi\Repositories\YksEstimateRepository;
use DersRotasi\Repositories\YksRankDataRepository;
use DersRotasi\Services\FirebaseTokenVerifier;
use DersRotasi\Services\PreferenceEvaluationService;
use DersRotasi\Services\YksBacktestConfidenceService;
use DersRotasi\Services\YksRankEstimator;
use DersRotasi\Services\YksScoreCalculator;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
if (file_exists($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$env = new Env($_ENV);
$request = Request::fromGlobals();
JsonResponse::applyCors($env->corsAllowedOrigins(), $request->origin());

if ($request->method() === 'OPTIONS') {
    JsonResponse::send(['success' => true]);
}

$pdo = null;
$db = static function () use (&$pdo, $env): PDO {
    return $pdo ??= Connection::make($env);
};
$auth = new FirebaseAuthMiddleware(new FirebaseTokenVerifier(
    $env->firebaseProjectId(),
    $env->sslCaBundle()
));
$authenticate = static fn (): array => $auth->authenticate($request);
$positiveId = static function (mixed $value, string $field = 'id'): int {
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        throw new RuntimeException("{$field} pozitif tam sayı olmalıdır.", 422);
    }
    return (int) $id;
};
$calculateYks = static function (array $body) use ($root, $db): array {
    $result = (new YksScoreCalculator($root . '/config/yks'))->calculate($body);
    if ($result['scores']['placement_score'] === null) {
        return $result;
    }
    $points = (new YksRankDataRepository($db()))->points($result['year'], $result['score_type']);
    $rankEstimate = (new YksRankEstimator())->estimate(
        (float) $result['scores']['placement_score'],
        $points,
        $result['year'],
        (float) ($result['scores']['placement_score_uncertainty'] ?? 0.0)
    );
    $result['rank_estimate'] = array_intersect_key(
        $rankEstimate,
        array_flip(['center', 'min', 'max', 'outside_data_range', 'point_count'])
    );
    $validation = (new YksBacktestConfidenceService(
        $root . '/storage/reports/yks_rank_backtest_2025.json'
    ))->forScoreType($result['score_type']);
    $result['confidence'] = $validation['confidence'];
    $result['confidence_explanation'] = $validation['explanation'];
    return $result;
};

try {
    $path = rtrim($request->path(), '/') ?: '/';
    $method = $request->method();

    if ($method === 'GET' && $path === '/health') {
        JsonResponse::send([
            'success' => true,
            'service' => 'Ders Rotası API',
            'environment' => $env->appEnv(),
        ]);
    }

    if ($method === 'GET' && $path === '/api/me') {
        $firebaseUser = $authenticate();
        $repository = new ProfileRepository($db());
        $profile = $repository->findByUid($firebaseUser['uid'])
            ?? $repository->save($firebaseUser['uid'], []);
        JsonResponse::send([
            'success' => true,
            'user' => $firebaseUser,
            'profile' => $profile,
        ]);
    }

    if (($method === 'GET' || $method === 'PUT') && $path === '/api/profile') {
        $firebaseUser = $authenticate();
        $repository = new ProfileRepository($db());
        if ($method === 'GET') {
            JsonResponse::send(['success' => true, 'profile' => $repository->findByUid($firebaseUser['uid'])]);
        }
        JsonResponse::send([
            'success' => true,
            'message' => 'Profil bilgileri kaydedildi.',
            'profile' => $repository->save($firebaseUser['uid'], $request->json()),
        ]);
    }

    if ($method === 'POST' && $path === '/api/yks/estimate') {
        JsonResponse::send(['success' => true, 'data' => $calculateYks($request->json())]);
    }

    if ($path === '/api/yks/estimates') {
        $firebaseUser = $authenticate();
        $repository = new YksEstimateRepository($db());
        if ($method === 'GET') {
            JsonResponse::send(['success' => true, 'data' => ['items' => $repository->all($firebaseUser['uid'])]]);
        }
        if ($method === 'POST') {
            $body = $request->json();
            $result = $calculateYks($body);
            JsonResponse::send([
                'success' => true,
                'message' => 'Hesaplama geçmişine kaydedildi.',
                'data' => $repository->save($firebaseUser['uid'], $body, $result),
            ], 201);
        }
    }

    if ($method === 'GET' && $path === '/api/universities/filters') {
        JsonResponse::send(['success' => true, 'data' => (new UniversityRepository($db()))->filters()]);
    }

    if ($method === 'GET' && preg_match('#^/api/universities/(\d+)$#', $path, $matches)) {
        $firebaseUid = null;
        if ($request->bearerToken() !== null) {
            $firebaseUid = $authenticate()['uid'];
        }
        $university = (new UniversityRepository($db()))->find((int) $matches[1], $firebaseUid);
        if ($university === null) {
            throw new RuntimeException('Üniversite programı bulunamadı.', 404);
        }
        JsonResponse::send(['success' => true, 'data' => $university]);
    }

    if ($method === 'GET' && $path === '/api/universities') {
        $firebaseUid = null;
        if ($request->bearerToken() !== null) {
            $firebaseUid = $authenticate()['uid'];
        }
        JsonResponse::send([
            'success' => true,
            'data' => (new UniversityRepository($db()))->paginate($_GET, $firebaseUid),
        ]);
    }

    if ($path === '/api/favorites') {
        $firebaseUser = $authenticate();
        $repository = new FavoriteRepository($db());
        if ($method === 'GET') {
            JsonResponse::send(['success' => true, 'data' => ['items' => $repository->all($firebaseUser['uid'])]]);
        }
        if ($method === 'POST') {
            $body = $request->json();
            $created = $repository->add($firebaseUser['uid'], $positiveId($body['university_id'] ?? null, 'university_id'));
            JsonResponse::send([
                'success' => true,
                'message' => $created ? 'Program favorilere eklendi.' : 'Program zaten favorilerinizde.',
            ], $created ? 201 : 200);
        }
    }

    if ($method === 'DELETE' && preg_match('#^/api/favorites/(\d+)$#', $path, $matches)) {
        $firebaseUser = $authenticate();
        $removed = (new FavoriteRepository($db()))->remove($firebaseUser['uid'], (int) $matches[1]);
        JsonResponse::send([
            'success' => true,
            'message' => $removed ? 'Program favorilerden çıkarıldı.' : 'Favori kaydı bulunamadı.',
        ]);
    }

    if ($path === '/api/preferences/reorder' && $method === 'PUT') {
        $firebaseUser = $authenticate();
        $body = $request->json();
        if (!isset($body['items']) || !is_array($body['items'])) {
            throw new RuntimeException('Tercih sıralaması geçersiz.', 422);
        }
        (new PreferenceRepository($db()))->reorder($firebaseUser['uid'], $body['items']);
        JsonResponse::send(['success' => true, 'message' => 'Tercih sıralaması kaydedildi.']);
    }

    if ($path === '/api/preferences') {
        $firebaseUser = $authenticate();
        $repository = new PreferenceRepository($db());
        if ($method === 'GET') {
            $items = $repository->all($firebaseUser['uid']);
            $profile = (new ProfileRepository($db()))->findByUid($firebaseUser['uid']);
            $targetRank = isset($profile['target_rank']) ? (int) $profile['target_rank'] : null;
            if ($targetRank !== null && $targetRank > 0) {
                $evaluation = new PreferenceEvaluationService();
                foreach ($items as &$item) {
                    $item['evaluation'] = $evaluation->evaluate(
                        $targetRank,
                        $item['base_rank'] !== null ? (int) $item['base_rank'] : null,
                        (int) $item['year']
                    );
                }
                unset($item);
            }
            JsonResponse::send(['success' => true, 'data' => ['items' => $items, 'user_rank' => $targetRank]]);
        }
        if ($method === 'POST') {
            $body = $request->json();
            $created = $repository->add(
                $firebaseUser['uid'],
                $positiveId($body['university_id'] ?? null, 'university_id'),
                (string) ($body['note'] ?? '')
            );
            JsonResponse::send([
                'success' => true,
                'message' => $created ? 'Program tercihlerinize eklendi.' : 'Program zaten tercih listenizde.',
            ], $created ? 201 : 200);
        }
    }

    if (preg_match('#^/api/preferences/(\d+)$#', $path, $matches)) {
        $firebaseUser = $authenticate();
        $repository = new PreferenceRepository($db());
        $universityId = (int) $matches[1];
        if ($method === 'PUT') {
            $updated = $repository->updateNote(
                $firebaseUser['uid'], $universityId, (string) (($request->json()['note'] ?? ''))
            );
            if (!$updated) {
                throw new RuntimeException('Tercih kaydı bulunamadı.', 404);
            }
            JsonResponse::send(['success' => true, 'message' => 'Tercih notu kaydedildi.']);
        }
        if ($method === 'DELETE') {
            $removed = $repository->remove($firebaseUser['uid'], $universityId);
            JsonResponse::send([
                'success' => true,
                'message' => $removed ? 'Program tercih listesinden çıkarıldı.' : 'Tercih kaydı bulunamadı.',
            ]);
        }
    }

    if ($method === 'GET' && $path === '/api/preference-suggestions') {
        $firebaseUser = $authenticate();
        $profile = (new ProfileRepository($db()))->findByUid($firebaseUser['uid']);
        $rankValue = $_GET['rank'] ?? ($profile['target_rank'] ?? null);
        if ($rankValue === null || $rankValue === '') {
            throw new RuntimeException('Profilinizde hedef sıralaması bulunmuyor.', 422);
        }
        $rank = $positiveId($rankValue, 'rank');
        $limit = $positiveId($_GET['limit'] ?? 30, 'limit');
        if ($limit > 60) {
            throw new RuntimeException('En fazla 60 öneri istenebilir.', 422);
        }

        $scoreMap = ['sayisal' => 'say', 'esit_agirlik' => 'ea', 'sozel' => 'soz', 'dil' => 'dil'];
        $typeMap = ['Devlet' => 'devlet', 'Vakıf' => 'vakif'];
        $preferredCity = '';
        if (!empty($profile['preferred_cities'])) {
            $preferredCity = trim(explode(',', (string) $profile['preferred_cities'])[0]);
        }
        $filters = [
            'user_rank' => $rank,
            'score_type' => $_GET['score_type'] ?? ($scoreMap[$profile['score_type'] ?? ''] ?? ''),
            'city' => $_GET['city'] ?? $preferredCity,
            'department' => $_GET['department'] ?? ($profile['target_department'] ?? ''),
            'university_type' => $_GET['university_type'] ?? ($typeMap[$profile['university_type'] ?? ''] ?? ''),
            'scholarship_type' => $_GET['scholarship_type'] ?? '',
            'year' => $_GET['year'] ?? 2025,
        ];
        $candidates = (new UniversityRepository($db()))->suggestionCandidates($filters, $limit);
        $groups = ['zor' => [], 'hedef' => [], 'daha_guvenli' => []];
        $perGroup = max(1, (int) ceil($limit / 3));
        $evaluationService = new PreferenceEvaluationService();
        foreach ($candidates as $candidate) {
            $evaluation = $evaluationService->evaluate($rank, (int) $candidate['base_rank'], (int) $candidate['year']);
            if (count($groups[$evaluation['label']]) < $perGroup) {
                $candidate['evaluation'] = $evaluation;
                $groups[$evaluation['label']][] = $candidate;
            }
        }
        JsonResponse::send(['success' => true, 'data' => [
            'user_rank' => $rank,
            'year' => (int) $filters['year'],
            'groups' => $groups,
            'disclaimer' => 'Bu gruplandırma geçmiş başarı sıralarına dayalı yaklaşık bir değerlendirmedir. Kontenjanlar, sınav zorluğu ve aday tercihleri her yıl değişebilir.',
        ]]);
    }

    JsonResponse::send(['success' => false, 'message' => 'İstenen kaynak bulunamadı.'], 404);
} catch (Throwable $exception) {
    $status = (int) $exception->getCode();
    if ($status < 400 || $status > 499) {
        $status = 500;
    }
    error_log(sprintf('[API] %s: %s', $exception::class, $exception->getMessage()));
    JsonResponse::send([
        'success' => false,
        'message' => $status === 500 ? 'İşlem şu anda tamamlanamadı.' : $exception->getMessage(),
    ], $status);
}
