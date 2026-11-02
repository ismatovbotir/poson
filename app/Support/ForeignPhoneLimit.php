<?php

namespace App\Support;

use App\Support\Monitoring\MonitoringAlerts;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * TG-43: one global budget ("site-foreign-phone", config
 * site.foreign_phone_limit_per_hour) for public submissions with a non-Uzbek
 * phone, shared by the lead form, the cart quote and checkout. Uzbek numbers
 * are never counted. The first refusal in an hour sends one management alert.
 *
 * H1: the budget is touched LAST, only for submissions that would really be
 * processed (not bots, not rejected by the IP / phone limiters or validation),
 * so junk traffic cannot use it up.
 */
final class ForeignPhoneLimit
{
    public const KEY = 'site-foreign-phone';

    public const DECAY_SECONDS = 3600;

    /**
     * Count a submission that is about to be processed (every other check
     * passed); true when the phone is foreign and the cap is now exceeded.
     */
    public static function exceeded(?string $phone): bool
    {
        if (! self::isCounted($phone)) {
            return false;
        }

        if (RateLimiter::hit(self::KEY, self::DECAY_SECONDS) <= self::max()) {
            return false;
        }

        self::alertOnce();

        return true;
    }

    /**
     * Check without counting: true when the phone is foreign and the cap is
     * already used up. Checkout uses this before PlaceOrder and record() after it.
     */
    public static function reached(?string $phone): bool
    {
        if (! self::isCounted($phone) || ! RateLimiter::tooManyAttempts(self::KEY, self::max())) {
            return false;
        }

        self::alertOnce();

        return true;
    }

    /**
     * Count one processed submission (checkout, after PlaceOrder succeeded).
     */
    public static function record(?string $phone): void
    {
        if (self::isCounted($phone)) {
            RateLimiter::hit(self::KEY, self::DECAY_SECONDS);
        }
    }

    private static function isCounted(?string $phone): bool
    {
        return $phone !== null && Phone::isForeign($phone);
    }

    private static function max(): int
    {
        return (int) config('site.foreign_phone_limit_per_hour', 30);
    }

    private static function alertOnce(): void
    {
        if (Cache::add(self::KEY.':alert', true, self::DECAY_SECONDS)) {
            MonitoringAlerts::problem('foreign_phone_cap', ['limit' => self::max()], timeout: 2);
        }
    }
}
