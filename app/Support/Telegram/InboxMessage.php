<?php

namespace App\Support\Telegram;

use App\Models\TelegramMessage as TelegramMessageRow;
use App\Models\TelegramUser;
use App\Support\Telegram\Bot\BotText;
use Illuminate\Support\Facades\Route;

/**
 * Team-chat texts of the customer bot inbox (plan 14 v2 §6, B4-S7, TG-24),
 * HTML for parse_mode=HTML in the Telegram language:
 *
 *  - header(): sent before a customer's files (at most every 10 minutes per
 *    conversation and target chat);
 *  - alert(): a new text message (TelegramInboxMessage).
 *
 * Content: the fixed localized "Customer:" prefix, the cleaned display name
 * (registered name, else the Telegram first / last name; control, format and
 * bidi characters removed, whitespace collapsed, ≤ NAME_LIMIT) and
 * "chat #<telegram_users.id>" (never the Telegram id). The alert adds a
 * text excerpt (≤ EXCERPT_LIMIT after BotText::clean, before e()). Never a
 * username, phone, company or INN. Every value goes through e().
 *
 * The "Open in admin" button only when the admin thread route exists (B5)
 * and APP_URL is https.
 */
final class InboxMessage
{
    public const NAME_LIMIT = 64;

    public const EXCERPT_LIMIT = 300;

    public const ADMIN_ROUTE = 'admin.telegram.chats.show';

    public static function header(TelegramUser $user, string $locale): TelegramMessage
    {
        return new TelegramMessage(self::who($user, $locale), self::buttons($user, $locale));
    }

    public static function alert(TelegramUser $user, TelegramMessageRow $message, string $locale): TelegramMessage
    {
        $lines = [
            '<b>'.e(__('telegram.inbox.alert_title', [], $locale)).'</b>',
            self::who($user, $locale),
        ];

        $excerpt = self::excerpt($message->text);

        if ($excerpt !== '') {
            $lines[] = e($excerpt);
        }

        return new TelegramMessage(implode("\n", $lines), self::buttons($user, $locale));
    }

    /**
     * The display name as shown in team chats ('' when there is none).
     */
    public static function displayName(TelegramUser $user): string
    {
        $name = BotText::clean($user->name);

        if ($name === '') {
            $name = BotText::clean(trim(($user->first_name ?? '').' '.($user->last_name ?? '')));
        }

        return trim(mb_substr($name, 0, self::NAME_LIMIT));
    }

    public static function excerpt(?string $text): string
    {
        $text = BotText::clean($text);

        return mb_strlen($text) > self::EXCERPT_LIMIT ? rtrim(mb_substr($text, 0, self::EXCERPT_LIMIT - 1)).'…' : $text;
    }

    private static function who(TelegramUser $user, string $locale): string
    {
        $name = self::displayName($user);

        return '<b>'.e(__('telegram.inbox.customer', [], $locale)).'</b> '
            .e($name !== '' ? $name : __('telegram.inbox.unnamed', [], $locale))
            .' · '.e(__('telegram.inbox.chat', ['id' => $user->id], $locale));
    }

    /**
     * @return list<array{text: string, url: string}>
     */
    private static function buttons(TelegramUser $user, string $locale): array
    {
        if (! Route::has(self::ADMIN_ROUTE)) {
            return [];
        }

        return TelegramMessage::adminButton(route(self::ADMIN_ROUTE, $user->id, absolute: false), __('telegram.inbox.open_in_admin', [], $locale));
    }
}
