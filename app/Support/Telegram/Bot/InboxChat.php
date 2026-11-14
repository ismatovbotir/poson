<?php

namespace App\Support\Telegram\Bot;

use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Secrets;
use App\Support\Telegram\PrivateGroupTarget;

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
 *  - verify(): delegated to PrivateGroupTarget (shared with careers, plan 31 CR-9):
 *    the chat must be a group or supergroup without a public username.
 */
final class InboxChat
{
    public const PATTERN = PrivateGroupTarget::PATTERN;

    public const VERIFY_TTL = PrivateGroupTarget::VERIFY_TTL;

    public const GENERATION_KEY = PrivateGroupTarget::GENERATION_KEY;

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

        return PrivateGroupTarget::normalize($value);
    }

    /**
     * Whether the chat is a non-public group the bot can see (cached, PrivateGroupTarget).
     *
     * @throws TelegramException on 401, 404, 429, 5xx, timeouts and connection failures
     */
    public static function verify(TelegramClient $client, string $chatId): bool
    {
        return PrivateGroupTarget::verify($client, $chatId);
    }

    /**
     * Drop the cached flag of one chat (a 400 / 403 from it).
     */
    public static function invalidate(string $chatId): void
    {
        PrivateGroupTarget::invalidate($chatId);
    }

    /**
     * Drop every cached flag (a chat id or the bot token was saved).
     */
    public static function forget(): void
    {
        PrivateGroupTarget::forget();
    }

    /**
     * The current verification generation (changes whenever forget() runs).
     */
    public static function generation(): string
    {
        return PrivateGroupTarget::generation();
    }
}
