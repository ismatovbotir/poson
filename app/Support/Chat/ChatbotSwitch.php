<?php

namespace App\Support\Chat;

use App\Enums\ChatChannel;
use App\Enums\SettingsArea;
use App\Models\Setting;
use ArrayObject;

/**
 * Per-channel kill switch of the rule bot (plan 28, CB-13): the settings row
 * "chatbot.telegram_enabled" (group chatbot). Absent or anything but true =
 * off, and then the Telegram bot behaves exactly as before the chat bot
 * existed (every registered message goes to the inbox). Read once per request
 * or job (and again after a chatbot setting row is saved), so a disable takes
 * effect on the next update.
 *
 * Only read here. Enabling is Admin-only and gated (privacy text) in C2.
 */
final class ChatbotSwitch
{
    public const TELEGRAM_KEY = 'chatbot.telegram_enabled';

    public const WEB_KEY = 'chatbot.web_enabled';

    private const STATE = 'chatbot.switch.state';

    /**
     * Memoized for the rest of the request or queued job (the state is a
     * scoped container instance, reset between requests and jobs, and
     * dropped when a chatbot setting row is saved), so the menu keyboard and
     * the routing don't read the row again and again.
     */
    public static function isEnabled(ChatChannel $channel = ChatChannel::Telegram): bool
    {
        $state = app(self::STATE);

        return $state[$channel->value] ??= Setting::query()
            ->where('key', self::keyFor($channel))
            ->where('group', SettingsArea::Chatbot->group())
            ->first()?->value === true;
    }

    public static function forget(): void
    {
        app(self::STATE)->exchangeArray([]);
    }

    public static function register(): void
    {
        app()->scoped(self::STATE, fn () => new ArrayObject);
    }

    public static function keyFor(ChatChannel $channel): string
    {
        return match ($channel) {
            ChatChannel::Telegram => self::TELEGRAM_KEY,
            ChatChannel::Web => self::WEB_KEY,
        };
    }
}
