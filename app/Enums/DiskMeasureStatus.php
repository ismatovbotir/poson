<?php

namespace App\Enums;

/**
 * Result of measuring one target (disk_snapshots.status, plan 37). Partial
 * means a budget was hit or part of the tree was unreadable: the size is a
 * lower bound.
 */
enum DiskMeasureStatus: string
{
    case Ok = 'ok';
    case Partial = 'partial';
    case Missing = 'missing';
    case Unreadable = 'unreadable';

    public function label(): string
    {
        return __("enums.disk_measure_status.{$this->value}");
    }

    /**
     * Badge color (x-admin.badge).
     */
    public function color(): string
    {
        return match ($this) {
            self::Ok => 'brand',
            self::Partial => 'amber',
            self::Missing => 'muted',
            self::Unreadable => 'red',
        };
    }
}
