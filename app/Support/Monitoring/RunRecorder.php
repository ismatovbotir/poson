<?php

namespace App\Support\Monitoring;

use App\Enums\TaskRunStatus;
use App\Enums\TaskRunTrigger;
use App\Models\ScheduledTaskRun;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * Writes scheduled_task_runs from the scheduler's own events (plan 17).
 *
 * The Starting event creates a "running" row and remembers its id under
 * spl_object_id($event); Finished / Failed find it again. This only works
 * while the start and the end happen in the SAME process: the scheduler must
 * never use runInBackground() (the end events would fire in another process).
 * A guard test (RunRecorderTest) fails if any scheduled event does.
 *
 * Recording must never break the scheduler: every failure is reported and
 * swallowed. Registered as a singleton so the map survives between events.
 */
#[Singleton]
final class RunRecorder
{
    public const OUTPUT_BYTES = 2048;

    public const ERROR_CHARS = 300;

    /**
     * @var array<int, int> spl_object_id(event) => run id
     */
    private array $runs = [];

    /**
     * @var array<int, float> spl_object_id(event) => hrtime(true) at start
     */
    private array $startedAt = [];

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(ScheduledTaskStarting::class, fn (ScheduledTaskStarting $e) => $this->guard(fn () => $this->starting($e->task)));
        $events->listen(ScheduledTaskFinished::class, fn (ScheduledTaskFinished $e) => $this->guard(fn () => $this->finished($e->task, $e->runtime)));
        $events->listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $e) => $this->guard(fn () => $this->failed($e->task, $e->exception)));
        $events->listen(ScheduledTaskSkipped::class, fn (ScheduledTaskSkipped $e) => $this->guard(fn () => $this->skipped($e->task)));
    }

    private function starting(Event $task): void
    {
        if (! MonitoredTasks::isRecorded($task)) {
            return;
        }

        $run = ScheduledTaskRun::query()->create([
            'task' => MonitoredTasks::key($task),
            'trigger' => TaskRunTrigger::Schedule,
            'status' => TaskRunStatus::Running,
            'started_at' => now(),
        ]);

        $id = spl_object_id($task);
        $this->runs[$id] = $run->id;
        $this->startedAt[$id] = hrtime(true) / 1e6;
    }

    private function finished(Event $task, ?float $runtime): void
    {
        $run = $this->run($task);

        if ($run === null) {
            return;
        }

        $exitCode = $task instanceof CallbackEvent ? ($task->exitCode ?? 0) : (int) $task->exitCode;
        $failed = $exitCode !== 0;

        $run->forceFill([
            'status' => $failed ? TaskRunStatus::Failed : TaskRunStatus::Success,
            'finished_at' => now(),
            'duration_ms' => $this->durationMs($task, $runtime),
            'exit_code' => $exitCode,
        ]);

        if ($failed) {
            $run->output = $this->output($task);
            $run->error = $run->error ?? Redactor::line('Exit code '.$exitCode.' '.$run->output, self::ERROR_CHARS);
        }

        $run->save();

        // A failing command also fires Failed (it adds the exception message),
        // so keep its id until then; everything else is finished.
        if (! $failed || $task instanceof CallbackEvent) {
            $this->forget($task);
        }
    }

    private function failed(Event $task, Throwable $exception): void
    {
        $run = $this->run($task);

        if ($run === null) {
            return;
        }

        $run->forceFill([
            'status' => TaskRunStatus::Failed,
            'finished_at' => $run->finished_at ?? now(),
            'duration_ms' => $run->duration_ms ?? $this->durationMs($task, null),
            'exit_code' => $run->exit_code ?? ((int) $task->exitCode ?: 1),
            'error' => $run->error ?? Redactor::line($exception->getMessage(), self::ERROR_CHARS),
            'output' => $run->output ?? $this->output($task),
        ])->save();

        $this->forget($task);
    }

    private function skipped(Event $task): void
    {
        if (! MonitoredTasks::isRecorded($task)) {
            return;
        }

        ScheduledTaskRun::query()->create([
            'task' => MonitoredTasks::key($task),
            'trigger' => TaskRunTrigger::Schedule,
            'status' => TaskRunStatus::Skipped,
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 0,
        ]);
    }

    private function run(Event $task): ?ScheduledTaskRun
    {
        $id = $this->runs[spl_object_id($task)] ?? null;

        return $id === null ? null : ScheduledTaskRun::query()->find($id);
    }

    private function forget(Event $task): void
    {
        unset($this->runs[spl_object_id($task)], $this->startedAt[spl_object_id($task)]);
    }

    private function durationMs(Event $task, ?float $runtime): int
    {
        if ($runtime !== null) {
            return (int) round($runtime * 1000);
        }

        $started = $this->startedAt[spl_object_id($task)] ?? null;

        return $started === null ? 0 : max(0, (int) round(hrtime(true) / 1e6 - $started));
    }

    /**
     * The last OUTPUT_BYTES of the task's stored output (routes/console.php
     * adds storeOutput()), redacted. Null when nothing was captured.
     */
    public function output(Event $task): ?string
    {
        $path = $task->output;

        if (! is_string($path) || $path === '' || in_array($path, ['/dev/null', 'NUL'], true) || ! is_file($path)) {
            return null;
        }

        $size = filesize($path);
        $handle = $size === false ? false : fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            fseek($handle, max(0, $size - self::OUTPUT_BYTES));
            $tail = (string) stream_get_contents($handle, self::OUTPUT_BYTES);
        } finally {
            fclose($handle);
        }

        $clean = Redactor::clean($tail, self::OUTPUT_BYTES, cutTrace: false);

        return $clean === '' ? null : $clean;
    }

    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
