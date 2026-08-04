<?php

declare(strict_types=1);

namespace DersRotasi\Subscriptions;

use DersRotasi\Config\Env;

final class PlanCatalog
{
    public function __construct(private readonly Env $env)
    {
    }

    public function limits(string $planCode): array
    {
        $premium = $planCode === 'premium';

        return [
            'daily_requests' => $premium
                ? $this->env->aiPremiumDailyRequests()
                : $this->env->aiFreeDailyRequests(),
            'daily_token_budget' => $premium
                ? $this->env->aiPremiumDailyTokenBudget()
                : $this->env->aiFreeDailyTokenBudget(),
            'max_message_chars' => $premium
                ? $this->env->aiPremiumMaxMessageChars()
                : $this->env->aiFreeMaxMessageChars(),
            'max_output_tokens' => $this->env->aiMaxOutputTokens(),
        ];
    }
}
