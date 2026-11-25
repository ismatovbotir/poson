<?php

namespace App\Jobs\Concerns;

use App\Enums\TelegramMessageStatus;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Services\Telegram\TelegramException;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Claim / send / mark of one stored outbound telegram_messages row, shared
 * by DeliverBotMessage and DeliverOrderStatusMessage (plan 14 v2 condition
 * 9, TG-25; plan 20 M5 §6.4). The using job defines RETRY_HOURS and
 * $maxExceptions; what is sent (text only, or text + one URL button) is the
 * job's own closure.
 *
 * - claim(): pending → sending by one conditional update, so two runs
 *   never both send;
 * - deliver(): 403 → telegram_users.blocked_at + failed; 400 / 404 →
 *   failed; 429 → back to pending, released for retry_after; 5xx / timeout
 *   → back to pending and rethrown for the backoff (10/60/300/900);
 * - failed(): retries used up → failed with the last code.
 * Logs: message id and error code only.
 */
trait DeliversStoredTelegramMessage
{
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(static::RETRY_HOURS);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
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
            $this->markMessage($message, TelegramMessageStatus::Failed, $status ?? 0);
        }
    }

    /**
     * Claim before sending (TG-25): only one run can move pending → sending.
     */
    protected function claim(TelegramMessage $message): bool
    {
        $claimed = TelegramMessage::query()
            ->whereKey($message->id)
            ->where('status', TelegramMessageStatus::Pending)
            ->update(['status' => TelegramMessageStatus::Sending, 'updated_at' => now()]);

        if ($claimed === 0) {
            return false;
        }

        $message->forceFill(['status' => TelegramMessageStatus::Sending])->syncOriginalAttribute('status');

        return true;
    }

    /**
     * Send a claimed message and record the outcome.
     *
     * @param  Closure(): array<string, mixed>  $send  The Bot API call; returns the sent message
     *
     * @throws TelegramException 5xx / timeout, for the backoff
     */
    protected function deliver(TelegramMessage $message, TelegramUser $user, Closure $send, string $logMessage): void
    {
        try {
            $sent = $send();
        } catch (TelegramException $exception) {
            Log::warning($logMessage, ['telegram_message_id' => $message->id, 'code' => $exception->code()]);

            if ($exception->isPermanent()) {
                if ($exception->status === 403) {
                    $user->forceFill(['blocked_at' => now()])->save();
                }

                $this->markMessage($message, TelegramMessageStatus::Failed, $exception->status);

                return;
            }

            // Back to pending (with the last code) so the retry can claim it again.
            $this->markMessage($message, TelegramMessageStatus::Pending, $exception->status);

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

    protected function markMessage(TelegramMessage $message, TelegramMessageStatus $status, int $code): void
    {
        $message->forceFill(['status' => $status, 'error_code' => $code > 0 ? $code : null])->save();
    }
}
