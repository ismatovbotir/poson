<?php

namespace App\Jobs;

use App\Actions\Monitoring\RunTaskNow;
use App\Enums\TaskRunStatus;
use App\Enums\TaskRunTrigger;
use App\Models\ScheduledTaskRun;
use App\Support\Monitoring\MonitoredTasks;
use App\Support\Monitoring\Redactor;
use App\Support\Monitoring\RunRecorder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Admin "Run now" of one scheduled task (plan 17, MON-S1, S2). Queued on the
 * monitoring queue (the worker must listen to it), unique per task, one try,
 * killed after 600 s (retry_after must be 660 on the server).
 *
 * The key is checked again here against config('monitoring.manual_tasks')
 * and the real schedule; the event is the scheduled one (its command and
 * arguments), never built from the key. If the task's overlap mutex is held
 * the run is recorded as skipped and nothing runs.
 */
class RunScheduledTask implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public const TIMEOUT = 600;

    public int $timeout = self::TIMEOUT;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 660;

    public function __construct(public string $key, public ?int $userId = null)
    {
        $this->onQueue((string) config('monitoring.queue', 'monitoring'));
    }

    public function uniqueId(): string
    {
        return md5($this->key);
    }

    public function handle(): void
    {
        try {
            $this->runTask();
        } finally {
            Cache::forget(RunTaskNow::QUEUED_KEY.md5($this->key));
        }
    }

    /**
     * The queue calls this on a timeout or when the job dies: release the "queued" mark.
     */
    public function failed(?Throwable $exception = null): void
    {
        Cache::forget(RunTaskNow::QUEUED_KEY.md5($this->key));
    }

    private function runTask(): void
    {
        if (! in_array($this->key, (array) config('monitoring.manual_tasks', []), true)) {
            return;
        }

        $event = MonitoredTasks::find($this->key);

        $run = ScheduledTaskRun::query()->create([
            'task' => $this->key,
            'trigger' => TaskRunTrigger::Manual,
            'status' => TaskRunStatus::Running,
            'started_at' => now(),
            'user_id' => $this->userId,
        ]);

        if ($event === null) {
            // Removed from the schedule since it was queued.
            $run->forceFill(['status' => TaskRunStatus::Skipped, 'finished_at' => now(), 'duration_ms' => 0, 'error' => 'not_scheduled'])->save();
            report(new RuntimeException('Monitoring: a queued manual task is no longer in the schedule.'));

            return;
        }

        if ($event->withoutOverlapping && $event->mutex->exists($event)) {
            $this->skip($run);

            return;
        }

        $started = hrtime(true);
        // Event::run() returns early (leaving this untouched) when it loses the mutex race.
        $event->exitCode = null;

        try {
            $event->run(app());
            $exitCode = $event->exitCode === null ? null : (int) $event->exitCode;
            $error = null;
        } catch (Throwable $exception) {
            $exitCode = 1;
            $error = Redactor::line($exception->getMessage(), RunRecorder::ERROR_CHARS);
        }

        if ($exitCode === null) {
            $this->skip($run);

            return;
        }

        $failed = $exitCode !== 0;
        $output = $failed ? app(RunRecorder::class)->output($event) : null;

        $run->forceFill([
            'status' => $failed ? TaskRunStatus::Failed : TaskRunStatus::Success,
            'finished_at' => now(),
            'duration_ms' => (int) round((hrtime(true) - $started) / 1e6),
            'exit_code' => $exitCode,
            'error' => $failed ? ($error ?? Redactor::line('Exit code '.$exitCode.' '.$output, RunRecorder::ERROR_CHARS)) : null,
            'output' => $output,
        ])->save();
    }

    private function skip(ScheduledTaskRun $run): void
    {
        $run->forceFill(['status' => TaskRunStatus::Skipped, 'finished_at' => now(), 'duration_ms' => 0])->save();
    }
}
