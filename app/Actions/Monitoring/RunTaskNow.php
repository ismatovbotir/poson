<?php

namespace App\Actions\Monitoring;

use App\Enums\MonitoringAction as MonitoringActionType;
use App\Enums\TaskRunStatus;
use App\Jobs\RunScheduledTask;
use App\Models\MonitoringAction;
use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Support\Monitoring\MonitoredTasks;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Admin "Run now" for one allow-listed scheduled task (plan 17, MON-S1, S2,
 * S9). The key must equal an entry of config('monitoring.manual_tasks') AND a
 * runnable task of the schedule snapshot (computed from the real schedule); no command is ever built from it.
 * Limits: no unfinished run, 300 s cooldown per task, 5 starts per 10 minutes
 * per user. Writes an audit row, then queues RunScheduledTask on the
 * monitoring queue.
 */
final class RunTaskNow
{
    use GuardsMonitoringWrites;

    public const COOLDOWN = 300;

    /**
     * Set while a run is queued or running (released by the job).
     */
    public const QUEUED_KEY = 'monitoring:queued:';

    public const PER_WINDOW = 5;

    public const WINDOW = 600;

    /**
     * @throws MonitoringActionException
     */
    public function handle(User $user, string $key): void
    {
        $this->guard($user);

        if (! MonitoredTasks::snapshotAllows($key)) {
            throw new MonitoringActionException('invalid');
        }

        $limiter = 'monitoring:run-user:'.$user->id;
        $this->limit($limiter, self::PER_WINDOW);

        if (ScheduledTaskRun::query()->where('task', $key)->where('status', TaskRunStatus::Running)->exists()) {
            throw new MonitoringActionException('running');
        }

        if (! Cache::add('monitoring:run:'.$key, 1, self::COOLDOWN)) {
            throw new MonitoringActionException('cooldown');
        }

        if (! Cache::add(self::QUEUED_KEY.md5($key), 1, RunScheduledTask::TIMEOUT + 60)) {
            throw new MonitoringActionException('queued');
        }

        RateLimiter::hit($limiter, self::WINDOW);

        MonitoringAction::query()->create([
            'user_id' => $user->id,
            'action' => MonitoringActionType::RunTask,
            'target' => json_encode([$key]),
            'count' => 1,
            'ip' => request()->ip(),
        ]);

        RunScheduledTask::dispatch($key, $user->id);
    }
}
