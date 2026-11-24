<?php

namespace App\Support\Monitoring;

use App\Enums\DiskMeasureStatus;
use App\Enums\DiskTarget;

/**
 * Result of one FolderSizer run (plan 37): bytes, files, status and
 * milliseconds per target. Numbers only, never a path or a name.
 */
final class DiskMeasurement
{
    /**
     * @param  array<string, array{bytes: int, files: int, status: DiskMeasureStatus, ms: int}>  $rows  DiskTarget value => figures
     */
    public function __construct(
        public readonly array $rows,
        public readonly bool $budgetHit = false,
        public readonly bool $aborted = false,
    ) {}

    /**
     * @return array{bytes: int, files: int, status: DiskMeasureStatus, ms: int}
     */
    public function row(DiskTarget $target): array
    {
        return $this->rows[$target->value] ?? ['bytes' => 0, 'files' => 0, 'status' => DiskMeasureStatus::Unreadable, 'ms' => 0];
    }

    public function total(): int
    {
        return $this->row(DiskTarget::ProjectTotal)['bytes'];
    }

    public function totalStatus(): DiskMeasureStatus
    {
        return $this->row(DiskTarget::ProjectTotal)['status'];
    }
}
