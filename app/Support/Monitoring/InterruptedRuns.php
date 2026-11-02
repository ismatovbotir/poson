<?php

namespace App\Support\Monitoring;

use App\Enums\TaskRunStatus;
use App\Models\MonitoringState;
use App\Models\ScheduledTaskRun;

/**
 * A "running" row whose process died never gets an end event. Rows older than
 * twice the task's overlap lock (at least 60 minutes) become failed with the
 * error "interrupted" (plan 17 §3).
 */
final class InterruptedRuns
{
    public const MIN_MINUTES = 60;

    public const ERROR = 'interrupted';

    /**
     * @return int rows marked
     */
    public static function sweep(): int
    {
        $candidates = ScheduledTaskRun::query()
            ->where('status', TaskRunStatus::Running)
            ->where('started_at', '<', now()->subMinutes(self::MIN_MINUTES))
            ->get();

        if ($candidates->isEmpty()) {
            return 0;
        }

        $tasks = MonitoringState::query()->whereKey(MonitoredTasks::SNAPSHOT_KEY)->first()?->meta['tasks'] ?? [];
        $marked = 0;

        foreach ($candidates as $run) {
            $limit = max(self::MIN_MINUTES, 2 * (int) ($tasks[$run->task]['overlap_minutes'] ?? 0));

            if ($run->started_at->gt(now()->subMinutes($limit))) {
                continue;
            }

            $run->forceFill([
                'status' => TaskRunStatus::Failed,
                'finished_at' => now(),
                'error' => self::ERROR,
            ])->save();

            $marked++;
        }

        return $marked;
    }
}
