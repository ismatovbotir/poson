<?php

namespace App\Support\Telegram\MiniApp;

/**
 * The bot's public username (plan 20 §7, MA-20): the non-secret settings row
 * "telegram.bot_username", written from getMe() (M5 "Check bot"). Validated
 * on read as on write; anything else is null, so no link is built from it.
 */
final class BotUsername
{
    public const KEY = 'telegram.bot_username';

    public const PATTERN = '/\A[A-Za-z0-9_]{5,32}\z/';

    public static function get(): ?string
    {
        $value = MiniAppSettings::value(self::KEY);

        return self::valid($value) ? $value : null;
    }

    public static function valid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * https://t.me/<bot>, optionally with a start payload; null without a stored username.
     */
    public static function link(?string $start = null): ?string
    {
        $username = self::get();

        if ($username === null) {
            return null;
        }

        return 'https://t.me/'.$username.($start !== null && preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $start) === 1 ? '?start='.$start : '');
    }
}
