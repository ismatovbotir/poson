<?php

namespace App\Listeners;

use App\Events\Contracts\TelegramAlert;
use App\Jobs\SendTelegramMessage;
use App\Support\Telegram\TelegramLocale;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Order and payment events → Telegram (plan 11b, security conditions 13,
 * 21, 22, 24): queued after the transaction commits, renders the event's
 * message in telegram.locale and queues one SendTelegramMessage per chat
 * (so a failing chat never re-sends to the other).
 */
class SendTelegramAlerts implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    public bool $deleteWhenMissingModels = true;

    public function handle(TelegramAlert $event): void
    {
        $message = $event->telegramMessage(TelegramLocale::current());

        if ($message === null) {
            return;
        }

        foreach (array_values(array_unique($event->telegramChats())) as $chat) {
            SendTelegramMessage::dispatch($chat, $message->html, $message->buttons, class_basename($event));
        }
    }
}
