<?php

namespace App\Jobs;

use App\Enums\TelegramDirection;
use App\Enums\TelegramMessageStatus;
use App\Jobs\Concerns\DeliversStoredTelegramMessage;
use App\Models\TelegramMessage;
use App\Services\Telegram\TelegramClient;
use App\Support\Telegram\Bot\BotSwitch;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Deliver one stored outbound conversation message (telegram_messages,
 * direction out, status pending) to the customer (plan 14 v2 §5,
 * condition 9). Used by staff replies and the inbox auto-ack (B4/B5);
 * registration prompts are UI and go through SendBotPrompt unstored.
 *
 * - Payload: the message id only; the chat id is read from telegram_users
 *   when the job runs. A deleted row → the job ends quietly (the
 *   deleteWhenMissingModels behaviour for an id payload).
 * - Plain text, no parse_mode, no buttons (condition 15). Order status
 *   rows are never sent from here (DeliverOrderStatusMessage, plan 20 M5).
 * - Claim / send / mark shared with DeliverOrderStatusMessage
 *   (DeliversStoredTelegramMessage).
 * - Claimed first (pending → sending, conditional update, TG-25), so two
 *   runs never both send. Then sent (telegram_message_id, sent_at) or
 *   failed (error_code): 403 → telegram_users.blocked_at + failed;
 *   400 / 404 → failed; 429 → back to pending, released for retry_after
 *   (does not use up retries, retryUntil); 5xx / timeout → back to pending,
 *   backoff 10/60/300/900, failed after $maxExceptions (failed()).
 *   A worker killed mid-send leaves "sending" (never re-sent automatically).
 * - Kill switch off, no token, or a banned user: nothing is sent and the row
 *   stays pending (retried by its author from the inbox later). Not in
 *   monitoring.retry_classes on purpose (D13).
 * - Logs: message id and error code only (never token, URL or text).
 */
class DeliverBotMessage implements ShouldQueueAfterCommit
{
    use DeliversStoredTelegramMessage, Queueable;

    /**
     * 429 releases must not use up the retries: the job may be retried for
     * RETRY_HOURS, while real failures (5xx / timeout, thrown) are capped by
     * $maxExceptions (first try + the four backoff steps).
     */
    public int $maxExceptions = 5;

    public const RETRY_HOURS = 1;

    public function __construct(public int $telegramMessageId)
    {
        $this->onQueue('default');
    }

    public function handle(TelegramClient $client): void
    {
        $message = TelegramMessage::query()->with('telegramUser')->find($this->telegramMessageId);

        // Order status rows (plan 20 M5) are delivered only by DeliverOrderStatusMessage (MA-32).
        if (! $message instanceof TelegramMessage
            || $message->direction !== TelegramDirection::Out
            || $message->status !== TelegramMessageStatus::Pending
            || $message->isOrderStatusMessage()
            || $message->telegramUser === null) {
            return;
        }

        $user = $message->telegramUser;

        if ($user->isBanned() || ! $client->isConfigured() || ! BotSwitch::isEnabled()) {
            return;
        }

        if (! $this->claim($message)) {
            return;
        }

        // Plain text, no parse_mode, no buttons (condition 15).
        $this->deliver(
            $message,
            $user,
            fn (): array => $client->sendMessage((string) $user->telegram_id, (string) $message->text, [], null, parseMode: null),
            'Telegram bot message failed',
        );
    }
}
