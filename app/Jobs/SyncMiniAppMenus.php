<?php

namespace App\Jobs;

use App\Enums\TelegramUserState;
use App\Models\TelegramUser;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Telegram\MiniApp\BotUsername;
use App\Support\Telegram\MiniApp\MiniAppMenuSync;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The menu button fan-out after a Mini App toggle (plan 20 M6, MA-36..38).
 * Payload: the generation and a cursor (telegram_users id) only.
 *
 * - Stale generation (a newer toggle bumped it) → stops, nothing queued.
 * - First step (cursor 0): getMe (stores the bot username) and the
 *   bot-wide default menu button (MA-28, never a web_app one).
 * - Each step queues SyncMiniAppMenuButton for at most CHUNK registered,
 *   unblocked conversations after the cursor, spread at PER_SECOND, then
 *   re-dispatches itself with the new cursor once those are due, so the
 *   queue never holds more than about CHUNK menu jobs and other "default"
 *   jobs (order alerts) are not stuck behind them.
 * - Per-user jobs carry no on/off flag: the button is computed from
 *   MiniAppAudience when each one runs (MA-37).
 *
 * Unique per generation until processing; queued after commit on "default".
 * A 429 releases for retry_after; logs carry the error code only.
 */
class SyncMiniAppMenus implements ShouldBeUniqueUntilProcessing, ShouldQueueAfterCommit
{
    use Queueable;

    public const CHUNK = 100;

    public const PER_SECOND = 5;

    public const RETRY_HOURS = 6;

    public int $maxExceptions = 5;

    public int $uniqueFor = 3600;

    public function __construct(public int $generation, public int $afterId = 0)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->generation;
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
        if (MiniAppMenuSync::current() !== $this->generation) {
            return;
        }

        if (! $client->isConfigured()) {
            MiniAppMenuSync::record($this->generation, ['state' => MiniAppMenuSync::FAILED, 'finished' => true]);

            return;
        }

        if ($this->afterId === 0 && ! $this->resetBotDefault($client)) {
            return;
        }

        $ids = TelegramUser::query()
            ->where('state', TelegramUserState::Registered)
            ->whereNotNull('registered_at')
            ->whereNull('blocked_at')
            ->where('id', '>', $this->afterId)
            ->orderBy('id')
            ->limit(self::CHUNK)
            ->pluck('id');

        foreach ($ids->values() as $index => $id) {
            dispatch(new SyncMiniAppMenuButton((int) $id))->delay(now()->addSeconds(intdiv($index, self::PER_SECOND)));
        }

        $more = $ids->count() === self::CHUNK;

        MiniAppMenuSync::record($this->generation, [
            'state' => $more ? MiniAppMenuSync::RUNNING : MiniAppMenuSync::DONE,
            'synced' => $ids->count(),
            'finished' => ! $more,
        ]);

        if ($more) {
            dispatch(new self($this->generation, (int) $ids->last()))->delay(now()->addSeconds(intdiv(self::CHUNK, self::PER_SECOND)));
        }
    }

    public function failed(): void
    {
        MiniAppMenuSync::record($this->generation, ['state' => MiniAppMenuSync::FAILED, 'finished' => true]);
    }

    /**
     * getMe (username, best effort) and Telegram's bot-wide default button.
     *
     * @return bool whether the fan-out may continue
     *
     * @throws TelegramException 5xx / timeout (retried with backoff)
     */
    private function resetBotDefault(TelegramClient $client): bool
    {
        MiniAppMenuSync::record($this->generation, ['state' => MiniAppMenuSync::RUNNING]);

        try {
            BotUsername::remember($client->getMe()['username'] ?? null);
        } catch (TelegramException $exception) {
            Log::warning('Telegram Mini App menu fan-out: getMe failed', ['code' => $exception->code()]);
        }

        try {
            $client->setChatMenuButton(null, null);
        } catch (TelegramException $exception) {
            Log::warning('Telegram Mini App menu fan-out: default button failed', ['code' => $exception->code()]);

            if ($exception->isRateLimited()) {
                $this->release($exception->retryAfter ?? $this->backoff()[0]);

                return false;
            }

            if ($exception->isPermanent()) {
                MiniAppMenuSync::record($this->generation, ['state' => MiniAppMenuSync::FAILED, 'finished' => true]);

                return false;
            }

            throw $exception;
        }

        return true;
    }
}
