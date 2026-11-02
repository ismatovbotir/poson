<?php

namespace App\Actions\Monitoring;

use App\Support\Admin\PasswordConfirmation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Shared checks of the monitoring write actions (MON-S8, S9): monitoring.manage
 * and a recent password confirmation, re-checked inside every action.
 */
trait GuardsMonitoringWrites
{
    /**
     * @throws AuthorizationException
     * @throws MonitoringActionException password
     */
    protected function guard(?object $user): void
    {
        if ($user === null || ! $user->can('monitoring.manage')) {
            throw new AuthorizationException;
        }

        if (! PasswordConfirmation::isRecent()) {
            throw new MonitoringActionException('password');
        }
    }

    /**
     * @throws MonitoringActionException rate
     */
    protected function limit(string $key, int $max): void
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new MonitoringActionException('rate');
        }
    }
}
