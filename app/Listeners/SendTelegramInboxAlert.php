<?php

namespace App\Listeners;

use App\Events\Telegram\TelegramInboxMessage;
use App\Jobs\SendTelegramMessage;
use App\Support\Telegram\TelegramLocale;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Customer inbox text alert (plan 14 v2 §5, B4-S*). Unlike SendTelegramAlerts
 * it retries: the target check (getChat) can fail transiently, and nothing has
 * been sent by then. It queues a single SendTelegramMessage, so a retry never
 * repeats a send. Registered by event discovery on handle()'s type; the
 * generic SendTelegramAlerts skips this event (shouldQueue).
 */
class SendTelegramInboxAlert implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    public bool $deleteWhenMissingModels = true;

    /** Retries fit inside the alert window (ALERT_MINUTES = 10): 30 + 120 + 300 = 450 s. */
    public int $tries = 4;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function failed(TelegramInboxMessage $event, Throwable $exception): void
    {
        $event->releaseAlertSlot();
    }

    public function handle(TelegramInboxMessage $event): void
    {
        $message = $event->telegramMessage(TelegramLocale::current());

        if ($message === null) {
            return;
        }

        foreach (array_values(array_unique($event->telegramChats())) as $chat) {
            SendTelegramMessage::dispatch($chat, $message->html, $message->buttons, class_basename($event), inbox: true);
        }
    }
}
