<?php

namespace App\Jobs;

use App\Models\TelegramUser;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Telegram\Bot\BotKeyboards;
use App\Support\Telegram\Bot\BotSwitch;
use App\Support\Telegram\Bot\BotText;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One bot UI message (registration step, menu, help, notices) to one
 * customer (plan 14 v2 §5, condition 9). Prompts are UI, not conversation,
 * so they are NOT stored as telegram_messages rows; real conversation
 * messages (staff replies, auto-ack) go through DeliverBotMessage.
 *
 * Payload: the telegram_users id, prompt keys (BotText::PROMPTS), a keyboard
 * name (BotKeyboards) and optionally the callback query to answer and the
 * message whose inline buttons to clear. No text, phone or chat id: the
 * chat id, language and name are read from the row when the job runs.
 *
 * Plain text, no parse_mode (condition 15). Retries like DeliverBotMessage:
 * 429 → released for retry_after; 403 → telegram_users.blocked_at, no
 * retry; 400 / 404 → dropped; 5xx / timeout → backoff 10/60/300/900.
 * Skipped when the row is gone, banned, or the kill switch is off.
 * Answering the callback and clearing old buttons are best effort.
 * Logs: row id and error code only.
 */
class SendBotPrompt implements ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * 429 releases must not use up the retries: the job may be retried for
     * RETRY_HOURS, while real failures (5xx / timeout, thrown) are capped by
     * $maxExceptions (first try + the four backoff steps).
     */
    public int $maxExceptions = 5;

    public const RETRY_HOURS = 1;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(self::RETRY_HOURS);
    }

    /**
     * @param  list<string>  $prompts  BotText::PROMPTS keys, sent as one message (empty: nothing sent)
     * @param  string|null  $keyboard  BotKeyboards constant
     */
    public function __construct(
        public int $telegramUserId,
        public array $prompts = [],
        public ?string $keyboard = null,
        public ?string $callbackQueryId = null,
        public ?int $clearMarkupMessageId = null,
    ) {
        $this->onQueue('default');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(TelegramClient $client): void
    {
        $user = TelegramUser::query()->find($this->telegramUserId);

        if (! $user instanceof TelegramUser || $user->isBanned() || ! $client->isConfigured() || ! BotSwitch::isEnabled()) {
            return;
        }

        $chatId = (string) $user->telegram_id;
        if ($this->callbackQueryId !== null) {
            $this->bestEffort(fn () => $client->answerCallbackQuery((string) $this->callbackQueryId));
        }

        if ($this->clearMarkupMessageId !== null) {
            $this->bestEffort(fn () => $client->editMessageReplyMarkup($chatId, (int) $this->clearMarkupMessageId));
        }

        $text = BotText::render($user, $this->prompts);

        if ($text === '') {
            return;
        }

        try {
            $client->sendMessage($chatId, $text, [], BotKeyboards::markup($this->keyboard, BotText::locale($user)), parseMode: null);
        } catch (TelegramException $exception) {
            Log::warning('Telegram bot prompt failed', ['telegram_user_id' => $user->id, 'code' => $exception->code()]);

            if ($exception->isRateLimited()) {
                $this->release($exception->retryAfter ?? $this->backoff()[0]);

                return;
            }

            if ($exception->status === 403) {
                $user->forceFill(['blocked_at' => now()])->save();
            }

            if ($exception->isPermanent()) {
                return;
            }

            throw $exception;
        }
    }

    /**
     * Callback answer and button clean-up: failures (expired query, message
     * already edited) never block the prompt itself.
     */
    private function bestEffort(callable $calls): void
    {
        try {
            $calls();
        } catch (TelegramException $exception) {
            Log::info('Telegram bot callback cleanup failed', ['telegram_user_id' => $this->telegramUserId, 'code' => $exception->code()]);
        }
    }
}
