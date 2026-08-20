<?php

declare(strict_types=1);

use DersRotasi\AI\AiChatService;
use DersRotasi\AI\AiChatValidator;
use DersRotasi\AI\AiConversationRepository;
use DersRotasi\AI\AiIntent;
use DersRotasi\AI\LazyAiGroundingProvider;
use DersRotasi\AI\OpenAiResponsesClient;
use DersRotasi\AI\PdoAiUsageStore;
use DersRotasi\AI\PdoRateLimitStore;
use DersRotasi\AI\RateLimiter;
use DersRotasi\Config\Env;
use DersRotasi\Database\Connection;
use DersRotasi\Http\JsonResponse;
use DersRotasi\Http\Request;
use DersRotasi\Middleware\FirebaseAuthMiddleware;
use DersRotasi\Repositories\FavoriteRepository;
use DersRotasi\Repositories\PreferenceRepository;
use DersRotasi\Repositories\ProfileRepository;
use DersRotasi\Repositories\StudyPlanRepository;
use DersRotasi\Repositories\UniversityRepository;
use DersRotasi\Repositories\YksEstimateRepository;
use DersRotasi\Repositories\YksRankDataRepository;
use DersRotasi\Services\FirebaseTokenVerifier;
use DersRotasi\Services\OfficialYksRankBandService;
use DersRotasi\Services\PremiumAiSummaryService;
use DersRotasi\Services\PremiumAnalysisService;
use DersRotasi\Services\StudyPlanGenerationService;
use DersRotasi\Services\PreferenceEvaluationService;
use DersRotasi\Services\YksBacktestConfidenceService;
use DersRotasi\Services\YksRankEstimator;
use DersRotasi\Services\YksScoreCalculator;
use DersRotasi\Subscriptions\PlanCatalog;
use DersRotasi\Subscriptions\PremiumAccessGuard;
use DersRotasi\Subscriptions\SubscriptionRepository;
use DersRotasi\Subscriptions\UserPlanService;
use DersRotasi\Subscriptions\UserRoleRepository;
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
$planForUid = static function (string $firebaseUid) use ($db, $env): array {
    return (new UserPlanService(
        new SubscriptionRepository($db()),
        new PlanCatalog($env),
        new PdoAiUsageStore($db()),
        new UserRoleRepository($db())
    ))->forUid($firebaseUid);
};
$runPremiumAi = static function (
    string $firebaseUid,
    string $requestId,
    string $feature,
    array $facts,
    callable $summarize
) use ($db, $env): array {
    if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $requestId)) {
        throw new RuntimeException('Geçerli bir request_id gönderilmelidir.', 422);
    }
    $usageStore = new PdoAiUsageStore($db());
    $plan = (new UserPlanService(
        new SubscriptionRepository($db()),
        new PlanCatalog($env),
        $usageStore,
        new UserRoleRepository($db())
    ))->forUid($firebaseUid);
    (new PremiumAccessGuard())->assertAllowed($plan);

    $userKeyHash = hash('sha256', $firebaseUid);
    $requestIdHash = hash('sha256', 'premium:' . $feature . ':' . $requestId);
    $factsJson = json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $reservedTokens = max(1, (int) ceil(strlen($factsJson) / 4)) + $env->aiMaxOutputTokens();
    $reservation = $usageStore->reserve(
        $userKeyHash,
        $requestIdHash,
        $plan['plan'],
        $plan['limits'],
        $reservedTokens,
        $env->aiGlobalDailyTokenBudget(),
        !$plan['is_admin']
    );
    if ($reservation['state'] === 'completed') {
        return $reservation['response'];
    }

    try {
        (new RateLimiter(new PdoRateLimitStore($db())))->hit('uid:' . $firebaseUid);
        if (!$env->aiChatEnabled()) {
            throw new RuntimeException('Dersrotası AI şu anda devre dışı.', 503);
        }
        $client = new OpenAiResponsesClient(
            $env->openAiApiKey(),
            $env->openAiModel(),
            $env->openAiTimeout(),
            $env->sslCaBundle(),
            null,
            $env->aiMaxOutputTokens()
        );
        $narration = $summarize(
            new PremiumAiSummaryService($client),
            hash('sha256', 'uid:' . $firebaseUid)
        );
        $response = [
            'success' => true,
            'data' => [...$facts, 'summary' => $narration['summary']],
            'meta' => [...$narration['meta'], 'plan' => $plan['plan'], 'feature' => $feature],
        ];
        return $usageStore->complete(
            $userKeyHash,
            $requestIdHash,
            (int) ($narration['meta']['usage']['total_tokens'] ?? 0),
            $response
        );
    } catch (Throwable $exception) {
        $usageStore->fail($userKeyHash, $requestIdHash);
        throw $exception;
    }
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

    if ($method === 'GET' && $path === '/api/me/plan') {
        $firebaseUid = $authenticate()['uid'];
        $usageStore = new PdoAiUsageStore($db());
        $plan = (new UserPlanService(
            new SubscriptionRepository($db()),
            new PlanCatalog($env),
            $usageStore,
            new UserRoleRepository($db())
        ))->forUid($firebaseUid);
        JsonResponse::send(['success' => true, 'data' => $plan]);
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

    if ($method === 'POST' && $path === '/api/yks/rank-band') {
        $service = new OfficialYksRankBandService(
            $root . '/config/yks/official_rank_distributions.php'
        );
        JsonResponse::send(['success' => true, 'data' => $service->compare($request->json())]);
    }

    if ($method === 'POST' && $path === '/api/premium/preference-analysis') {
        $firebaseUid = $authenticate()['uid'];
        $body = $request->json();
        $plan = $planForUid($firebaseUid);
        (new PremiumAccessGuard())->assertAllowed($plan);
        $facts = (new PremiumAnalysisService(
            new PreferenceRepository($db()),
            new ProfileRepository($db()),
            new UniversityRepository($db())
        ))->analyzePreferences($firebaseUid, $body['user_rank'] ?? null);
        $response = $runPremiumAi(
            $firebaseUid,
            (string) ($body['request_id'] ?? ''),
            'preference_analysis',
            $facts,
            static fn (PremiumAiSummaryService $service, string $safety): array =>
                $service->preferenceSummary($facts, $safety)
        );
        JsonResponse::send($response);
    }

    if ($method === 'POST' && $path === '/api/premium/program-comparison') {
        $firebaseUid = $authenticate()['uid'];
        $body = $request->json();
        $plan = $planForUid($firebaseUid);
        (new PremiumAccessGuard())->assertAllowed($plan);
        if (!is_array($body['program_ids'] ?? null)) {
            throw new RuntimeException('program_ids iki program kimliği içermelidir.', 422);
        }
        $facts = (new PremiumAnalysisService(
            new PreferenceRepository($db()),
            new ProfileRepository($db()),
            new UniversityRepository($db())
        ))->comparePrograms($firebaseUid, $body['program_ids'], $body['user_rank'] ?? null);
        $response = $runPremiumAi(
            $firebaseUid,
            (string) ($body['request_id'] ?? ''),
            'program_comparison',
            $facts,
            static fn (PremiumAiSummaryService $service, string $safety): array =>
                $service->comparisonSummary($facts, $safety)
        );
        JsonResponse::send($response);
    }

    if ($path === '/api/ai/conversations') {
        $firebaseUid = $authenticate()['uid'];
        $repository = new AiConversationRepository($db());
        $userKeyHash = hash('sha256', $firebaseUid);
        if ($method === 'GET') {
            JsonResponse::send(['success' => true, 'data' => ['items' => $repository->all($userKeyHash)]]);
        }
        if ($method === 'POST') {
            JsonResponse::send(['success' => true, 'data' => $repository->create($userKeyHash)], 201);
        }
    }

    if ($method === 'GET' && preg_match('#^/api/ai/conversations/(\d+)$#', $path, $matches)) {
        $firebaseUid = $authenticate()['uid'];
        $detail = (new AiConversationRepository($db()))->find(
            hash('sha256', $firebaseUid),
            (int) $matches[1],
            $firebaseUid
        );
        JsonResponse::send(['success' => true, 'data' => $detail]);
    }

    if ($method === 'POST' && $path === '/api/ai/chat') {
        $firebaseUid = $authenticate()['uid'];
        $body = $request->json();
        $requestId = $body['request_id'] ?? null;
        if (!is_string($requestId) || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $requestId)) {
            throw new RuntimeException('Geçerli bir request_id gönderilmelidir.', 422);
        }

        $userKeyHash = hash('sha256', $firebaseUid);
        $requestIdHash = hash('sha256', $requestId);
        $conversationRepository = new AiConversationRepository($db());
        if (!array_key_exists('conversation_id', $body) || $body['conversation_id'] === null) {
            $conversationId = $conversationRepository->create($userKeyHash)['id'];
        } else {
            $conversationId = $positiveId($body['conversation_id'], 'conversation_id');
            $conversationRepository->assertOwned($userKeyHash, $conversationId);
        }
        $usageStore = new PdoAiUsageStore($db());
        $planService = new UserPlanService(
            new SubscriptionRepository($db()),
            new PlanCatalog($env),
            $usageStore,
            new UserRoleRepository($db())
        );
        $plan = $planService->forUid($firebaseUid);
        $validated = (new AiChatValidator())->validate($body, $plan['limits']['max_message_chars']);
        $inputCharacters = strlen($validated['message']);
        foreach ($validated['history'] as $historyItem) {
            $inputCharacters += strlen($historyItem['content']);
        }
        $reservedTokens = max(1, (int) ceil($inputCharacters / 4)) + $env->aiMaxOutputTokens();
        if ($plan['plan'] === 'free') {
            $freePerMessageTokenAllowance = (int) ceil(
                $plan['limits']['daily_token_budget'] / $plan['limits']['daily_requests']
            );
            $reservedTokens = max($reservedTokens, $freePerMessageTokenAllowance);
        }
        $reservation = $usageStore->reserve(
            $userKeyHash,
            $requestIdHash,
            $plan['plan'],
            $plan['limits'],
            $reservedTokens,
            $env->aiGlobalDailyTokenBudget(),
            !$plan['is_admin']
        );
        if ($reservation['state'] === 'completed') {
            $cachedResponse = $reservation['response'];
            $cachedResponse['conversation'] = $conversationRepository->appendExchange(
                $userKeyHash,
                $conversationId,
                $requestIdHash,
                $validated['message'],
                $cachedResponse
            );
            JsonResponse::send($cachedResponse);
        }

        $rateIdentifier = 'uid:' . $firebaseUid;
        try {
            (new RateLimiter(new PdoRateLimitStore($db())))->hit($rateIdentifier);

            if (!$env->aiChatEnabled()) {
                throw new RuntimeException('Dersrotası AI şu anda devre dışı.', 503);
            }
            if ($env->openAiApiKey() === '') {
                throw new RuntimeException(
                    'Dersrotası AI henüz yapılandırılmadı: OPENAI_API_KEY eksik.',
                    503
                );
            }

            $intent = new AiIntent();
            $service = new AiChatService(
                new AiChatValidator(),
                $intent,
                new LazyAiGroundingProvider($db, $intent),
                new OpenAiResponsesClient(
                    $env->openAiApiKey(),
                    $env->openAiModel(),
                    $env->openAiTimeout(),
                    $env->sslCaBundle(),
                    null,
                    $env->aiMaxOutputTokens()
                ),
                $env->aiChatEnabled()
            );
            $response = $service->chat(
                $body,
                $firebaseUid,
                hash('sha256', $rateIdentifier)
            );
            $actualTokens = (int) ($response['meta']['usage']['total_tokens'] ?? 0);
            $response['meta']['plan'] = $plan['plan'];
            $response = $usageStore->complete(
                $userKeyHash,
                $requestIdHash,
                $actualTokens,
                $response
            );
            $response['conversation'] = $conversationRepository->appendExchange(
                $userKeyHash,
                $conversationId,
                $requestIdHash,
                $validated['message'],
                $response
            );
            JsonResponse::send($response);
        } catch (Throwable $exception) {
            $usageStore->fail($userKeyHash, $requestIdHash);
            throw $exception;
        }
    }

    if ($method === 'POST' && $path === '/api/study-plans/ai-generate') {
        $firebaseUid = $authenticate()['uid'];
        $body = $request->json();
        $requestId = $body['request_id'] ?? null;
        if (!is_string($requestId) || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $requestId)) {
            throw new RuntimeException('Geçerli bir request_id gönderilmelidir.', 422);
        }
        $usageStore = new PdoAiUsageStore($db());
        $plan = (new UserPlanService(
            new SubscriptionRepository($db()), new PlanCatalog($env), $usageStore, new UserRoleRepository($db())
        ))->forUid($firebaseUid);
        (new PremiumAccessGuard())->assertAllowed($plan, 'AI destekli çalışma planı Dersrotası Premium’a özel.');
        $userKeyHash = hash('sha256', $firebaseUid);
        $requestIdHash = hash('sha256', 'study-plan:' . $requestId);
        $inputJson = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $reservedTokens = max(1, (int) ceil(strlen($inputJson) / 4)) + $env->aiMaxOutputTokens();
        $reservation = $usageStore->reserve(
            $userKeyHash, $requestIdHash, $plan['plan'], $plan['limits'], $reservedTokens,
            $env->aiGlobalDailyTokenBudget(), !$plan['is_admin']
        );
        if ($reservation['state'] === 'completed') JsonResponse::send($reservation['response']);

        try {
            (new RateLimiter(new PdoRateLimitStore($db())))->hit('uid:' . $firebaseUid);
            if (!$env->aiChatEnabled()) throw new RuntimeException('Dersrotası AI şu anda devre dışı.', 503);
            $generated = (new StudyPlanGenerationService(new OpenAiResponsesClient(
                $env->openAiApiKey(), $env->openAiModel(), $env->openAiTimeout(),
                $env->sslCaBundle(), null, $env->aiMaxOutputTokens()
            )))->generate(
                $body,
                (new ProfileRepository($db()))->findByUid($firebaseUid),
                hash('sha256', 'uid:' . $firebaseUid)
            );
            $response = [
                'success' => true,
                'message' => 'AI çalışma planı önizlemesi hazır.',
                'data' => [
                    'tasks' => $generated['tasks'], 'summary' => $generated['summary'],
                    'disclaimer' => $generated['disclaimer'], 'week_start' => $body['week_start'] ?? '',
                ],
                'meta' => [...$generated['meta'], 'plan' => $plan['plan'], 'feature' => 'study_plan_generation'],
            ];
            JsonResponse::send($usageStore->complete(
                $userKeyHash, $requestIdHash,
                (int) ($generated['meta']['usage']['total_tokens'] ?? 0), $response
            ));
        } catch (Throwable $exception) {
            $usageStore->fail($userKeyHash, $requestIdHash);
            throw $exception;
        }
    }

    if ($method === 'POST' && $path === '/api/study-plans/ai-apply') {
        $firebaseUid = $authenticate()['uid'];
        $body = $request->json();
        $plan = $planForUid($firebaseUid);
        (new PremiumAccessGuard())->assertAllowed($plan, 'AI destekli çalışma planı Dersrotası Premium’a özel.');
        if (!is_array($body['tasks'] ?? null)) {
            throw new RuntimeException('Kaydedilecek AI görevleri geçersiz.', 422);
        }
        $week = (new StudyPlanRepository($db()))->addGeneratedTasks(
            $firebaseUid, $body['week_start'] ?? '', $body['tasks']
        );
        JsonResponse::send([
            'success' => true, 'message' => 'AI çalışma planı haftana eklendi.', 'data' => ['plan' => $week],
        ], 201);
    }

    if ($path === '/api/study-plans') {
        $firebaseUid = $authenticate()['uid'];
        $repository = new StudyPlanRepository($db());
        $weekStart = $method === 'GET' ? ($_GET['week_start'] ?? '') : ($request->json()['week_start'] ?? '');
        if ($method === 'GET') {
            JsonResponse::send(['success' => true, 'data' => $repository->week($firebaseUid, $weekStart)]);
        }
        if ($method === 'DELETE') {
            $deleted = $repository->clearWeek($firebaseUid, $weekStart);
            JsonResponse::send(['success' => true, 'message' => "{$deleted} görev silindi."]);
        }
    }

    if ($method === 'POST' && $path === '/api/study-plans/tasks') {
        $firebaseUid = $authenticate()['uid'];
        $body = $request->json();
        $task = (new StudyPlanRepository($db()))->addTask($firebaseUid, $body['week_start'] ?? '', $body);
        JsonResponse::send(['success' => true, 'message' => 'Görev eklendi.', 'data' => $task], 201);
    }

    if (preg_match('#^/api/study-plans/tasks/(\d+)$#', $path, $matches)) {
        $firebaseUid = $authenticate()['uid'];
        $repository = new StudyPlanRepository($db());
        if ($method === 'PUT') {
            JsonResponse::send([
                'success' => true, 'message' => 'Görev güncellendi.',
                'data' => $repository->updateTask($firebaseUid, (int) $matches[1], $request->json()),
            ]);
        }
        if ($method === 'DELETE') {
            if (!$repository->removeTask($firebaseUid, (int) $matches[1])) {
                throw new RuntimeException('Çalışma görevi bulunamadı.', 404);
            }
            JsonResponse::send(['success' => true, 'message' => 'Görev silindi.']);
        }
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

    if ($method === 'GET' && $path === '/api/universities/options') {
        $limit = $positiveId($_GET['limit'] ?? 20, 'limit');
        JsonResponse::send(['success' => true, 'data' => ['items' => (new UniversityRepository($db()))->options(
            (string) ($_GET['type'] ?? ''),
            (string) ($_GET['q'] ?? ''),
            $_GET['university'] ?? [],
            (string) ($_GET['value'] ?? ''),
            $limit
        )]]);
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
        $responseYear = is_array($filters['year'])
            ? array_values(array_unique(array_map('intval', $filters['year'])))
            : (int) $filters['year'];
        if (is_array($responseYear) && count($responseYear) === 1) {
            $responseYear = $responseYear[0];
        }
        JsonResponse::send(['success' => true, 'data' => [
            'user_rank' => $rank,
            'year' => $responseYear,
            'groups' => $groups,
            'disclaimer' => 'Bu gruplandırma geçmiş başarı sıralarına dayalı yaklaşık bir değerlendirmedir. Kontenjanlar, sınav zorluğu ve aday tercihleri her yıl değişebilir.',
        ]]);
    }

    JsonResponse::send(['success' => false, 'message' => 'İstenen kaynak bulunamadı.'], 404);
} catch (Throwable $exception) {
    $status = (int) $exception->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    error_log(sprintf('[API] %s: %s', $exception::class, $exception->getMessage()));
    $safeServerErrors = [502, 503, 504];
    JsonResponse::send([
        'success' => false,
        'message' => $status === 500 || ($status >= 500 && !in_array($status, $safeServerErrors, true))
            ? 'İşlem şu anda tamamlanamadı.'
            : $exception->getMessage(),
    ], $status);
}
