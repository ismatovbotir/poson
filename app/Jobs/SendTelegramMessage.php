<?php

namespace App\Jobs;

use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Secrets;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Send one rendered alert to one team chat (plan 11b), retried like the
 * lead alerts: 429 → released for retry_after; 400/401/403/404 → failed at
 * once; 5xx / timeout → retried with backoff. Skipped quietly without a
 * bot token or chat id (local dev). Logs only the context label, chat key
 * and error code (never the token, the URL or the text).
 */
class SendTelegramMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @param  list<array{text: string, url: string}>  $buttons
     */
    public function __construct(
        public string $chat,
        public string $html,
        public array $buttons = [],
        public string $context = 'alert',
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(TelegramClient $client): void
    {
        $chatId = Secrets::get('telegram.chats.'.$this->chat);

        if (! $client->isConfigured() || ! is_string($chatId) || $chatId === '') {
            return;
        }

        try {
            $client->sendMessage($chatId, $this->html, $this->buttons);
        } catch (TelegramException $exception) {
            Log::warning('Telegram alert failed', ['context' => $this->context, 'chat' => $this->chat, 'code' => $exception->code()]);

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
}
