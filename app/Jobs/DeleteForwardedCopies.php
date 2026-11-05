<?php

namespace App\Jobs;

use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Telegram\Bot\BotSwitch;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Best-effort removal of a deleted conversation's copies from the staff chat
 * (TG-27 amended, plan 21 B4). Used by the Admin delete only: retention makes
 * no Telegram call.
 *
 * - Pairs come from pairsFor(), captured before the rows are deleted: only
 *   stored (chat id, message id) pairs under MAX_AGE_HOURS, so a row missing
 *   either value is never called. The chat is never the current setting or
 *   client input, so a target switch still uses the old chat.
 * - Payload: ids only.
 * - Each failure is counted, never retried, and never reaches the chat check or
 *   lost_target (T21-9): no InboxChat or ForwardAlerts call here. A 401, 404 or
 *   429 ends the run, and what is left counts as failed. So does a run over
 *   DEADLINE_SECONDS. The timeout stays under the queue's default retry_after (90 s).
 * - Outside production, kill switch off or no token: no call. Staging or a copied
 *   database never deletes from the production chat, even with its token (T21-9).
 * - Logs: counts only. Not in monitoring.retry_classes on purpose.
 */
class DeleteForwardedCopies implements ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * Telegram allows a delete within 48 hours of sending; one hour is kept in reserve.
     */
    public const MAX_AGE_HOURS = 47;

    public const DEADLINE_SECONDS = 70;

    private const CALL_TIMEOUT = 5;

    private const CONNECT_TIMEOUT = 3;

    public int $tries = 1;

    public int $timeout = 85;

    public bool $failOnTimeout = true;

    /**
     * @param  list<array{0: int, 1: int}>  $pairs  (chat id, message id)
     */
    public function __construct(public array $pairs)
    {
        $this->onQueue('default');
    }

    /**
     * The stored copies of this conversation young enough to delete: file copies
     * by created_at and the latest header by forward_header_at.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function pairsFor(TelegramUser $conversation): array
    {
        $cutoff = now()->subHours(self::MAX_AGE_HOURS);

        $pairs = $conversation->messages()
            ->whereNotNull('forwarded_chat_id')
            ->whereNotNull('forwarded_message_id')
            // created_at, not the send time: a late retry can only make a copy look older, never younger.
            ->where('created_at', '>', $cutoff)
            ->orderBy('id')
            ->get(['forwarded_chat_id', 'forwarded_message_id'])
            ->map(fn (TelegramMessage $row): array => [(int) $row->forwarded_chat_id, (int) $row->forwarded_message_id])
            ->all();

        if ($conversation->forward_header_chat_id !== null
            && $conversation->forward_header_message_id !== null
            && $conversation->forward_header_at?->greaterThan($cutoff)) {
            $pairs[] = [(int) $conversation->forward_header_chat_id, (int) $conversation->forward_header_message_id];
        }

        return array_values(array_unique($pairs, SORT_REGULAR));
    }

    public function handle(TelegramClient $client): void
    {
        $total = count($this->pairs);

        if (! app()->isProduction() || ! $client->isConfigured() || ! BotSwitch::isEnabled()) {
            Log::info('Telegram forwarded copies not deleted', ['skipped' => $total]);

            return;
        }

        $client = $client->withTimeout(self::CALL_TIMEOUT, self::CONNECT_TIMEOUT);
        $deadline = now()->addSeconds(self::DEADLINE_SECONDS);
        $deleted = 0;

        foreach ($this->pairs as [$chatId, $messageId]) {
            if (now()->greaterThan($deadline)) {
                break;
            }

            try {
                $client->deleteMessage((string) $chatId, $messageId);
                $deleted++;
            } catch (TelegramException $failure) {
                if ($failure->isRateLimited() || in_array($failure->status, [401, 404], true)) {
                    break;
                }
            } catch (Throwable $failure) {
                report($failure);
            }
        }

        Log::info('Telegram forwarded copies deleted', ['deleted' => $deleted, 'failed' => $total - $deleted]);
    }
}
