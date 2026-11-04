<?php

namespace App\Support\Telegram\Bot;

use App\Actions\Telegram\CheckTelegramWebhook;
use App\Events\Telegram\TelegramInboxMessage;
use App\Jobs\AnswerBotCallback;
use App\Jobs\DeliverBotMessage;
use App\Jobs\ForwardTelegramMedia;
use App\Jobs\SendBotPrompt;
use App\Jobs\SendTelegramMessage;
use App\Listeners\NotifyFinanceOfPriceSyncIssues;
use App\Listeners\SendLeadToTelegram;
use App\Listeners\SendTelegramAlerts;
use App\Listeners\SendTelegramInboxAlert;
use App\Models\MonitoringState;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Services\Telegram\TelegramClient;
use App\Support\Monitoring\FailedJobs;
use App\Support\Monitoring\Heartbeat;
use App\Support\Monitoring\MonitoringAlerts;
use App\Support\Monitoring\ProblemMonitor;
use App\Support\Monitoring\QueueStats;
use App\Support\Secrets;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
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
    /**
     * Memo of cronState() within one compute().
     *
     * @var array{dead: bool, minutes: int|null}|null
     */
    private ?array $cron = null;

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

    public const UNREACHABLE = 'unreachable';

    public const EPOCH_KEY = 'telegram-status:epoch';

    public const FAILED_JOB_DAYS = 7;

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
        AnswerBotCallback::class,
        SendBotPrompt::class,
        NotifyFinanceOfPriceSyncIssues::class,
    ];

    /**
     * @return array{rows: list<array{id: string, state: string, params: array<string, scalar|null>, items: list<array{env: string, reason: string}>, next: string|null, link: string|null}>, counts: array<string, int|string|null>, checked_at: string|null}
     */
    public function result(): array
    {
        try {
            $cached = Cache::get(self::CACHE_KEY);

            if (is_array($cached)) {
                return $cached;
            }

            $epoch = Cache::get(self::EPOCH_KEY, 0);
            $result = $this->compute();

            // A "check now" that finished while this was computing bumped the epoch: do not store the older result.
            if (Cache::get(self::EPOCH_KEY, 0) === $epoch) {
                Cache::put(self::CACHE_KEY, $result, self::CACHE_SECONDS);
            }

            return $result;
        } catch (Throwable $exception) {
            report($exception);

            return $this->compute();
        }
    }

    /**
     * Where each row's link points; null when the route does not exist
     * (the settings page moves with plan 21 stream C). The view still checks
     * the ability with @can.
     *
     * @return array<string, array{route: string|null, can: string}>
     */
    public static function links(): array
    {
        $settings = Route::has('admin.telegram.settings') ? 'admin.telegram.settings' : (Route::has('admin.settings.integrations') ? 'admin.settings.integrations' : null);

        return [
            'bot' => ['route' => 'admin.telegram.bot', 'can' => 'telegram.bot'],
            'settings' => ['route' => $settings, 'can' => 'settings.integrations'],
            'monitoring' => ['route' => Route::has('admin.monitoring') ? 'admin.monitoring' : null, 'can' => 'monitoring.view'],
        ];
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
     * "Check now": the webhook check and the inbox chat verdict (one snapshot
     * write inside CheckTelegramWebhook), 3 s timeouts, the cached chat
     * verdict dropped first. One run at a time across all users (non-blocking
     * lock, released in finally); a busy lock does not use up the user's
     * allowance. About 3 per minute per user (one per CHECK_COOLDOWN_SECONDS,
     * atomic hit). Cache or Telegram failures never throw: the rows show
     * unknown and the caller shows an error.
     *
     * @return self::REFRESHED|self::LIMITED|self::BUSY|self::FAILED|self::UNREACHABLE
     */
    public function refresh(int|string|null $userId): string
    {
        $lock = null;
        $acquired = false;

        try {
            $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);
            $acquired = (bool) $lock->get();

            if (! $acquired) {
                return self::BUSY;
            }

            if (RateLimiter::hit('telegram-status-check:'.$userId, self::CHECK_COOLDOWN_SECONDS) > 1) {
                return self::LIMITED;
            }

            $chatId = InboxChat::id();

            if ($chatId !== null) {
                InboxChat::invalidate($chatId);
            }

            $result = app(CheckTelegramWebhook::class)->handle(self::TIMEOUT);

            // Drop the old result, bump the epoch so a poll that started earlier cannot store its older one, then store the new one.
            Cache::forget(self::CACHE_KEY);
            Cache::put(self::EPOCH_KEY, Str::random(12), 3600);
            Cache::put(self::CACHE_KEY, $this->compute(), self::CACHE_SECONDS);

            return $result === CheckTelegramWebhook::UNREACHABLE ? self::UNREACHABLE : self::REFRESHED;
        } catch (Throwable $exception) {
            report($exception);

            return self::FAILED;
        } finally {
            if ($acquired) {
                try {
                    $lock?->release();
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }
    }

    public static function retryAfter(int|string|null $userId): int
    {
        try {
            return RateLimiter::availableIn('telegram-status-check:'.$userId);
        } catch (Throwable) {
            return self::CHECK_COOLDOWN_SECONDS;
        }
    }

    /**
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int|string|null>|null, checked_at: string|null}
     */
    private function compute(): array
    {
        $this->cron = null;
        $snapshot = $this->guarded(fn (): array => $this->snapshot()) ?? [];
        $enabled = (bool) $this->guarded(fn (): bool => BotSwitch::isEnabled());
        $problems = $this->guarded(fn (): array => CheckTelegramWebhook::openProblems()) ?? [];
        $tokenSet = (bool) $this->guarded(fn (): bool => app(TelegramClient::class)->isConfigured());
        $databaseQueue = config('queue.default') === 'database';

        $rows = [
            $this->guardedRow('bot_switch', fn (): array => $this->botSwitch($enabled)),
            $this->guardedRow('token', fn (): array => $this->token($enabled, $tokenSet, $snapshot, $problems)),
            $this->guardedRow('webhook', fn (): array => $this->webhook($enabled, $tokenSet, $snapshot, $problems)),
            $this->guardedRow('chat', fn (): array => $this->chat($enabled, $snapshot)),
            $this->guardedRow('management_chat', fn (): array => $this->managementChat($enabled)),
            $this->guardedRow('privacy', fn (): array => $this->privacy($enabled, (bool) app(BotChecklist::class)->local()['privacy'])),
            $this->guardedRow('worker', fn (): array => $this->worker($databaseQueue)),
            $this->guardedRow('cron', fn (): array => $this->cron()),
            $this->guardedRow('oldest_job', fn (): array => $this->oldestJob($databaseQueue)),
            $this->guardedRow('failed_jobs', fn (): array => $this->failedJobs()),
            $this->guardedRow('invalid_env', fn (): array => $this->invalidEnv()),
            $this->guardedRow('inbox_listener', fn (): array => $this->inboxListener()),
            $this->guardedRow('queue_connection', fn (): array => $this->queueConnection()),
        ];

        return ['rows' => $rows, 'counts' => $this->guarded(fn (): array => $this->counts()), 'checked_at' => $snapshot['checked_at'] ?? null];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    private function guarded(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * One failing row becomes unknown; the panel still renders.
     *
     * @param  callable(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function guardedRow(string $id, callable $callback): array
    {
        return $this->guarded($callback) ?? $this->row($id, self::UNKNOWN);
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
     * text: the message key suffix (the state, or a more specific variant).
     * next: a translation key for problem and unknown rows.
     *
     * @param  array<string, scalar|null>  $params
     * @param  list<array{env: string, reason: string}>  $items
     * @return array{id: string, state: string, text: string, params: array<string, scalar|null>, items: list<array{env: string, reason: string}>, next: string|null, link: string|null}
     */
    private function row(string $id, string $state, array $params = [], ?string $link = null, array $items = [], ?string $variant = null): array
    {
        $specific = $variant === null ? null : 'telegram_status.rows.'.$id.'.next_'.$variant;

        $next = match ($state) {
            self::PROBLEM => $specific !== null && Lang::has($specific, 'en') ? $specific : 'telegram_status.rows.'.$id.'.next',
            self::UNKNOWN => $specific !== null && Lang::has($specific, 'en') ? $specific : 'telegram_status.next_unknown',
            default => null,
        };

        return ['id' => $id, 'state' => $state, 'text' => $variant ?? $state, 'params' => $params, 'items' => $items, 'next' => $next, 'link' => $link];
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
            return $this->row('token', $enabled ? self::PROBLEM : self::NEUTRAL, link: 'settings');
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
        $match = $snapshot['webhook_match'] ?? null;

        if (! $tokenSet) {
            return $this->row('webhook', self::NEUTRAL, $params, 'bot');
        }

        $issue = match (true) {
            $match === false => 'url_mismatch',
            $minutes !== null && $minutes * 60 < CheckTelegramWebhook::ERROR_WINDOW => 'delivery_errors',
            $pending !== null && $pending > CheckTelegramWebhook::PENDING_LIMIT => 'backlog',
            default => null,
        };

        if (isset($problems['telegram_webhook'])) {
            // Bot off: the only webhook problem is a foreign URL that is set.
            return $this->row('webhook', self::PROBLEM, $params, 'bot', variant: $enabled ? $issue : 'url_unexpected');
        }

        if (! is_bool($match)) {
            return $this->row('webhook', self::UNKNOWN, $params, 'bot');
        }

        if (! $enabled) {
            return $this->row('webhook', self::NEUTRAL, $params, 'bot');
        }

        return $issue === null ? $this->row('webhook', self::OK, $params, 'bot') : $this->row('webhook', self::PROBLEM, $params, 'bot', variant: $issue);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function chat(bool $enabled, array $snapshot): array
    {
        $key = InboxChat::key();

        if ($key === null || InboxChat::id() === null) {
            return $this->row('chat', $enabled ? self::PROBLEM : self::NEUTRAL, ['chat' => $key], 'settings');
        }

        // A verdict about another chat (the setting changed since) is no verdict.
        $state = ($snapshot['chat_key'] ?? null) !== $key ? self::UNKNOWN : match ($snapshot['chat'] ?? null) {
            'pass' => self::OK,
            'fail' => self::PROBLEM,
            default => self::UNKNOWN,
        };

        return $this->row('chat', $state, ['chat' => $key], 'settings');
    }

    /**
     * Only says whether a management chat and a bot token are set; delivery
     * is not proven. Neutral rather than red while the bot is off.
     *
     * @return array<string, mixed>
     */
    private function managementChat(bool $enabled): array
    {
        $set = MonitoringAlerts::isConfigured();

        return $this->row('management_chat', $set ? self::OK : ($enabled ? self::PROBLEM : self::NEUTRAL), link: 'settings');
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
        if (! $databaseQueue) {
            return $this->row('worker', self::UNKNOWN, link: 'monitoring', variant: 'not_database');
        }

        if ($this->cronState()['dead']) {
            return $this->row('worker', self::UNKNOWN, link: 'monitoring', variant: 'cron_dead');
        }

        $age = Heartbeat::age(Heartbeat::WORKER);

        if ($age === null) {
            return $this->row('worker', self::UNKNOWN, link: 'monitoring', variant: 'never');
        }

        return $this->row('worker', $age > ProblemMonitor::WORKER_SILENT_AFTER ? self::PROBLEM : self::OK, ['minutes' => (int) ceil($age / 60)], 'monitoring');
    }

    /**
     * @return array<string, mixed>
     */
    private function cron(): array
    {
        $cron = $this->cronState();

        if ($cron['dead']) {
            return $this->row('cron', self::PROBLEM, ['minutes' => $cron['minutes']], 'monitoring', variant: $cron['minutes'] === null ? 'never_dead' : null);
        }

        if ($cron['minutes'] === null) {
            return $this->row('cron', self::UNKNOWN, link: 'monitoring', variant: 'never');
        }

        return $this->row('cron', self::OK, ['minutes' => $cron['minutes']], 'monitoring');
    }

    /**
     * Scheduler liveness as ProblemMonitor sees it: the open problem:cron_dead
     * state, a heartbeat older than CRON_DEAD_AFTER, or a scheduler that never
     * ticked and was first missed more than NEVER_TICKED_GRACE ago.
     *
     * @return array{dead: bool, minutes: int|null}
     */
    private function cronState(): array
    {
        if ($this->cron !== null) {
            return $this->cron;
        }

        $age = Heartbeat::age(Heartbeat::SCHEDULER);
        $open = MonitoringState::query()->whereKey('problem:cron_dead')->whereNotNull('problem_since')->exists();
        $dead = $open || ($age !== null && $age > ProblemMonitor::CRON_DEAD_AFTER);

        if ($age === null && ! $dead) {
            $firstSeen = MonitoringState::query()->whereKey(Heartbeat::SCHEDULER)->first()?->meta['first_seen'] ?? null;
            $dead = is_string($firstSeen) && $this->minutesSince($firstSeen) !== null && $this->minutesSince($firstSeen) * 60 > ProblemMonitor::NEVER_TICKED_GRACE;
        }

        return $this->cron = ['dead' => $dead, 'minutes' => $age === null ? null : (int) ceil($age / 60)];
    }

    /**
     * @return array<string, mixed>
     */
    private function oldestJob(bool $databaseQueue): array
    {
        if (! $databaseQueue) {
            return $this->row('oldest_job', self::UNKNOWN, link: 'monitoring', variant: 'not_database');
        }

        $stats = QueueStats::snapshot();
        $stuck = $stats['oldest_age'] !== null && $stats['oldest_age'] >= ProblemMonitor::QUEUE_STUCK_AFTER;

        return $this->row('oldest_job', $stuck ? self::PROBLEM : self::OK, ['minutes' => $stats['oldest_age'] === null ? null : (int) floor($stats['oldest_age'] / 60), 'pending' => $stats['pending']], 'monitoring');
    }

    /**
     * Failed Telegram jobs of the last FAILED_JOB_DAYS days and the newest one's age.
     *
     * @return array<string, mixed>
     */
    private function failedJobs(): array
    {
        $recent = FailedJobs::recentForClasses(self::FAILED_JOB_CLASSES, self::FAILED_JOB_DAYS);
        $hours = $recent['newest'] === null ? null : max(0, (int) floor($recent['newest']->diffInHours(now(), true)));

        return $this->row('failed_jobs', $recent['count'] > 0 ? self::PROBLEM : self::OK, ['count' => $recent['count'], 'days' => self::FAILED_JOB_DAYS, 'hours' => $hours], 'monitoring');
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

        return $this->row('invalid_env', $onlyUnknown ? self::UNKNOWN : self::PROBLEM, ['count' => count($items)], items: array_map(fn (array $item): array => [
            'env' => $item['env'],
            'reason' => in_array($item['reason'], ['format', 'ignored', 'unknown'], true) ? $item['reason'] : 'unknown',
        ], $items));
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
