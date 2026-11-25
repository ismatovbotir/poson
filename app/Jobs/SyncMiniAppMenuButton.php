<?php

namespace App\Jobs;

use App\Models\TelegramUser;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Services\Telegram\WebAppButton;
use App\Support\Telegram\Bot\BotText;
use App\Support\Telegram\MiniApp\MiniAppAudience;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One customer's chat menu button (plan 20 M5 §7, MA-20, MA-23, MA-27,
 * MA-28, MA-31). Payload: the telegram_users id and a reset flag only; the
 * chat id (always the private chat telegram_users.telegram_id, never a
 * business connection), language, eligibility and URL are read when the
 * job runs.
 *
 * - In the Mini App audience (MiniAppAudience, re-checked now) and a valid
 *   canonical https URL → the web_app button (telegram_bot.menu.open_app →
 *   route tma.bootstrap on APP_URL); otherwise, or with $reset, Telegram's
 *   default button for that chat.
 * - Blocked users (blocked_at) are skipped: Telegram refuses them anyway.
 * - 403 → blocked_at, no retry; 400 / 404 → dropped; 429 → released for
 *   retry_after (does not use up retries); 5xx / timeout → backoff.
 * Queued after commit on "default". Logs: row id and error code only.
 */
class SyncMiniAppMenuButton implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $maxExceptions = 5;

    public const RETRY_HOURS = 6;

    public function __construct(public int $telegramUserId, public bool $reset = false)
    {
        $this->onQueue('default');
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(self::RETRY_HOURS);
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

        if (! $user instanceof TelegramUser || $user->blocked_at !== null || ! $client->isConfigured()) {
            return;
        }

        $button = ! $this->reset && MiniAppAudience::includes($user)
            ? WebAppButton::miniApp(__('telegram_bot.menu.open_app', [], BotText::locale($user)))
            : null;

        try {
            $client->setChatMenuButton((string) $user->telegram_id, $button);
        } catch (TelegramException $exception) {
            Log::warning('Telegram menu button sync failed', ['telegram_user_id' => $user->id, 'code' => $exception->code()]);

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
}
