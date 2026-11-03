<?php

namespace App\Jobs;

use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Telegram\Bot\BotSwitch;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Stop the spinner of a pressed inline button without any other effect
 * (plan 14 v2 condition 15): tampered or out-of-state callback data, banned
 * users, limits reached. Payload: the opaque callback query id only (no
 * user, chat or text). One try: an unanswered spinner expires on its own.
 */
class AnswerBotCallback implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $callbackQueryId)
    {
        $this->onQueue('default');
    }

    public function handle(TelegramClient $client): void
    {
        if (! $client->isConfigured() || ! BotSwitch::isEnabled()) {
            return;
        }

        try {
            $client->answerCallbackQuery($this->callbackQueryId);
        } catch (TelegramException $exception) {
            Log::info('Telegram callback answer failed', ['code' => $exception->code()]);
        }
    }
}
