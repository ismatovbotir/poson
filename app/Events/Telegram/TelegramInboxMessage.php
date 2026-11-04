<?php

namespace App\Events\Telegram;

use App\Events\Contracts\TelegramAlert;
use App\Models\TelegramMessage as TelegramMessageRow;
use App\Models\TelegramUser;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Secrets;
use App\Support\Telegram\Bot\BotSwitch;
use App\Support\Telegram\Bot\ForwardAlerts;
use App\Support\Telegram\Bot\InboxChat;
use App\Support\Telegram\InboxMessage;
use App\Support\Telegram\TelegramMessage;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Log;

/**
 * A registered bot customer wrote a text message (plan 14 v2 §5) → the inbox
 * chat (InboxChat: inbox, else sales; B4-S1) through the SendTelegramInboxAlert
 * listener. Raised by StoreInboundMessage at most once per conversation per 10
 * minutes (last_alert_at under the row lock) and only while
 * telegram.notify_inbox is on. Media raise no alert: the forward header is one.
 *
 * Payload: the telegram_messages id only. The target must pass the TG-23
 * getChat check (cached); otherwise, or when the check can't run now, no
 * alert is sent: a refused target raises the deduped refused_target
 * monitoring alert (message stays stored); a transient failure is rethrown so
 * SendTelegramInboxAlert retries, and when the retries run out releaseAlertSlot()
 * clears last_alert_at. Content: see InboxMessage::alert() (no phone, INN, company).
 */
class TelegramInboxMessage implements ShouldDispatchAfterCommit, TelegramAlert
{
    use Dispatchable;

    public function __construct(public int $telegramMessageId) {}

    public function telegramChats(): array
    {
        $key = InboxChat::key();
        $chatId = InboxChat::id();
        $client = app(TelegramClient::class);

        if ($key === null || ! $client->isConfigured()) {
            return [];
        }

        if ($chatId === null) {
            // Configured but not a numeric group id (e.g. @username): refused, not silent.
            ForwardAlerts::once('refused_target', 3600);

            return [];
        }

        try {
            if (InboxChat::verify($client, $chatId)) {
                return [$key];
            }
        } catch (TelegramException $exception) {
            Log::info('Telegram inbox alert delayed', ['telegram_message_id' => $this->telegramMessageId, 'code' => $exception->code()]);

            // Transient (429 / 5xx / timeout): SendTelegramInboxAlert retries; nothing is sent or cached meanwhile.
            throw $exception;
        }

        ForwardAlerts::once('refused_target', 3600);

        return [];
    }

    /**
     * Retries used up: give the conversation its alert slot back, so the next
     * customer text can raise the alert instead of waiting out the 10 minutes.
     * Only a slot this message owns is cleared (last_alert_at within a minute
     * of the message time; the next alert is at least ALERT_MINUTES later), so
     * a newer alert's slot is never wiped.
     */
    public function releaseAlertSlot(): void
    {
        $message = TelegramMessageRow::query()->find($this->telegramMessageId);

        if ($message === null) {
            return;
        }

        TelegramUser::query()
            ->whereKey($message->telegram_user_id)
            ->whereBetween('last_alert_at', [$message->created_at->copy()->subMinute(), $message->created_at->copy()->addMinute()])
            ->update(['last_alert_at' => null]);
    }

    public function telegramMessage(string $locale): ?TelegramMessage
    {
        // A retried alert honours the current settings.
        if (! BotSwitch::isEnabled() || Secrets::get('telegram.notify_inbox') === false) {
            return null;
        }

        $message = TelegramMessageRow::query()->with('telegramUser')->find($this->telegramMessageId);

        if ($message === null || $message->telegramUser === null || $message->telegramUser->isBanned()) {
            return null;
        }

        return InboxMessage::alert($message->telegramUser, $message, $locale);
    }
}
