<?php

namespace App\Enums;

/**
 * Outcome of one price API batch (price_sync_logs.status, plan 09):
 * success = every item matched and valid; partial = some items unknown or
 * invalid (the valid ones were applied); rejected = the whole batch was
 * refused (wrong content type, bad JSON, items missing / empty / > 100).
 */
enum PriceSyncStatus: string
{
    case Success = 'success';
    case Partial = 'partial';
    case Rejected = 'rejected';

    public function label(): string
    {
        return __("enums.price_sync_status.{$this->value}");
    }

    /**
     * Badge color (x-admin.badge).
     */
    public function color(): string
    {
        return match ($this) {
            self::Success => 'brand',
            self::Partial => 'amber',
            self::Rejected => 'red',
        };
    }
}
