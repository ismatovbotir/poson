<?php

namespace App\Enums;

/**
 * Derived state of a news post for admin screens (plan 07 7b): draft
 * (status draft), scheduled (published, date in the future) or published
 * (published, date passed: public). Never stored; see NewsPost::state().
 */
enum NewsState: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';

    public function label(): string
    {
        return __("enums.news_state.{$this->value}");
    }

    /**
     * Badge color (x-admin.badge).
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'muted',
            self::Scheduled => 'sky',
            self::Published => 'brand',
        };
    }
}
