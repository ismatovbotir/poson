<?php

namespace App\Enums;

/**
 * Why a measurement failed or is incomplete (DM-26): a fixed code, never a
 * message, so no path or hostname can reach the progress row.
 */
enum DiskRunError: string
{
    case Budget = 'budget';
    case UnreadableRoot = 'unreadable_root';
    case WorkerLost = 'worker_lost';
    case WorkerMissing = 'worker_missing';
    case Exception = 'exception';

    public function label(): string
    {
        return __("enums.disk_run_error.{$this->value}");
    }
}
