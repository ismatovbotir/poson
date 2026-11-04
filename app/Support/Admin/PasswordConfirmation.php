<?php

namespace App\Support\Admin;

use Illuminate\Support\Facades\Date;

/**
 * Recent password re-confirmation for sensitive admin writes (plan 04 S7):
 * Settings → Integrations and Telegram → Settings need one within the last
 * config('admin.password_confirm_timeout') seconds (15 minutes).
 *
 * Uses Laravel's own session key ("auth.password_confirmed_at", set by
 * Session::passwordConfirmed()) with our shorter timeout. Confirm on
 * /admin/confirm-password (Admin\Auth\ConfirmPassword).
 */
final class PasswordConfirmation
{
    public const SESSION_KEY = 'auth.password_confirmed_at';

    public static function timeout(): int
    {
        return (int) config('admin.password_confirm_timeout', 900);
    }

    /**
     * A confirmation within the timeout; a timestamp in the future never counts.
     */
    public static function isRecent(): bool
    {
        $confirmedAt = session(self::SESSION_KEY);

        if (! is_numeric($confirmedAt)) {
            return false;
        }

        $now = Date::now()->unix();

        return (int) $confirmedAt <= $now && $now - (int) $confirmedAt < self::timeout();
    }

    /**
     * Seconds left before the confirmation expires (0 when there is none).
     */
    public static function secondsLeft(): int
    {
        $confirmedAt = session(self::SESSION_KEY);

        return self::isRecent() ? max(0, self::timeout() - (Date::now()->unix() - (int) $confirmedAt)) : 0;
    }

    public static function confirm(): void
    {
        session()->passwordConfirmed();
    }

    /**
     * URL of the confirm page that returns to $returnTo afterwards.
     */
    public static function url(string $returnTo): string
    {
        session()->put('url.intended', $returnTo);

        return route('admin.confirm-password');
    }
}
