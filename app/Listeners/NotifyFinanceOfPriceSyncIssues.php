<?php

namespace App\Listeners;

use App\Enums\PriceSyncStatus;
use App\Events\PriceSyncCompleted;
use App\Models\PriceSyncLog;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Secrets;
use App\Support\Telegram\PriceSyncMessage;
use App\Support\Telegram\TelegramLocale;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Price API batch with problems → Telegram finance chat (plan 09, on from
 * launch: decision 2026-10-27). Queued only when the batch had unknown or
 * invalid items or was rejected (shouldQueue()).
 *
 * - Skipped quietly when telegram.notify_price_sync is off or the bot token
 *   / finance chat is not set.
 * - De-duplicated per token and problem type (unknown / invalid /
 *   rejected): at most one alert per type every DEDUPE_HOURS hours,
 *   whatever the exact codes. The marks are claimed with Cache::add before
 *   sending and forgotten again when the send fails.
 * - Failures as in SendLeadToTelegram: 429 → released, permanent → failed,
 *   others rethrown and retried. Logs only the log id and the error code.
 */
class NotifyFinanceOfPriceSyncIssues implements ShouldQueue
{
    use InteractsWithQueue;

    public const DEDUPE_HOURS = 6;

    public int $tries = 5;

    public bool $afterCommit = true;

    public bool $deleteWhenMissingModels = true;

    public function __construct(protected TelegramClient $client) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    /**
     * Clean batches never reach the queue.
     */
    public function shouldQueue(PriceSyncCompleted $event): bool
    {
        return $event->log->hasIssues();
    }

    public function handle(PriceSyncCompleted $event): void
    {
        $log = $event->log;
        $chatId = Secrets::get('telegram.chats.finance');

        if (! $log->hasIssues() || Secrets::get('telegram.notify_price_sync') === false || ! $this->client->isConfigured() || ! is_string($chatId) || $chatId === '') {
            return;
        }

        // Claim each problem type first (atomic), so parallel jobs can't both send.
        $claimed = [];

        foreach (static::problemTypes($log) as $type) {
            $key = static::dedupeKey($log, $type);

            if (Cache::add($key, true, now()->addHours(self::DEDUPE_HOURS))) {
                $claimed[] = $key;
            }
        }

        if ($claimed === []) {
            return;
        }

        $message = PriceSyncMessage::for($log, TelegramLocale::current());

        try {
            $this->client->sendMessage($chatId, $message->html, $message->buttons);
        } catch (Throwable $exception) {
            // Not sent: release the claims so the retry (or the next batch) can alert.
            foreach ($claimed as $key) {
                Cache::forget($key);
            }

            if (! $exception instanceof TelegramException) {
                throw $exception;
            }

            Log::warning('Telegram price sync alert failed', ['price_sync_log_id' => $log->id, 'code' => $exception->code(), 'exception' => $exception::class]);

            if ($exception->isRateLimited()) {
                $this->release($exception->retryAfter ?? $this->backoff()[0]);

                return;
            }

            if ($exception->isPermanent()) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        }
    }

    /**
     * Problem types of a batch: rejected, or unknown and / or invalid items.
     *
     * @return list<string>
     */
    public static function problemTypes(PriceSyncLog $log): array
    {
        if ($log->status === PriceSyncStatus::Rejected) {
            return ['rejected'];
        }

        return array_values(array_filter([
            $log->unknown > 0 ? 'unknown' : null,
            $log->invalid > 0 ? 'invalid' : null,
        ]));
    }

    /**
     * One key per token and problem type, whatever the exact codes.
     */
    public static function dedupeKey(PriceSyncLog $log, string $type): string
    {
        return 'price-sync-alert:'.($log->api_token_id ?? 'none').':'.$type;
    }
}
