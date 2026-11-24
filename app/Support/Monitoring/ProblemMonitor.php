<?php

namespace App\Support\Monitoring;

use App\Enums\TaskRunStatus;
use App\Models\MonitoringState;
use App\Models\ScheduledTaskRun;
use App\Support\Chat\WebChatBacklog;
use App\Support\Chat\WorkingHours;
use Illuminate\Support\Number;
use Throwable;

/**
 * Evaluates the monitored problems and keeps their alert state in
 * monitoring_states (plan 17 §4):
 *
 *  - cron_dead: the scheduler heartbeat is older than 5 minutes;
 *  - task:{md5(task)}: the latest finished run of a task failed;
 *  - queue_stuck: the oldest available, unreserved job is 10+ minutes old;
 *  - worker_silent: the worker heartbeat is older than 5 minutes while the
 *    scheduler is alive (not evaluated while cron_dead).
 *
 * Alerting: first detection sends; an open problem reminds when the last alert
 * is 1 hour old; clearing sends "recovered" (only if an alert went out) and
 * resets. A failed send is retried at most every 5 minutes (meta.last_try);
 * a skipped one (bot / chat not configured) is not retried noisily.
 */
final class ProblemMonitor
{
    public const CRON_DEAD_AFTER = 300;

    public const WORKER_SILENT_AFTER = 300;

    public const QUEUE_STUCK_AFTER = 600;

    public const REMIND_AFTER = 3600;

    public const RETRY_SEND_AFTER = 300;

    /**
     * A scheduler that never ticked counts as dead this long after it was first missed.
     */
    public const NEVER_TICKED_GRACE = 600;

    public function __construct(private readonly int $timeout = 5) {}

    public static function using(int $timeout): self
    {
        return new self($timeout);
    }

    /**
     * Everything, in the order cron → tasks → queue → worker.
     */
    public function evaluate(): void
    {
        $cronDead = $this->evaluateCronDead();

        $this->evaluateTasks();
        $this->evaluateQueue();
        $this->evaluateWebChats();

        if (! $cronDead) {
            $this->evaluateWorker();
        }
    }

    /**
     * The watchdog's check: cron_dead only. True when the scheduler is dead.
     */
    public function evaluateCronDead(): bool
    {
        $age = Heartbeat::age(Heartbeat::SCHEDULER);
        $dead = $age !== null && $age > self::CRON_DEAD_AFTER;

        if ($age === null) {
            // Never ticked: dead once the first look found no tick for the grace period.
            $age = $this->secondsSinceFirstSeen();
            $dead = $age !== null && $age > self::NEVER_TICKED_GRACE;
        }

        $this->track('problem:cron_dead', $dead, 'cron_dead', ['age' => $age === null ? null : (int) ceil($age / 60)]);

        return $dead;
    }

    /**
     * Remember (meta.first_seen on the empty scheduler heartbeat row) when
     * the missing tick was first noticed. Null in local development.
     */
    private function secondsSinceFirstSeen(): ?int
    {
        if (app()->isLocal()) {
            return null;
        }

        $state = MonitoringState::query()->firstOrCreate(['key' => Heartbeat::SCHEDULER], ['ticked_at' => null, 'meta' => ['first_seen' => now()->toIso8601String()]]);
        $firstSeen = $state->meta['first_seen'] ?? null;

        if ($firstSeen === null) {
            $state->forceFill(['meta' => ['first_seen' => now()->toIso8601String()]])->save();

            return 0;
        }

        return max(0, (int) now()->diffInSeconds($firstSeen, true));
    }

    public function evaluateTasks(): void
    {
        $latest = ScheduledTaskRun::query()
            ->whereIn('id', ScheduledTaskRun::query()
                ->toBase()
                ->selectRaw('max(id)')
                ->whereIn('status', [TaskRunStatus::Success->value, TaskRunStatus::Failed->value])
                ->groupBy('task'))
            ->get();

        foreach ($latest as $run) {
            $this->track('problem:task:'.md5($run->task), $run->status === TaskRunStatus::Failed, 'task_failed', [
                'task' => $run->task,
                'at' => $run->started_at->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                'exit_code' => $run->exit_code,
                'error' => $run->error,
            ], recoveredValues: ['task' => $run->task]);
        }
    }

    public function evaluateQueue(): void
    {
        $stats = QueueStats::snapshot();
        $stuck = $stats['oldest_age'] !== null && $stats['oldest_age'] >= self::QUEUE_STUCK_AFTER;

        $this->track('problem:queue_stuck', $stuck, 'queue_stuck', [
            'age' => $stats['oldest_age'] === null ? null : (int) floor($stats['oldest_age'] / 60),
            'pending' => $stats['pending'],
        ]);
    }

    /**
     * chat_web_unanswered (plan 28 C5d): website hand-offs waiting for staff
     * longer than chatbot.web.unanswered_minutes during working hours. Counts
     * only. A failing check never stops the other problems.
     */
    public function evaluateWebChats(): void
    {
        try {
            $count = WorkingHours::isOpen() ? WebChatBacklog::unanswered() : 0;
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        $this->track('problem:chat_web_unanswered', $count > 0, 'chat_web_unanswered', [
            'count' => $count,
            'age' => max(1, (int) config('chatbot.web.unanswered_minutes', 15)),
        ]);
    }

    public function evaluateWorker(): void
    {
        $age = Heartbeat::age(Heartbeat::WORKER);
        $silent = $age !== null && $age > self::WORKER_SILENT_AFTER;

        $this->track('problem:worker_silent', $silent, 'worker_silent', ['age' => $age === null ? null : (int) ceil($age / 60)]);
    }

    /**
     * Track one problem's state and alert (first detection, hourly reminder,
     * recovered). Public for other monitors with their own checks
     * (plan 14 v2: telegram:check-webhook); $key must be unique (problem:*).
     *
     * @param  array<string, scalar|null>  $values
     * @param  array<string, scalar|null>  $recoveredValues
     */
    public function track(string $key, bool $problem, string $event, array $values, array $recoveredValues = []): void
    {
        $this->process($key, $problem, $event, $values, $recoveredValues, null);
    }

    /**
     * Level-aware tracking of one problem (plan 37, DM-13, DM-14): like
     * track(), but the problem has a level (rank by position in $events,
     * lowest first) that survives deliver() in meta.level. $level null means
     * "no problem". Escalating to a higher level alerts at once as a new
     * first alert; dropping to a lower level updates the stored level
     * silently (reminders go on hourly at the current level); clearing sends
     * one "recovered" ($recovered is its event key).
     *
     * @param  list<string>  $events  alert event per level, lowest first
     * @param  array<string, scalar|null>  $values
     * @param  array<string, scalar|null>  $recoveredValues
     */
    public function trackLevel(string $key, ?int $level, array $events, string $recovered, array $values, array $recoveredValues = []): void
    {
        $level = $level === null ? null : max(0, min($level, count($events) - 1));

        $this->process($key, $level !== null, $level === null ? $recovered : $events[$level], $values, $recoveredValues, $level);
    }

    /**
     * Project size (plan 37, DM-23): evaluated only by MeasureProjectSize
     * after a finished measurement, never by the minute check. Level 0 = warn,
     * 1 = critical. Integer math; a level is held until the size drops 5 %
     * below its limit (DM-14); a partial measurement (a lower bound) may
     * raise a level but never lower or clear one. Telegram values are
     * formatted sizes, never raw bytes (DM-7).
     */
    public function evaluateProjectSize(int $bytes, bool $partial = false): void
    {
        $thresholds = app(DiskThresholds::class);
        $warn = $thresholds->warnBytes();
        $critical = $thresholds->criticalBytes();
        $current = $this->storedLevel('problem:project_size');

        $level = match (true) {
            $bytes >= $critical => 1,
            $current === 1 && $bytes * 100 >= $critical * 95 => 1,
            $bytes >= $warn => 0,
            $current !== null && $bytes * 100 >= $warn * 95 => 0,
            default => null,
        };

        if ($partial && $current !== null && ($level === null || $level < $current)) {
            $level = $current;
        }

        $this->trackLevel('problem:project_size', $level, ['project_size_warn', 'project_size_critical'], 'project_size', [
            'project_size' => Number::fileSize($bytes, 1),
            'size_limit' => Number::fileSize($level === 1 ? $critical : $warn, 0),
        ], ['project_size' => Number::fileSize($bytes, 1)]);
    }

    private function storedLevel(string $key): ?int
    {
        $state = MonitoringState::query()->find($key);
        $level = $state?->problem_since === null ? null : ($state->meta['level'] ?? null);

        return is_int($level) ? $level : null;
    }

    /**
     * @param  array<string, scalar|null>  $values
     * @param  array<string, scalar|null>  $recoveredValues
     */
    private function process(string $key, bool $problem, string $event, array $values, array $recoveredValues, ?int $level): void
    {
        $state = MonitoringState::query()->find($key);

        if (! $problem) {
            if ($state !== null && $state->problem_since !== null) {
                $this->clear($state, $event, $recoveredValues);
            }

            return;
        }

        $state ??= new MonitoringState(['key' => $key]);

        if ($state->problem_since === null) {
            $state->problem_since = now();
            $state->alerted_at = null;
            $state->meta = null;
        } elseif ($level !== null && is_int($state->meta['level'] ?? null) && $level > $state->meta['level']) {
            // Escalation: a new first alert at once.
            $state->alerted_at = null;
            $state->meta = null;
        }

        $reminder = $state->alerted_at !== null;

        if ($reminder && $state->alerted_at->gt(now()->subSeconds(self::REMIND_AFTER))) {
            $this->withLevel($state, $level);
            $state->save();

            return;
        }

        if ($this->canTry($state)) {
            $this->deliver($state, MonitoringAlerts::problem($event, $values, $reminder, $this->timeout));
        }

        $this->withLevel($state, $level);
        $state->save();
    }

    /**
     * Keep meta.level through every outcome of deliver() (SENT clears meta, FAILED / SKIPPED replace it).
     */
    private function withLevel(MonitoringState $state, ?int $level): void
    {
        if ($level !== null) {
            $state->meta = [...($state->meta ?? []), 'level' => $level];
        }
    }

    /**
     * @param  array<string, scalar|null>  $values
     */
    private function clear(MonitoringState $state, string $event, array $values): void
    {
        // One attempt only: whatever its outcome, reset, so a recurrence gets a fresh first alert.
        if ($state->alerted_at !== null) {
            MonitoringAlerts::recovered($event, $values, $this->timeout);
        }

        $state->forceFill(['problem_since' => null, 'alerted_at' => null, 'meta' => null])->save();
    }

    private function canTry(MonitoringState $state): bool
    {
        $last = max($state->meta['last_try'] ?? '', $state->meta['skipped_at'] ?? '');

        return $last === '' || now()->diffInSeconds($last, true) >= self::RETRY_SEND_AFTER;
    }

    private function deliver(MonitoringState $state, string $result): void
    {
        if ($result === MonitoringAlerts::SENT) {
            $state->alerted_at = now();
            $state->meta = null;
        } elseif ($result === MonitoringAlerts::FAILED) {
            $state->meta = ['last_try' => now()->toIso8601String()];
        } else {
            // Not configured: look again in RETRY_SEND_AFTER seconds, not every minute.
            $state->meta = ['skipped_at' => now()->toIso8601String()];
        }
    }
}
