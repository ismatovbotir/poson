<?php

namespace App\Enums;

/**
 * What started a task run (scheduled_task_runs.trigger, plan 17).
 */
enum TaskRunTrigger: string
{
    case Schedule = 'schedule';
    case Manual = 'manual';

    public function label(): string
    {
        return __("enums.task_run_trigger.{$this->value}");
    }
}
