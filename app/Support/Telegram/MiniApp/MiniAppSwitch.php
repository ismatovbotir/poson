<?php

namespace App\Support\Telegram\MiniApp;

use App\Support\Telegram\Bot\BotSwitch;

/**
 * The Telegram Mini App kill switch (plan 20 §10, MA-13): the settings row
 * "telegram.mini_app_enabled" (group telegram) AND the customer bot switch.
 * Absent, anything but true, or the bot off = off (fail closed). Read fresh
 * from the database (one query per Mini App request, MiniAppSettings), so a
 * disable stops the next request.
 * Written later by the admin Mini App page (M6, password re-confirm, audited).
 */
final class MiniAppSwitch
{
    public const KEY = 'telegram.mini_app_enabled';

    public static function isEnabled(): bool
    {
        return MiniAppSettings::value(self::KEY) === true && MiniAppSettings::value(BotSwitch::KEY) === true;
    }

    /**
     * The Mini App's own row only (the admin page shows it next to the bot switch).
     */
    public static function isStoredOn(): bool
    {
        return MiniAppSettings::value(self::KEY) === true;
    }
}
