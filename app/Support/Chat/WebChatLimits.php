<?php

namespace App\Support\Chat;

use App\Models\WebChat;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The website chat's rate limits (plan 28 C5, CB-19, WC-3, WC-11). Every
 * hit is atomic (count first, then compare) and runs BEFORE any transaction
 * opens, so the database cache store never holds a counter row while a
 * web_chats row is locked and a rollback never refunds a hit.
 *
 *  - message(): a visitor message, a pressed button or a menu topic: per
 *    chat chatbot.web.per_minute_chat (15) and per_day_chat (200), per IP
 *    hash per_minute_ip (60), all visitors per_minute_global (600); the
 *    narrowest first. Without a chat only the IP and the global budgets.
 *  - countNewChat(): the first message also counts toward the new chat's own budgets.
 *  - refresh(): the widget poll, per cookie token (no query needed to find
 *    the key) chatbot.web.refresh_per_minute (30); over it the widget runs
 *    no query and renders nothing.
 *  - contact(): the optional "leave your phone" card, per chat contact_per_hour (3).
 */
final class WebChatLimits
{
    public static function message(?WebChat $chat, string $ipHash): bool
    {
        if ($chat !== null) {
            if (RateLimiter::hit('web-chat:msg:chat:'.$chat->id, 60) > (int) config('chatbot.web.per_minute_chat', 15)) {
                return false;
            }

            if (RateLimiter::hit('web-chat:msg:day:'.$chat->id, 86400) > (int) config('chatbot.web.per_day_chat', 200)) {
                return false;
            }
        }

        if (RateLimiter::hit('web-chat:msg:ip:'.$ipHash, 60) > (int) config('chatbot.web.per_minute_ip', 60)) {
            return false;
        }

        return RateLimiter::hit('web-chat:msg:all', 60) <= (int) config('chatbot.web.per_minute_global', 600);
    }

    public static function countNewChat(WebChat $chat): void
    {
        RateLimiter::hit('web-chat:msg:chat:'.$chat->id, 60);
        RateLimiter::hit('web-chat:msg:day:'.$chat->id, 86400);
    }

    public static function refresh(string $token): bool
    {
        return RateLimiter::hit('web-chat:refresh:'.WebChatToken::hash($token), 60) <= (int) config('chatbot.web.refresh_per_minute', 30);
    }

    public static function contact(WebChat $chat): bool
    {
        return RateLimiter::hit('web-chat:contact:'.$chat->id, 3600) <= (int) config('chatbot.web.contact_per_hour', 3);
    }
}
