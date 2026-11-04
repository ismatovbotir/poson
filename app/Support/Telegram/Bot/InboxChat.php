<?php

namespace App\Support\Telegram\Bot;

use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Secrets;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The one team chat for the customer inbox (plan 14 v2 §23, B4-S1/S2): the
 * customer files copied by ForwardTelegramMedia and the TelegramInboxMessage
 * alert both go here.
 *
 *  - key(): telegram.chats.inbox when set, otherwise telegram.chats.sales
 *    (user decision 2026-11-02). A configured but unusable inbox chat never
 *    falls back silently to the sales chat: it is "not configured".
 *  - id(): the resolved value, only when it is a numeric group id
 *    (^-[1-9]\d{0,19}$, no leading zero).
 *    The registry still accepts @username values for the other chats; such a
 *    value is refused here (public channel / group, TG-23).
 *  - verify(): getChat must report a group or supergroup without a public
 *    username. The pass / fail flag is cached VERIFY_TTL seconds under
 *    sha1(chat id) (never the id itself) within a generation that forget()
 *    bumps when a chat id or the bot token is saved; invalidate() drops it on
 *    a 400 / 403 from the target. 429 / 5xx / timeouts are transient: thrown,
 *    never cached, never "not configured".
 */
final class InboxChat
{
    public const PATTERN = '/^-[1-9]\d{0,19}$/D';

    public const VERIFY_TTL = 600;

    public const GENERATION_KEY = 'telegram-inbox-chat:generation';

    /**
     * Chat key of services.telegram.chats in use: "inbox", "sales" or null.
     */
    public static function key(): ?string
    {
        foreach (['inbox', 'sales'] as $key) {
            if (Secrets::isConfigured('telegram.chats.'.$key)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * The resolved chat id when it is a private-looking numeric group id, else null.
     */
    public static function id(): ?string
    {
        $key = self::key();
        $value = $key !== null ? Secrets::get('telegram.chats.'.$key) : null;

        return is_string($value) && preg_match(self::PATTERN, trim($value)) === 1 ? trim($value) : null;
    }

    /**
     * Whether the chat is a non-public group the bot can see (cached).
     *
     * @throws TelegramException on 429, 5xx, timeouts and connection failures
     */
    public static function verify(TelegramClient $client, string $chatId): bool
    {
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
            if (! $exception->isPermanent()) {
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
