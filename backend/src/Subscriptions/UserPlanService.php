<?php

declare(strict_types=1);

namespace DersRotasi\Subscriptions;

use DersRotasi\AI\PdoAiUsageStore;
use RuntimeException;
use Throwable;

final class UserPlanService
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly PlanCatalog $catalog,
        private readonly PdoAiUsageStore $usage,
        private readonly UserRoleRepository $roles
    ) {
    }

    public function forUid(string $firebaseUid): array
    {
        try {
            $userKeyHash = hash('sha256', $firebaseUid);
            $resolved = $this->subscriptions->activePlan($userKeyHash);
            $planCode = $resolved['plan_code'];
            $role = $this->roles->role($userKeyHash);
            $isAdmin = $role === 'admin';
            $limits = $this->catalog->limits($planCode);
            $usage = $this->usage->usage($userKeyHash);

            return [
                'plan' => $planCode,
                'is_premium' => $planCode === 'premium',
                'role' => $role,
                'is_admin' => $isAdmin,
                'has_premium_access' => $isAdmin || $planCode === 'premium',
                'limits' => $limits,
                'available_plans' => [
                    'free' => $this->catalog->limits('free'),
                    'premium' => $this->catalog->limits('premium'),
                ],
                'usage' => [
                    ...$usage,
                    'requests_remaining' => $isAdmin
                        ? null
                        : max(0, $limits['daily_requests'] - $usage['requests_used']),
                    'tokens_remaining' => $isAdmin
                        ? null
                        : max(0, $limits['daily_token_budget'] - $usage['tokens_used']),
                ],
                'subscription' => $resolved['subscription'],
            ];
        } catch (RuntimeException $exception) {
            if ($exception->getCode() === 503) {
                throw $exception;
            }
            error_log('[Subscription] plan lookup failed');
            throw new RuntimeException('Plan bilgin şu anda doğrulanamıyor. Lütfen biraz sonra tekrar dene.', 503, $exception);
        } catch (Throwable $exception) {
            error_log('[Subscription] plan lookup failed');
            throw new RuntimeException('Plan bilgin şu anda doğrulanamıyor. Lütfen biraz sonra tekrar dene.', 503, $exception);
        }
    }
}
