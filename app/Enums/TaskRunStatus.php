<?php

namespace App\Enums;

/**
 * Outcome of one scheduled task run (scheduled_task_runs.status, plan 17).
 * A running row that never finished is later marked failed ("interrupted").
 */
enum TaskRunStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return __("enums.task_run_status.{$this->value}");
    }

    /**
     * Badge color (x-admin.badge).
     */
    public function color(): string
    {
        return match ($this) {
            self::Running => 'amber',
            self::Success => 'brand',
            self::Failed => 'red',
            self::Skipped => 'muted',
        };
    }
}
