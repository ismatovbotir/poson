<?php

namespace App\Enums;

/**
 * Derived state of a banner for admin screens (plan 07 7c): inactive
 * (switched off), scheduled (starts_at in the future), expired (ends_at
 * passed) or active (shown now). Never stored; see Banner::state().
 */
enum BannerState: string
{
    case Active = 'active';
    case Scheduled = 'scheduled';
    case Expired = 'expired';
    case Inactive = 'inactive';

    public function label(): string
    {
        return __("enums.banner_state.{$this->value}");
    }

    /**
     * Badge color (x-admin.badge).
     */
    public function color(): string
    {
        return match ($this) {
            self::Active => 'brand',
            self::Scheduled => 'sky',
            self::Expired => 'amber',
            self::Inactive => 'muted',
        };
    }
}
