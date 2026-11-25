<?php

namespace App\Support\Office;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Flood limits of the Telegram Business ingest (plan 41, VO-3), config
 * office.limits. Counters only (cache), never content.
 *
 *  - new chat rows (any kind): ≤ new_chats_per_minute and ≤ new_chats_per_day;
 *  - stored messages per client chat and Tashkent day ≤ stored_messages_per_chat_daily
 *    (counters still move, nothing is stored over it);
 *  - getBusinessConnection for an unknown connection: once per id and hour,
 *    ≤ connection_fetches_per_hour overall.
 */
final class OfficeLimits
{
    public const NEW_CHATS_MINUTE = 'office:new-chats:minute';

    public const NEW_CHATS_DAY = 'office:new-chats:day';

    public const FETCHES_HOUR = 'office:connection-fetches:hour';

    public static function allowNewChat(): bool
    {
        if (RateLimiter::tooManyAttempts(self::NEW_CHATS_MINUTE, (int) config('office.limits.new_chats_per_minute', 30))
            || RateLimiter::tooManyAttempts(self::NEW_CHATS_DAY, (int) config('office.limits.new_chats_per_day', 200))) {
            return false;
        }

        RateLimiter::hit(self::NEW_CHATS_MINUTE, 60);
        RateLimiter::hit(self::NEW_CHATS_DAY, 86400);

        return true;
    }

    public static function allowStoredMessage(int $chatId): bool
    {
        $key = 'office:stored:'.$chatId.':'.now('Asia/Tashkent')->format('Ymd');
        $max = (int) config('office.limits.stored_messages_per_chat_daily', 500);

        Cache::add($key, 0, now()->addDay());

        return (int) Cache::increment($key) <= $max;
    }

    /**
     * One fetch per unknown connection id and hour, and a global hourly budget.
     */
    public static function allowConnectionFetch(string $connectionId): bool
    {
        if (! Cache::add('office:connection-fetch:'.hash('sha256', $connectionId), true, 3600)) {
            return false;
        }

        if (RateLimiter::tooManyAttempts(self::FETCHES_HOUR, (int) config('office.limits.connection_fetches_per_hour', 20))) {
            return false;
        }

        RateLimiter::hit(self::FETCHES_HOUR, 3600);

        return true;
    }
}
