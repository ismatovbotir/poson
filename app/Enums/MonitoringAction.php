<?php

namespace App\Enums;

/**
 * Admin action on the monitoring screen (monitoring_actions.action, plan 17).
 */
enum MonitoringAction: string
{
    case RunTask = 'run_task';
    case RetryJob = 'retry_job';
    case ForgetJob = 'forget_job';

    public function label(): string
    {
        return __("enums.monitoring_action.{$this->value}");
    }
}
