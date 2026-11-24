<?php

namespace App\Enums;

/**
 * State of the project size measurement (monitoring_states row disk:progress).
 */
enum DiskRunStatus: string
{
    case Idle = 'idle';
    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';

    public function label(): string
    {
        return __("enums.disk_run_status.{$this->value}");
    }

    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
