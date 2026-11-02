<?php

namespace App\Support\Monitoring;

use App\Models\MonitoringState;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stable task keys for the scheduled events (plan 17), the schedule
 * snapshot shown on the monitoring screen and the "Run now" allow-list.
 *
 * Key: the command without the php/artisan prefix and shell quotes, e.g.
 * `quotes:expire` or `model:prune --model=App\Models\McpCall`; closures and
 * jobs use their description / class name. Never build a command from a key
 * that came from a client: look the Event up with find() (MON-S1).
 */
final class MonitoredTasks
{
    public const SNAPSHOT_KEY = 'schedule:snapshot';

    public static function key(Event $event): string
    {
        $name = $event->description;

        if (! is_string($name) || $name === '') {
            $command = (string) $event->command;
            $name = preg_match('/artisan[\'"]?\s+(.+)$/s', $command, $match) === 1 ? $match[1] : $command;
        }

        return Str::limit(trim(str_replace(["'", '"'], '', $name)), 191, '');
    }

    /**
     * False for the monitor itself and the heartbeat job.
     */
    public static function isRecorded(Event $event): bool
    {
        return ! in_array(self::key($event), (array) config('monitoring.unrecorded_tasks', []), true);
    }

    /**
     * @return Collection<string, Event> key => event, recorded tasks only
     */
    public static function events(): Collection
    {
        return collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => self::isRecorded($event))
            ->keyBy(fn (Event $event): string => self::key($event));
    }

    public static function find(string $key): ?Event
    {
        return self::events()->get($key);
    }

    /**
     * Tasks an Admin may run by hand: the config allow-list AND a real
     * scheduled event with exactly that key.
     */
    public static function isManual(string $key): bool
    {
        return in_array($key, (array) config('monitoring.manual_tasks', []), true)
            && self::events()->has($key);
    }

    /**
     * The snapshot written by monitoring:check (console context), or null.
     * Web requests don't load routes/console.php, so the screen and the
     * run-now action read the schedule from here.
     */
    public static function snapshot(): ?MonitoringState
    {
        return MonitoringState::query()->find(self::SNAPSHOT_KEY);
    }

    /**
     * True when the snapshot lists the task as runnable (allow-list AND a real
     * scheduled event, computed in the console where the schedule is loaded).
     */
    public static function snapshotAllows(string $key): bool
    {
        return in_array($key, (array) config('monitoring.manual_tasks', []), true)
            && (self::snapshot()?->meta['tasks'][$key]['runnable'] ?? false) === true;
    }

    /**
     * Write the schedule snapshot (expression, timezone, next run, overlap,
     * runnable) for the monitoring screen.
     */
    public static function writeSnapshot(): void
    {
        $tasks = [];

        foreach (self::events() as $key => $event) {
            $tasks[$key] = [
                'expression' => $event->expression,
                'timezone' => (string) ($event->timezone ?? config('app.timezone')),
                'next_run' => self::nextRun($event),
                'overlap' => (bool) $event->withoutOverlapping,
                'overlap_minutes' => $event->withoutOverlapping ? (is_int($event->expiresAt) ? $event->expiresAt : 1440) : null,
                'runnable' => self::isManual($key),
                'kind' => $event instanceof CallbackEvent ? 'callback' : 'command',
            ];
        }

        MonitoringState::query()->updateOrCreate(
            ['key' => self::SNAPSHOT_KEY],
            ['ticked_at' => now(), 'meta' => ['tasks' => $tasks]],
        );
    }

    private static function nextRun(Event $event): ?string
    {
        try {
            return $event->nextRunDate()->utc()->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }
}
