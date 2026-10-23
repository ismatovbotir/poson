<?php

namespace App\Services\Ai;

use App\Enums\AiErrorCode;
use App\Enums\AiFeature;
use App\Enums\AiRequestStatus;
use App\Models\AiRequest;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * AI limits (plan 02, decision 2026-10-20): text requests per user per day
 * (Settings → Integrations, default 100) and a monthly budget in USD for
 * everyone (empty = no cap). Days and months follow Tashkent time.
 * Rejected requests don't count; failed ones do (they may have cost).
 *
 * The budget counts finished requests at their real cost and open ones
 * (pending / running) at a worst-case estimate, plus the new request's
 * estimate: concurrent requests can't overshoot it. Check + insert run
 * under the "ai-quota" lock (QueueTextRequest).
 */
final class AiQuota
{
    public const LOCK = 'ai-quota';

    /**
     * @throws AiException quota_exceeded / budget_exceeded
     */
    public static function ensureAvailable(User $user, ?string $model = null): void
    {
        if (self::budgetReached($model)) {
            throw AiException::for(AiErrorCode::BudgetExceeded);
        }

        if (self::remainingToday($user) <= 0) {
            throw AiException::for(AiErrorCode::QuotaExceeded);
        }
    }

    public static function dailyLimit(): int
    {
        $limit = config('ai-assist.limits.daily_text');

        return is_numeric($limit) ? max(0, (int) $limit) : 100;
    }

    public static function usedToday(User $user): int
    {
        return AiRequest::query()
            ->where('user_id', $user->id)
            ->whereIn('feature', array_map(fn (AiFeature $feature): string => $feature->value, AiFeature::text()))
            ->counted()
            ->since(self::startOfDay())
            ->count();
    }

    public static function remainingToday(User $user): int
    {
        return max(0, self::dailyLimit() - self::usedToday($user));
    }

    /**
     * Monthly budget in micro-USD, null when there is no cap.
     */
    public static function budgetMicroUsd(): ?int
    {
        $budget = config('ai-assist.limits.monthly_budget_usd');

        return is_numeric($budget) ? max(0, (int) $budget) * AiRequest::MICRO : null;
    }

    /**
     * Cost of this calendar month (all users) in micro-USD: finished
     * requests at their cost, open ones at the worst-case estimate.
     */
    public static function monthCostMicroUsd(): int
    {
        $open = array_map(fn (AiRequestStatus $status): string => $status->value, [AiRequestStatus::Pending, AiRequestStatus::Running]);

        $spent = (int) AiRequest::query()->since(self::startOfMonth())->whereNotIn('status', $open)->sum('cost_micro_usd');

        $reserved = AiRequest::query()->since(self::startOfMonth())->whereIn('status', $open)
            ->toBase()->selectRaw('model, COUNT(*) AS open_count')->groupBy('model')->get()
            ->sum(fn (object $row): int => (int) $row->open_count * self::estimateMicroUsd($row->model));

        return $spent + $reserved;
    }

    /**
     * Worst-case cost of one text request: the input estimate plus the
     * maximum output, at the model's rates (fallback rates for unknown models).
     */
    public static function estimateMicroUsd(?string $model): int
    {
        return AiCost::microUsd(
            (string) ($model ?? ClaudeTextAssistant::model()),
            (int) config('ai-assist.input_tokens_estimate', 6000),
            (int) config('ai-assist.max_output_tokens', 8192),
        );
    }

    /**
     * The month's cost (with open requests) plus one more request of $model
     * would pass the budget. Without $model: the budget is already used up.
     */
    public static function budgetReached(?string $model = null): bool
    {
        $budget = self::budgetMicroUsd();

        if ($budget === null) {
            return false;
        }

        $cost = self::monthCostMicroUsd();

        return $model === null ? $cost >= $budget : $cost + self::estimateMicroUsd($model) > $budget;
    }

    public static function timezone(): string
    {
        return (string) config('ai-assist.timezone', 'Asia/Tashkent');
    }

    public static function startOfDay(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfDay();
    }

    public static function startOfMonth(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfMonth();
    }
}
