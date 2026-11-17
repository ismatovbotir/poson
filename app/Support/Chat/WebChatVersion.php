<?php

namespace App\Support\Chat;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Change marker of one website chat (plan 28 C5, CB-20, WC-11): cache key
 * "webchat:{id}:v". Every writer the visitor can see (visitor message, bot
 * reply, hand-off, staff reply, close, ban, expiry; the Plan 33 B job too)
 * calls bump(), which runs after the transaction commits, so a rolled-back
 * write never changes it. The widget's refresh() compares get() with the
 * version it rendered and skips the render when nothing changed.
 */
final class WebChatVersion
{
    public const TTL_HOURS = 48;

    public static function key(int $chatId): string
    {
        return "webchat:{$chatId}:v";
    }

    public static function bump(int $chatId): void
    {
        DB::afterCommit(function () use ($chatId): void {
            Cache::put(self::key($chatId), Str::random(16), now()->addHours(self::TTL_HOURS));
        });
    }

    /**
     * The current marker, or null when none is set or the cache can't be read
     * (the widget then renders instead of failing).
     */
    public static function get(int $chatId): ?string
    {
        try {
            $value = Cache::get(self::key($chatId));
        } catch (Throwable) {
            return null;
        }

        return is_string($value) ? $value : null;
    }
}
