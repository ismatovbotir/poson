<?php

namespace App\Livewire\Site\Concerns;

use App\Enums\AnalyticsGoal;
use App\Support\Site\Analytics;
use Throwable;

/**
 * Plan 40 (T1, YD-3): a site component reached a conversion goal. Call it
 * once, in the success branch only (after the server stored the lead / chat,
 * never for bots or on validation errors). Dispatches the Livewire browser
 * event "poson-goal" with exactly one key (goal = the enum value) and only
 * while this visitor is tracked (Analytics::goalsEnabled(): production or
 * analytics_force, tracking on, not staff). resources/js/analytics.js
 * re-checks the name and calls ym(id, 'reachGoal', goal) without params.
 */
trait DispatchesAnalyticsGoals
{
    protected function reachGoal(AnalyticsGoal $goal): void
    {
        try {
            if (! app(Analytics::class)->goalsEnabled()) {
                return;
            }
        } catch (Throwable $exception) {
            // Analytics never breaks a form.
            report($exception);

            return;
        }

        $this->dispatch('poson-goal', goal: $goal->value);
    }
}
