<?php

namespace App\Support\Chat;

use App\Enums\ChatChannel;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Whether the website chat may answer at all (plan 28 C5, CB-13, WC-6):
 * the widget is built (chatbot.web_ready) AND the web channel switch with
 * its privacy gate is on (ChatbotSwitch, memoized per request). Checked by
 * every web chat action on every call; off means "unavailable" and nothing
 * is stored. Says nothing about AI (chatbot.ai.web_ready, AiSwitch).
 *
 * isOpenCached(): the same answer from the cache for CACHE_SECONDS, for the
 * two hot paths that must stay query-free: the launcher on every page view
 * and the widget's idle poll (WC-11 ceiling). ChatbotSwitch::forget() (every
 * chatbot setting save) drops it, so switching the widget off takes effect
 * at once; an unpublished privacy page within CACHE_SECONDS. Writes never
 * rely on it: every action checks isOpen().
 */
final class WebChatSwitch
{
    public const CACHE_KEY = 'chatbot:web-open';

    public const CACHE_SECONDS = 30;

    public static function isOpen(): bool
    {
        return config('chatbot.web_ready') === true && ChatbotSwitch::isEnabled(ChatChannel::Web);
    }

    public static function isOpenCached(): bool
    {
        if (config('chatbot.web_ready') !== true) {
            return false;
        }

        try {
            return (int) Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): int => self::isOpen() ? 1 : 0) === 1;
        } catch (Throwable) {
            return self::isOpen();
        }
    }

    public static function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // The entry expires by itself within CACHE_SECONDS.
        }
    }
}
