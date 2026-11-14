<?php

namespace App\Support\Telegram;

use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The one check that a team chat is a safe target for personal data (plan 31
 * CR-9, extracted from the customer inbox's InboxChat, TG-23): used before
 * every customer file copy, inbox alert, CV (sendDocument) and careers text
 * alert.
 *
 *  - normalize(): only a numeric group id (^-[1-9]\d{0,19}$, no leading
 *    zero); an @username (public channel / group) is refused.
 *  - verify(): getChat must report a group or supergroup without a public
 *    username (a channel or a private chat with a person is refused). The
 *    pass / fail flag is cached VERIFY_TTL seconds under sha1(chat id) (never
 *    the id itself) within a generation that forget() bumps when a chat id
 *    or the bot token is saved; invalidate() drops it on a 400 / 403 from the
 *    target. 429 / 5xx / timeouts are transient, and a 401 / 404 means the
 *    token is the problem, not the chat: thrown, never cached.
 *
 * The cache keys keep their original "telegram-inbox-chat" prefix so the
 * verdicts and the generation stay shared with the inbox code that predates this class.
 */
final class PrivateGroupTarget
{
    public const PATTERN = '/^-[1-9]\d{0,19}$/D';

    public const VERIFY_TTL = 600;

    public const GENERATION_KEY = 'telegram-inbox-chat:generation';

    /**
     * The value when it is a numeric group id, else null.
     */
    public static function normalize(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::PATTERN, trim($value)) === 1 ? trim($value) : null;
    }

    /**
     * Whether the chat is a non-public group the bot can see (cached).
     *
     * @throws TelegramException on 401, 404, 429, 5xx, timeouts and connection failures
     */
    public static function verify(TelegramClient $client, string $chatId): bool
    {
        if (self::normalize($chatId) === null) {
            return false;
        }

        $cacheKey = self::cacheKey($chatId);
        $cached = Cache::get($cacheKey);

        if (is_bool($cached)) {
            return $cached;
        }

        try {
            $chat = $client->getChat($chatId);
            $ok = in_array($chat['type'] ?? null, ['group', 'supergroup'], true)
                && (! isset($chat['username']) || $chat['username'] === '');
        } catch (TelegramException $exception) {
            // 429 / 5xx / timeouts, and a refused token (401) or unknown bot URL (404):
            // the verdict on the chat is unknown, never cached as a bad chat (security L2).
            if (! $exception->isPermanent() || in_array($exception->status, [401, 404], true)) {
                throw $exception;
            }

            $ok = false;
        }

        Cache::put($cacheKey, $ok, self::VERIFY_TTL);

        return $ok;
    }

    /**
     * Drop the cached flag of one chat (a 400 / 403 from it).
     */
    public static function invalidate(string $chatId): void
    {
        Cache::forget(self::cacheKey($chatId));
    }

    /**
     * Drop every cached flag (a chat id or the bot token was saved).
     */
    public static function forget(): void
    {
        Cache::forever(self::GENERATION_KEY, Str::random(12));
    }

    /**
     * The current verification generation (changes whenever forget() runs).
     * A cache failure reads as '?', which differs from any real generation.
     */
    public static function generation(): string
    {
        try {
            return (string) Cache::get(self::GENERATION_KEY, '0');
        } catch (\Throwable) {
            return '?';
        }
    }

    private static function cacheKey(string $chatId): string
    {
        return 'telegram-inbox-chat:'.Cache::get(self::GENERATION_KEY, '0').':'.sha1($chatId);
    }
}
