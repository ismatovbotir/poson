<?php

namespace App\Support\Telegram\Bot;

use App\Support\Monitoring\MonitoringAlerts;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Customer bot abuse limits (plan 14 "Abuse and reliability", condition 8):
 *
 *  - per Telegram user: PER_MINUTE updates a minute and PER_DAY a day,
 *    messages and callbacks alike; over a limit the update is dropped and
 *    the user gets one "rate_limited" notice per window;
 *  - new telegram_users rows: NEW_USERS_PER_MINUTE a minute overall (over
 *    it the update is dropped silently, no row, nothing sent);
 *  - bot-created CRM customers: CUSTOMERS_PER_HOUR an hour overall; over it
 *    the registration still completes (identical reply) without a new
 *    customer and the management chat gets one alert an hour.
 *
 * The counters are hit inside HandleTelegramUpdate's DB transaction but live
 * in the cache, so a rolled-back update still counts (B4-S11a, kept on
 * purpose): a failing update can never be replayed to bypass a limit, and
 * Telegram does not resend a webhook update that was answered 200.
 */
final class BotLimits
{
    public const PER_MINUTE = 20;

    public const PER_DAY = 300;

    public const NEW_USERS_PER_MINUTE = 120;

    public const CUSTOMERS_PER_HOUR = 30;

    public const NEW_USERS_KEY = 'telegram-bot-new-users';

    public const CUSTOMERS_KEY = 'telegram-bot-customers';

    /**
     * Count one update of the user. Returns null when it is within the limits,
     * true when it is over a limit and the notice should be sent (first time
     * in that window), false when over a limit and already noticed.
     */
    public static function hitUser(int $telegramId): ?bool
    {
        foreach (['minute' => [self::PER_MINUTE, 60], 'day' => [self::PER_DAY, 86400]] as $window => [$max, $decay]) {
            $key = self::userKey($telegramId, $window);

            if (RateLimiter::hit($key, $decay) > $max) {
                return Cache::add($key.':noticed', true, max(1, RateLimiter::availableIn($key)));
            }
        }

        return null;
    }

    public static function userKey(int $telegramId, string $window): string
    {
        return "telegram-bot-user:{$window}:{$telegramId}";
    }

    /**
     * Count a new telegram_users row; false when the global cap is exceeded.
     * Atomic: the hit itself decides (no check-then-hit race).
     */
    public static function allowNewUser(): bool
    {
        return RateLimiter::hit(self::NEW_USERS_KEY, 60) <= self::NEW_USERS_PER_MINUTE;
    }

    /**
     * Count a bot-created customer; false (plus one management alert an hour,
     * sent after the transaction commits) when the hourly cap is exceeded.
     * Atomic like allowNewUser().
     */
    public static function allowNewCustomer(): bool
    {
        if (RateLimiter::hit(self::CUSTOMERS_KEY, 3600) <= self::CUSTOMERS_PER_HOUR) {
            return true;
        }

        DB::afterCommit(function (): void {
            if (Cache::add(self::CUSTOMERS_KEY.':alert', true, 3600)) {
                MonitoringAlerts::problem('telegram_customer_cap', ['limit' => self::CUSTOMERS_PER_HOUR], timeout: 2);
            }
        });

        return false;
    }
}
