<?php

namespace App\Support\Leads;

use Illuminate\Support\Facades\RateLimiter;

/**
 * The lead submission budgets shared by every lead form (plan 05, plan 20
 * MA-12): per IP and per phone number. The site forms
 * (GuardsLeadSubmissions) and the Telegram Mini App quote / service
 * requests use the same "site-lead-phone:" key, so one phone has one budget
 * whatever channel it uses. Hits are counted first, then compared, so
 * concurrent requests can't slip past; callers hit outside any DB
 * transaction.
 */
final class LeadLimits
{
    /**
     * Valid submissions allowed per IP within IP_SECONDS.
     */
    public const IP_LIMIT = 5;

    public const IP_SECONDS = 600;

    /**
     * Valid submissions allowed per phone number within PHONE_SECONDS.
     */
    public const PHONE_LIMIT = 3;

    public const PHONE_SECONDS = 3600;

    public static function ipKey(string $ip): string
    {
        return 'site-lead:'.sha1($ip);
    }

    public static function phoneKey(string $phone): string
    {
        return 'site-lead-phone:'.sha1($phone);
    }

    /**
     * Count a submission against the key; true when the budget is now exceeded.
     */
    public static function exceeded(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        return RateLimiter::hit($key, $decaySeconds) > $maxAttempts;
    }

    public static function ipExceeded(string $ip): bool
    {
        return self::exceeded(self::ipKey($ip), self::IP_LIMIT, self::IP_SECONDS);
    }

    public static function phoneExceeded(string $phone): bool
    {
        return self::exceeded(self::phoneKey($phone), self::PHONE_LIMIT, self::PHONE_SECONDS);
    }

    public static function availableIn(string $key): int
    {
        return RateLimiter::availableIn($key);
    }
}
