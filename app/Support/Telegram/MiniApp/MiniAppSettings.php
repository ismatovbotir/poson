<?php

namespace App\Support\Telegram\MiniApp;

use App\Enums\SettingsArea;
use App\Models\Setting;
use App\Support\Telegram\Bot\BotSwitch;

/**
 * The three settings rows the Mini App reads on every screen (the Mini App
 * switch, the bot switch, the bot username) in one query. Inside a Mini App
 * request (route tma.*) the rows are kept for the rest of that request only;
 * anywhere else (jobs, commands, the bot webhook) every call reads fresh, so
 * a long-running worker never keeps a stale switch (MA-13).
 */
final class MiniAppSettings
{
    public const KEYS = [MiniAppSwitch::KEY, BotSwitch::KEY, BotUsername::KEY];

    private const MEMO = 'tma.settings';

    public static function value(string $key): mixed
    {
        return self::rows()[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function rows(): array
    {
        $request = app()->bound('request') ? request() : null;
        $memo = $request !== null && $request->routeIs('tma.*');

        if ($memo && is_array($rows = $request->attributes->get(self::MEMO))) {
            return $rows;
        }

        $rows = Setting::query()
            ->whereIn('key', self::KEYS)
            ->where('group', SettingsArea::TelegramBot->group())
            ->get(['key', 'value'])
            ->mapWithKeys(fn (Setting $setting): array => [$setting->key => $setting->value])
            ->all();

        if ($memo) {
            $request->attributes->set(self::MEMO, $rows);
        }

        return $rows;
    }
}
