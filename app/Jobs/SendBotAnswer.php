<?php

namespace App\Jobs;

use App\Models\TelegramUser;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Chat\Adapters\TelegramAdapter;
use App\Support\Chat\ChatbotSwitch;
use App\Support\Telegram\Bot\BotSwitch;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One rule-bot answer to one customer (plan 28, CB-4, CB-5). Not stored as a
 * conversation message: bot answers are UI, like SendBotPrompt.
 *
 * Payload: the telegram_users id, the plain answer text (public rule content,
 * at most TelegramAdapter::MAX_TEXT_UNITS characters), the inline keyboard
 * rows built by TelegramAdapter, and the callback query to answer. The chat id
 * is read from the row when the job runs.
 *
 * The text is escaped with e() and sent with parse_mode HTML, so markup typed
 * by an admin shows as text and nothing is ever Markdown. After commit; claimed
 * before sending (an atomic Cache::add on a per-job token, like TG-25), so two
 * runs never both send. A failure that can be retried frees the claim first;
 * a permanent one (400/403/404) keeps it. A worker killed mid-send leaves the
 * claim, so the answer is never sent twice. Skipped when the user is gone or
 * banned, or the bot or the chat bot is off. Logs: row id and error code only.
 */
class SendBotAnswer implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $maxExceptions = 5;

    public const RETRY_HOURS = 1;

    public const CLAIM_HOURS = 6;

    public string $claimToken;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(self::RETRY_HOURS);
    }

    /**
     * @param  list<list<array{text: string, callback_data?: string, url?: string}>>  $inline
     */
    public function __construct(
        public int $telegramUserId,
        public string $text,
        public array $inline = [],
        public ?string $callbackQueryId = null,
    ) {
        $this->claimToken = (string) Str::uuid();
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
        // Stop the spinner first, whatever happens next (first try only; best effort).
        if ($this->attempts() <= 1 && $this->callbackQueryId !== null && $client->isConfigured()) {
            try {
                $client->answerCallbackQuery($this->callbackQueryId);
            } catch (TelegramException $exception) {
                Log::info('Telegram chat callback answer failed', ['telegram_user_id' => $this->telegramUserId, 'code' => $exception->code()]);
            }
        }

        $user = TelegramUser::query()->find($this->telegramUserId);

        if (! $user instanceof TelegramUser || $user->isBanned() || ! $client->isConfigured() || ! BotSwitch::isEnabled() || ! ChatbotSwitch::isEnabled()) {
            return;
        }

        if ($this->text === '') {
            return;
        }

        if (! Cache::add($this->claimKey(), true, now()->addHours(self::CLAIM_HOURS))) {
            return;
        }

        try {
            $client->sendMessage((string) $user->telegram_id, e($this->text), [], TelegramAdapter::markup($this->inline), parseMode: 'HTML');
        } catch (TelegramException $exception) {
            Log::warning('Telegram chat answer failed', ['telegram_user_id' => $user->id, 'code' => $exception->code()]);

            if ($exception->isPermanent()) {
                if ($exception->status === 403) {
                    $user->forceFill(['blocked_at' => now()])->save();
                }

                return;
            }

            Cache::forget($this->claimKey());

            if ($exception->isRateLimited()) {
                $this->release($exception->retryAfter ?? $this->backoff()[0]);

                return;
            }

            throw $exception;
        }
    }

    private function claimKey(): string
    {
        return 'chatbot-answer-claim:'.$this->claimToken;
    }
}
