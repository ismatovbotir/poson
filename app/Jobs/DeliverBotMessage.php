<?php

namespace App\Jobs;

use App\Enums\TelegramDirection;
use App\Enums\TelegramMessageStatus;
use App\Models\TelegramMessage;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Telegram\Bot\BotSwitch;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deliver one stored outbound conversation message (telegram_messages,
 * direction out, status pending) to the customer (plan 14 v2 §5,
 * condition 9). Used by staff replies and the inbox auto-ack (B4/B5);
 * registration prompts are UI and go through SendBotPrompt unstored.
 *
 * - Payload: the message id only; the chat id is read from telegram_users
 *   when the job runs. A deleted row → the job ends quietly (the
 *   deleteWhenMissingModels behaviour for an id payload).
 * - Plain text, no parse_mode, no buttons (condition 15).
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
    use Queueable;

    /**
     * 429 releases must not use up the retries: the job may be retried for
     * RETRY_HOURS, while real failures (5xx / timeout, thrown) are capped by
     * $maxExceptions (first try + the four backoff steps).
     */
    public int $maxExceptions = 5;

    public const RETRY_HOURS = 1;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(self::RETRY_HOURS);
    }

    public function __construct(public int $telegramMessageId)
    {
        $this->onQueue('default');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(TelegramClient $client): void
    {
        $message = TelegramMessage::query()->with('telegramUser')->find($this->telegramMessageId);

        if (! $message instanceof TelegramMessage
            || $message->direction !== TelegramDirection::Out
            || $message->status !== TelegramMessageStatus::Pending
            || $message->telegramUser === null) {
            return;
        }

        $user = $message->telegramUser;

        if ($user->isBanned() || ! $client->isConfigured() || ! BotSwitch::isEnabled()) {
            return;
        }

        // Claim before sending (TG-25): only one run can move pending → sending.
        $claimed = TelegramMessage::query()
            ->whereKey($message->id)
            ->where('status', TelegramMessageStatus::Pending)
            ->update(['status' => TelegramMessageStatus::Sending, 'updated_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $message->forceFill(['status' => TelegramMessageStatus::Sending])->syncOriginalAttribute('status');

        try {
            $sent = $client->sendMessage((string) $user->telegram_id, (string) $message->text, [], null, parseMode: null);
        } catch (TelegramException $exception) {
            Log::warning('Telegram bot message failed', ['telegram_message_id' => $message->id, 'code' => $exception->code()]);

            if ($exception->isPermanent()) {
                if ($exception->status === 403) {
                    $user->forceFill(['blocked_at' => now()])->save();
                }

                $this->mark($message, TelegramMessageStatus::Failed, $exception->status);

                return;
            }

            // Back to pending (with the last code) so the retry can claim it again.
            $this->mark($message, TelegramMessageStatus::Pending, $exception->status);

            if ($exception->isRateLimited()) {
                $this->release($exception->retryAfter ?? $this->backoff()[0]);

                return;
            }

            throw $exception;
        }

        $message->forceFill([
            'status' => TelegramMessageStatus::Sent,
            'telegram_message_id' => is_int($sent['message_id'] ?? null) ? $sent['message_id'] : null,
            'sent_at' => now(),
            'error_code' => null,
        ])->save();
    }

    /**
     * Retries used up (5xx / timeouts, or still rate-limited after
     * RETRY_HOURS): the message is failed, keeping the last HTTP code.
     */
    public function failed(?Throwable $exception): void
    {
        $message = TelegramMessage::query()->find($this->telegramMessageId);

        if ($message instanceof TelegramMessage && in_array($message->status, [TelegramMessageStatus::Pending, TelegramMessageStatus::Sending], true)) {
            $status = $exception instanceof TelegramException && $exception->status > 0 ? $exception->status : $message->error_code;
            $this->mark($message, TelegramMessageStatus::Failed, $status ?? 0);
        }
    }

    private function mark(TelegramMessage $message, TelegramMessageStatus $status, int $code): void
    {
        $message->forceFill(['status' => $status, 'error_code' => $code > 0 ? $code : null])->save();
    }
}
