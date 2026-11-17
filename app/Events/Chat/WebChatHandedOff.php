<?php

namespace App\Events\Chat;

use App\Events\Contracts\TelegramAlert;
use App\Models\WebChat;
use App\Support\Telegram\TelegramMessage;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A website chat waits for staff (plan 28 C5 §19.3, CB-22, WC-13): alert
 * the sales chat (web hand-offs never go to the service chat; a service
 * request arrives as a service_request lead through LeadCreated).
 *
 * Raised by HandOffWebChat / PostVisitorMessage at most once per chat per
 * chatbot.web.alert_minutes (web_chats.last_alert_at under the row lock),
 * dispatched after commit, queued by SendTelegramAlerts. The payload is ids
 * only. The global cap (chatbot.web.alerts_per_hour) is hit here, in the
 * queued listener, outside any transaction (WC-3): over it exactly one
 * "limited" notice per hour goes out, then nothing; the unanswered count on
 * the monitoring row is the fallback (C5d).
 *
 * Content: "W-{id}", the chat's locale, the reason and an https admin link
 * built from app.url (never the request host). Never the visitor's phone,
 * name, text or page.
 */
class WebChatHandedOff implements ShouldDispatchAfterCommit, TelegramAlert
{
    use Dispatchable;

    public const REASON_HANDOFF = 'handoff';

    public const REASON_MESSAGE = 'message';

    /**
     * Admin path of the chat (the C5c Show screen, admin.chats.web.show).
     */
    public const ADMIN_PATH = '/admin/chats/web/';

    public const LIMIT_KEY = 'web-chat-alert:all';

    public const LIMITED_KEY = 'web-chat-alert:limited';

    public function __construct(public int $webChatId, public string $reason = self::REASON_HANDOFF) {}

    public function telegramChats(): array
    {
        return ['sales'];
    }

    public function telegramMessage(string $locale): ?TelegramMessage
    {
        $chat = WebChat::query()->find($this->webChatId, ['id', 'locale', 'banned_at']);

        if ($chat === null || $chat->isBanned()) {
            return null;
        }

        if (RateLimiter::hit(self::LIMIT_KEY, 3600) > max(1, (int) config('chatbot.web.alerts_per_hour', 30))) {
            return Cache::add(self::LIMITED_KEY, true, max(1, RateLimiter::availableIn(self::LIMIT_KEY))) ? $this->limited($locale) : null;
        }

        $reason = $this->reason === self::REASON_MESSAGE ? self::REASON_MESSAGE : self::REASON_HANDOFF;

        return new TelegramMessage(
            implode("\n", [
                '<b>'.e(__('telegram.web_chat.title', [], $locale)).'</b>',
                e(__('telegram.web_chat.chat', ['id' => $chat->id], $locale)).' · '.e(strtoupper($chat->locale)),
                e(__("telegram.web_chat.reason.{$reason}", [], $locale)),
            ]),
            TelegramMessage::adminButton(self::ADMIN_PATH.$chat->id, __('telegram.inbox.open_in_admin', [], $locale)),
        );
    }

    private function limited(string $locale): TelegramMessage
    {
        return new TelegramMessage(
            '<b>'.e(__('telegram.web_chat.limited', [], $locale)).'</b>',
            TelegramMessage::adminButton(route('admin.telegram.chats', absolute: false), __('telegram.inbox.open_in_admin', [], $locale)),
        );
    }
}
