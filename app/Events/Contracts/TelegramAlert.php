<?php

namespace App\Events\Contracts;

use App\Support\Telegram\TelegramMessage;

/**
 * An event that alerts team chats on Telegram (plan 11b): the
 * SendTelegramAlerts listener (queued, after commit) renders the message
 * in telegram.locale and queues one SendTelegramMessage job per chat.
 */
interface TelegramAlert
{
    /**
     * Chat keys of services.telegram.chats ("sales", "finance", "service").
     *
     * @return list<string>
     */
    public function telegramChats(): array;

    /**
     * The message in a locale; null when there is nothing to send any more
     * (e.g. the order was deleted before the queue ran).
     */
    public function telegramMessage(string $locale): ?TelegramMessage;
}
