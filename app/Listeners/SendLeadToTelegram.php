<?php

namespace App\Listeners;

use App\Enums\ActivityType;
use App\Events\LeadCreated;
use App\Models\Lead;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Secrets;
use App\Support\Telegram\LeadMessage;
use App\Support\Telegram\TelegramLocale;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * New lead → Telegram (plan 05c). Queued after the lead's transaction
 * commits. Routing by type: service_request → service chat, everything
 * else → sales chat. Message: LeadMessage in telegram.locale.
 *
 * - Skipped silently when telegram.notify_new_leads is off or the token /
 *   chat is not set (local dev).
 * - 429 → released for Telegram's retry_after; 400/401/403/404 → failed
 *   at once (retrying can't help); 5xx / timeout / connection → rethrown,
 *   retried with BACKOFF up to $tries.
 * - A "notification" activity on the lead: {result: sent, chat} on
 *   success, {result: failed, chat, code} in failed().
 * - Logs only the lead id, chat, code and exception class (never the
 *   token, the URL or personal data).
 */
class SendLeadToTelegram implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 5;

    public bool $afterCommit = true;

    /**
     * A lead deleted before the job runs: drop the job quietly.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(protected TelegramClient $client) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(LeadCreated $event): void
    {
        $chat = static::chatFor($event->lead);
        $chatId = Secrets::get('telegram.chats.'.$chat);

        if (Secrets::get('telegram.notify_new_leads') === false || ! $this->client->isConfigured() || ! is_string($chatId) || $chatId === '') {
            return;
        }

        $lead = Lead::query()->with(['items', 'customer:id,name'])->find($event->lead->id);

        if (! $lead instanceof Lead) {
            return;
        }

        $message = LeadMessage::for($lead, TelegramLocale::current());

        try {
            $this->client->sendMessage($chatId, $message->html, $message->buttons);
        } catch (TelegramException $exception) {
            Log::warning('Telegram lead alert failed', ['lead_id' => $lead->id, 'chat' => $chat, 'code' => $exception->code(), 'exception' => $exception::class]);

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

        // The message is out: a failing history write must not make the queue send it again.
        try {
            $lead->activities()->create([
                'type' => ActivityType::Notification,
                'meta' => ['result' => 'sent', 'chat' => $chat],
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Out of attempts, or a permanent error: record it on the lead.
     */
    public function failed(LeadCreated $event, ?Throwable $exception): void
    {
        $code = $exception instanceof TelegramException ? $exception->code() : 'error';

        Log::warning('Telegram lead alert gave up', ['lead_id' => $event->lead->id, 'code' => $code, 'exception' => $exception !== null ? $exception::class : null]);

        if (! Lead::query()->whereKey($event->lead->id)->exists()) {
            return;
        }

        $event->lead->activities()->create([
            'type' => ActivityType::Notification,
            'meta' => ['result' => 'failed', 'chat' => static::chatFor($event->lead), 'code' => $code],
            'created_at' => now(),
        ]);
    }

    /**
     * service_request → "service", every other type → "sales".
     */
    public static function chatFor(Lead $lead): string
    {
        return $lead->type->isService() ? 'service' : 'sales';
    }
}
