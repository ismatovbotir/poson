<?php

namespace App\Support\Telegram\Bot;

use App\Enums\SettingsArea;
use App\Models\Setting;

/**
 * The customer bot kill switch (plan 14 v2 D12, TG-37): a plain settings row
 * "telegram.bot_enabled" (group telegram). Absent or anything but true = off.
 * Not an integration field, so no .env value can bypass the checklist.
 *
 * Read fresh from the database on every call (no cache), so a disable takes
 * effect on the very next update. Written only by SetBotEnabled (audited).
 */
final class BotSwitch
{
    public const KEY = 'telegram.bot_enabled';

    public static function isEnabled(): bool
    {
        return Setting::query()->where('key', self::KEY)->where('group', SettingsArea::TelegramBot->group())->first()?->value === true;
    }
}
