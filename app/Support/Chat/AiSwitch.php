<?php

namespace App\Support\Chat;

use App\Enums\ChatChannel;
use Illuminate\Support\Facades\Cache;

/**
 * Whether the chat bot may ask the AI model on a channel (plan 30 §2.6,
 * AI-24): the stored "chatbot.ai_{channel}_enabled" switch (Admin enables,
 * Content manager may disable) AND an API key in .env AND the channel's chat
 * bot switch with its privacy gate (ChatbotSwitch). The website also needs
 * chatbot.web_ready. Read from the memoized ChatSettings row: no extra query
 * (CB-31). Never resolves the model or the SDK.
 *
 * M3: a 401/403 from the API (key revoked or wrong) pauses AI answers for
 * AUTH_PAUSE_SECONDS (markAuthFailed), so every message is not one more
 * failing call; the rule bot answers meanwhile.
 */
final class AiSwitch
{
    public const AUTH_FAILED_KEY = 'chatbot:ai:auth_failed';

    public const AUTH_PAUSE_SECONDS = 900;

    public static function keyFor(ChatChannel $channel): string
    {
        return ChatSettings::key('ai_'.$channel->value.'_enabled');
    }

    /**
     * An API key is set (the value itself is never returned or shown, AI-1).
     */
    public static function keyConfigured(): bool
    {
        return trim((string) config('services.anthropic.key')) !== '';
    }

    /**
     * The raw switch an Admin saved, without the key and privacy conditions.
     */
    public static function isStored(ChatChannel $channel): bool
    {
        return ChatSettings::all()['ai'][$channel->value.'_enabled'] ?? false;
    }

    public static function isEnabled(ChatChannel $channel): bool
    {
        if ($channel === ChatChannel::Web && ! config('chatbot.web_ready')) {
            return false;
        }

        return self::keyConfigured() && self::isStored($channel) && ChatbotSwitch::isEnabled($channel) && ! self::authFailed();
    }

    /**
     * The AI product wizard switch an Admin saved (plan 32, "chatbot.ai_admin_enabled").
     */
    public static function adminStored(): bool
    {
        return ChatSettings::all()['ai']['admin_enabled'] ?? false;
    }

    /**
     * The AI product wizard may call the model: switch on, key set, not paused by a 401/403.
     */
    public static function adminEnabled(): bool
    {
        return self::keyConfigured() && self::adminStored() && ! self::authFailed();
    }

    public static function markAuthFailed(): void
    {
        Cache::put(self::AUTH_FAILED_KEY, true, self::AUTH_PAUSE_SECONDS);
    }

    public static function authFailed(): bool
    {
        return Cache::get(self::AUTH_FAILED_KEY) === true;
    }
}
