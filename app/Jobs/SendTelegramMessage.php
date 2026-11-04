<?php

namespace App\Jobs;

use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Secrets;
use App\Support\Telegram\Bot\BotSwitch;
use App\Support\Telegram\Bot\ForwardAlerts;
use App\Support\Telegram\Bot\InboxChat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Send one rendered alert to one team chat (plan 11b), retried like the
 * lead alerts: 429 → released for retry_after; 400/401/403/404 → failed at
 * once; 5xx / timeout → retried with backoff. Skipped quietly without a
 * bot token or chat id (local dev). Logs only the context label, chat key
 * and error code (never the token, the URL or the text).
 *
 * Inbox alerts ($inbox = true, plan 21 A6/L3) ignore the chat key they were
 * queued with: at send time the target is resolved again (InboxChat::key / id)
 * and verified (getChat, cached), so a target switch during the queue wait is
 * honoured and nothing goes to an unverified chat. A refused target sends
 * nothing and raises the deduped refused_target alert; a transient or token
 * failure of the check is handled like a send failure.
 */
class SendTelegramMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * A normal property with a default (not promoted): payloads queued before
     * this field existed unserialize without it and must still run (T21-30).
     */
    public bool $inbox = false;

    /**
     * @param  list<array{text: string, url: string}>  $buttons
     */
    public function __construct(
        public string $chat,
        public string $html,
        public array $buttons = [],
        public string $context = 'alert',
        bool $inbox = false,
    ) {
        $this->inbox = $inbox;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(TelegramClient $client): void
    {
        if ($this->inbox && (! BotSwitch::isEnabled() || Secrets::get('telegram.notify_inbox') === false)) {
            return;
        }

        $chatId = $this->inbox ? InboxChat::id() : Secrets::get('telegram.chats.'.$this->chat);

        if (! $client->isConfigured() || ! is_string($chatId) || $chatId === '') {
            if ($this->inbox && $client->isConfigured() && InboxChat::key() !== null) {
                ForwardAlerts::once('refused_target', 3600);
            }

            return;
        }

        try {
            if ($this->inbox && ! InboxChat::verify($client, $chatId)) {
                ForwardAlerts::once('refused_target', 3600);

                return;
            }

            $client->sendMessage($chatId, $this->html, $this->buttons);
        } catch (TelegramException $exception) {
            if ($this->inbox && in_array($exception->status, [400, 403], true)) {
                InboxChat::invalidate($chatId);
            }

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
