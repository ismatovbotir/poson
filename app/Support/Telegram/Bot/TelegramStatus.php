<?php

namespace App\Support\Telegram\Bot;

use App\Actions\Telegram\CheckTelegramWebhook;
use App\Events\Telegram\TelegramInboxMessage;
use App\Jobs\DeliverBotMessage;
use App\Jobs\ForwardTelegramMedia;
use App\Jobs\SendTelegramMessage;
use App\Listeners\SendLeadToTelegram;
use App\Listeners\SendTelegramAlerts;
use App\Listeners\SendTelegramInboxAlert;
use App\Models\MonitoringState;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Monitoring\FailedJobs;
use App\Support\Monitoring\Heartbeat;
use App\Support\Monitoring\MonitoringAlerts;
use App\Support\Monitoring\ProblemMonitor;
use App\Support\Monitoring\QueueStats;
use App\Support\Secrets;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Read model of the Telegram status panel (plan 21 E2, T21-7). Everything
 * comes from the database, config and the `telegram:status` snapshot that
 * CheckTelegramWebhook writes every 15 minutes: rendering and polling never
 * call Telegram. Only refresh() ("check now") does, rate-limited and under a
 * global lock.
 *
 * rows(): one entry per check, {id, state, params, items, next, link}. State
 * is ok, problem, unknown (no fresh data) or neutral (the bot switch is off
 * and the row only matters once it is on). Params are raw scalars (counts,
 * minutes, chat key name), never a token, URL, chat id, title, username,
 * error text or customer data; the view translates them. A snapshot older
 * than SNAPSHOT_FRESH_SECONDS counts as no data.
 *
 * The whole result is cached CACHE_SECONDS (the poll costs no queries
 * between refreshes, including the failed-jobs scan); refresh() drops it.
 */
final class TelegramStatus
{
    public const CACHE_KEY = 'telegram-status:result';

    public const CACHE_SECONDS = 55;

    public const SNAPSHOT_FRESH_SECONDS = 1800;

    public const CHECK_COOLDOWN_SECONDS = 20;

    public const LOCK_KEY = 'telegram-status:check';

    public const LOCK_SECONDS = 30;

    public const TIMEOUT = 3;

    public const REFRESHED = 'refreshed';

    public const LIMITED = 'limited';

    public const BUSY = 'busy';

    public const FAILED = 'failed';

    public const OK = 'ok';

    public const PROBLEM = 'problem';

    public const UNKNOWN = 'unknown';

    public const NEUTRAL = 'neutral';

    /**
     * Failed jobs of these classes count as Telegram failures (fixed list).
     *
     * @var list<class-string>
     */
    public const FAILED_JOB_CLASSES = [
        SendTelegramMessage::class,
        SendTelegramAlerts::class,
        SendTelegramInboxAlert::class,
        SendLeadToTelegram::class,
        ForwardTelegramMedia::class,
        DeliverBotMessage::class,
    ];

    /**
     * @return array{rows: list<array{id: string, state: string, params: array<string, scalar|null>, items: list<array{env: string, reason: string}>, next: string|null, link: string|null}>, counts: array<string, int|string|null>, checked_at: string|null}
     */
    public function result(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => $this->compute());
    }

    /**
     * Problem rows for the bot page summary line.
     *
     * @return array{problems: int, unknown: int}
     */
    public function summary(): array
    {
        $states = array_column($this->result()['rows'], 'state');

        return [
            'problems' => count(array_filter($states, fn (string $state): bool => $state === self::PROBLEM)),
            'unknown' => count(array_filter($states, fn (string $state): bool => $state === self::UNKNOWN)),
        ];
    }

    /**
     * "Check now": the webhook check (writes the snapshot) plus the inbox
     * chat verdict, 3 s timeouts. About 3 per minute per user (one per
     * CHECK_COOLDOWN_SECONDS, atomic hit) and one run at a time across all
     * users (non-blocking lock, released in finally). Every failure leaves
     * the affected rows unknown.
     *
     * @return self::REFRESHED|self::LIMITED|self::BUSY|self::FAILED
     */
    public function refresh(int|string|null $userId): string
    {
        $limiterKey = 'telegram-status-check:'.$userId;

        if (RateLimiter::hit($limiterKey, self::CHECK_COOLDOWN_SECONDS) > 1) {
            return self::LIMITED;
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return self::BUSY;
        }

        try {
            app(CheckTelegramWebhook::class)->handle(self::TIMEOUT);
            $this->verifyChat();
            Cache::forget(self::CACHE_KEY);

            return self::REFRESHED;
        } catch (Throwable $exception) {
            report($exception);
            Cache::forget(self::CACHE_KEY);

            return self::FAILED;
        } finally {
            $lock->release();
        }
    }

    public static function retryAfter(int|string|null $userId): int
    {
        return RateLimiter::availableIn('telegram-status-check:'.$userId);
    }

    /**
     * Put the inbox chat verdict (pass / fail / unknown) into the snapshot.
     * A 429, 5xx or timeout is unknown, never fail.
     */
    private function verifyChat(): void
    {
        $chatId = InboxChat::id();
        $verdict = 'unknown';

        if ($chatId !== null && app(TelegramClient::class)->isConfigured()) {
            try {
                $verdict = InboxChat::verify(app(TelegramClient::class)->withTimeout(self::TIMEOUT, self::TIMEOUT), $chatId) ? 'pass' : 'fail';
            } catch (TelegramException) {
                $verdict = 'unknown';
            }
        }

        $state = MonitoringState::query()->find(CheckTelegramWebhook::SNAPSHOT_KEY);

        if ($state !== null) {
            $state->forceFill(['meta' => [...($state->meta ?? []), 'chat' => $verdict, 'chat_key' => InboxChat::key()]])->save();
        }
    }

    /**
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int|string|null>, checked_at: string|null}
     */
    private function compute(): array
    {
        $enabled = BotSwitch::isEnabled();
        $snapshot = $this->snapshot();
        $problems = CheckTelegramWebhook::openProblems();
        $tokenSet = app(TelegramClient::class)->isConfigured();
        $local = app(BotChecklist::class)->local();
        $databaseQueue = config('queue.default') === 'database';

        $rows = [
            $this->botSwitch($enabled),
            $this->token($enabled, $tokenSet, $snapshot, $problems),
            $this->webhook($enabled, $tokenSet, $snapshot, $problems),
            $this->chat($enabled, $snapshot),
            $this->managementChat(),
            $this->privacy($enabled, (bool) $local['privacy']),
            $this->worker($databaseQueue),
            $this->cron(),
            $this->oldestJob($databaseQueue),
            $this->failedJobs(),
            $this->invalidEnv(),
            $this->inboxListener(),
            $this->queueConnection(),
        ];

        return ['rows' => $rows, 'counts' => $this->counts(), 'checked_at' => $snapshot['checked_at'] ?? null];
    }

    /**
     * The fresh snapshot (an empty array when missing or older than 30 minutes).
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $meta = MonitoringState::query()->find(CheckTelegramWebhook::SNAPSHOT_KEY)?->meta;

        if (! is_array($meta) || ! is_string($meta['checked_at'] ?? null)) {
            return [];
        }

        try {
            $fresh = Carbon::parse($meta['checked_at'])->gt(now()->subSeconds(self::SNAPSHOT_FRESH_SECONDS));
        } catch (Throwable) {
            return [];
        }

        return $fresh ? $meta : [];
    }

    /**
     * @param  array<string, scalar|null>  $params
     * @param  list<array{env: string, reason: string}>  $items
     * @return array{id: string, state: string, params: array<string, scalar|null>, items: list<array{env: string, reason: string}>, next: string|null, link: string|null}
     */
    private function row(string $id, string $state, array $params = [], ?string $link = null, array $items = []): array
    {
        $next = match ($state) {
            self::PROBLEM => 'telegram_status.rows.'.$id.'.next',
            self::UNKNOWN => 'telegram_status.next_unknown',
            default => null,
        };

        return ['id' => $id, 'state' => $state, 'params' => $params, 'items' => $items, 'next' => $next, 'link' => $link];
    }

    /**
     * @return array<string, mixed>
     */
    private function botSwitch(bool $enabled): array
    {
        return $this->row('bot_switch', $enabled ? self::OK : self::NEUTRAL, link: 'bot');
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $problems
     * @return array<string, mixed>
     */
    private function token(bool $enabled, bool $tokenSet, array $snapshot, array $problems): array
    {
        if (! $tokenSet) {
            return $this->row('token', $enabled ? self::PROBLEM : self::NEUTRAL, ['missing' => true], 'settings');
        }

        $state = match (true) {
            isset($problems['telegram_token']), ($snapshot['get_me'] ?? null) === 'fail' => self::PROBLEM,
            ($snapshot['get_me'] ?? null) === 'ok' => self::OK,
            default => self::UNKNOWN,
        };

        return $this->row('token', $state, link: 'settings');
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $problems
     * @return array<string, mixed>
     */
    private function webhook(bool $enabled, bool $tokenSet, array $snapshot, array $problems): array
    {
        $pending = is_int($snapshot['pending'] ?? null) ? $snapshot['pending'] : null;
        $minutes = $this->minutesSince($snapshot['last_error_at'] ?? null);
        $params = ['pending' => $pending, 'error_minutes' => $minutes];

        if (! $tokenSet) {
            return $this->row('webhook', self::NEUTRAL, $params, 'bot');
        }

        if (isset($problems['telegram_webhook'])) {
            return $this->row('webhook', self::PROBLEM, $params, 'bot');
        }

        $match = $snapshot['webhook_match'] ?? null;

        if (! is_bool($match)) {
            return $this->row('webhook', self::UNKNOWN, $params, 'bot');
        }

        if (! $enabled) {
            return $this->row('webhook', self::NEUTRAL, $params, 'bot');
        }

        $bad = ! $match
            || ($pending !== null && $pending > CheckTelegramWebhook::PENDING_LIMIT)
            || ($minutes !== null && $minutes * 60 < CheckTelegramWebhook::ERROR_WINDOW);

        return $this->row('webhook', $bad ? self::PROBLEM : self::OK, $params, 'bot');
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function chat(bool $enabled, array $snapshot): array
    {
        $key = InboxChat::key();

        if ($key === null || InboxChat::id() === null) {
            return $this->row('chat', $enabled ? self::PROBLEM : self::NEUTRAL, ['chat' => $key, 'invalid' => $key !== null], 'settings');
        }

        $state = match ($snapshot['chat'] ?? null) {
            'pass' => self::OK,
            'fail' => self::PROBLEM,
            default => self::UNKNOWN,
        };

        return $this->row('chat', $state, ['chat' => $key], 'settings');
    }

    /**
     * @return array<string, mixed>
     */
    private function managementChat(): array
    {
        return $this->row('management_chat', MonitoringAlerts::isConfigured() ? self::OK : self::PROBLEM, link: 'settings');
    }

    /**
     * @return array<string, mixed>
     */
    private function privacy(bool $enabled, bool $pageOk): array
    {
        return $this->row('privacy', $pageOk ? self::OK : ($enabled ? self::PROBLEM : self::NEUTRAL));
    }

    /**
     * @return array<string, mixed>
     */
    private function worker(bool $databaseQueue): array
    {
        if (! $databaseQueue || $this->cronIsDead()) {
            return $this->row('worker', self::UNKNOWN, ['database' => $databaseQueue], 'monitoring');
        }

        $age = Heartbeat::age(Heartbeat::WORKER);

        if ($age === null) {
            return $this->row('worker', self::UNKNOWN, ['database' => true], 'monitoring');
        }

        return $this->row('worker', $age > ProblemMonitor::WORKER_SILENT_AFTER ? self::PROBLEM : self::OK, ['minutes' => (int) ceil($age / 60), 'database' => true], 'monitoring');
    }

    /**
     * @return array<string, mixed>
     */
    private function cron(): array
    {
        $age = Heartbeat::age(Heartbeat::SCHEDULER);

        if ($age === null) {
            return $this->row('cron', self::UNKNOWN, link: 'monitoring');
        }

        return $this->row('cron', $age > ProblemMonitor::CRON_DEAD_AFTER ? self::PROBLEM : self::OK, ['minutes' => (int) ceil($age / 60)], 'monitoring');
    }

    private function cronIsDead(): bool
    {
        $age = Heartbeat::age(Heartbeat::SCHEDULER);

        return $age !== null && $age > ProblemMonitor::CRON_DEAD_AFTER;
    }

    /**
     * @return array<string, mixed>
     */
    private function oldestJob(bool $databaseQueue): array
    {
        if (! $databaseQueue) {
            return $this->row('oldest_job', self::UNKNOWN, ['database' => false], 'monitoring');
        }

        $stats = QueueStats::snapshot();
        $stuck = $stats['oldest_age'] !== null && $stats['oldest_age'] >= ProblemMonitor::QUEUE_STUCK_AFTER;

        return $this->row('oldest_job', $stuck ? self::PROBLEM : self::OK, ['minutes' => $stats['oldest_age'] === null ? null : (int) floor($stats['oldest_age'] / 60), 'pending' => $stats['pending'], 'database' => true], 'monitoring');
    }

    /**
     * @return array<string, mixed>
     */
    private function failedJobs(): array
    {
        $count = FailedJobs::countForClasses(self::FAILED_JOB_CLASSES);

        return $this->row('failed_jobs', $count > 0 ? self::PROBLEM : self::OK, ['count' => $count], 'monitoring');
    }

    /**
     * @return array<string, mixed>
     */
    private function invalidEnv(): array
    {
        $items = Secrets::invalidEnv('telegram');

        if ($items === []) {
            return $this->row('invalid_env', self::OK);
        }

        $onlyUnknown = collect($items)->every(fn (array $item): bool => $item['reason'] === 'unknown');

        return $this->row('invalid_env', $onlyUnknown ? self::UNKNOWN : self::PROBLEM, ['count' => count($items)], items: array_map(fn (array $item): array => ['env' => $item['env'], 'reason' => $item['reason']], $items));
    }

    /**
     * The inbox alert listener as the web process sees it (a worker started
     * from an older deploy is not visible here).
     *
     * @return array<string, mixed>
     */
    private function inboxListener(): array
    {
        $listeners = Event::getRawListeners()[TelegramInboxMessage::class] ?? [];

        $registered = collect($listeners)->contains(fn (mixed $listener): bool => match (true) {
            is_string($listener) => str_starts_with($listener, SendTelegramInboxAlert::class.'@') || $listener === SendTelegramInboxAlert::class,
            is_array($listener) => ($listener[0] ?? null) === SendTelegramInboxAlert::class,
            default => false,
        });

        return $this->row('inbox_listener', $registered ? self::OK : self::PROBLEM);
    }

    /**
     * @return array<string, mixed>
     */
    private function queueConnection(): array
    {
        return $this->row('queue_connection', config('queue.default') === 'sync' ? self::PROBLEM : self::OK, ['connection' => (string) config('queue.default')]);
    }

    /**
     * Aggregates only: no names, phones or texts.
     *
     * @return array<string, int|string|null>
     */
    private function counts(): array
    {
        $users = TelegramUser::query()->toBase()
            ->selectRaw('sum(case when state = ? then 1 else 0 end) as registered', ['registered'])
            ->selectRaw('sum(case when state <> ? then 1 else 0 end) as registering', ['registered'])
            ->selectRaw('coalesce(sum(unread_count), 0) as unread')
            ->first();

        $messages = TelegramMessage::query()->toBase()
            ->where('direction', 'in')
            ->selectRaw('sum(case when created_at >= ? then 1 else 0 end) as today', [now()->startOfDay()])
            ->selectRaw('max(created_at) as last_at')
            ->first();

        return [
            'registered' => (int) ($users->registered ?? 0),
            'registering' => (int) ($users->registering ?? 0),
            'today' => (int) ($messages->today ?? 0),
            'last_at' => $messages->last_at ?? null,
            'stuck' => TelegramMessage::query()->stuck()->count(),
            'failed_forwards' => TelegramMessage::query()->where('forward_status', 'failed')->count(),
            'unread' => (int) ($users->unread ?? 0),
        ];
    }

    private function minutesSince(mixed $iso): ?int
    {
        if (! is_string($iso)) {
            return null;
        }

        try {
            return max(0, (int) Carbon::parse($iso)->diffInMinutes(now(), true));
        } catch (Throwable) {
            return null;
        }
    }
}
