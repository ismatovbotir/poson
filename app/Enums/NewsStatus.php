<?php

namespace App\Enums;

/**
 * News post status (plan 07 K8). A published post is public once its
 * published_at has passed: a future date means "scheduled" (no cron; the
 * site compares with now()). See NewsPost::state().
 */
enum NewsStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function label(): string
    {
        return __("enums.news_status.{$this->value}");
    }

    /**
     * Badge color (x-admin.badge).
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'muted',
            self::Published => 'brand',
        };
    }
}
