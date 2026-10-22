<?php

namespace App\Support\Telegram;

use App\Support\Secrets;

/**
 * Language of every Telegram message (lead alerts, the Integrations test
 * message): the telegram.locale integration setting, Uzbek by default
 * (decision 2026-10-22).
 */
final class TelegramLocale
{
    public const DEFAULT = 'uz';

    public static function current(): string
    {
        $locale = Secrets::get('telegram.locale');

        return is_string($locale) && in_array($locale, config('app.supported_locales'), true) ? $locale : self::DEFAULT;
    }
}
