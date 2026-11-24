<?php

namespace App\Actions\Monitoring;

use App\Enums\DiskRunStatus;
use App\Jobs\MeasureProjectSize;
use App\Models\MonitoringState;
use App\Models\User;
use App\Support\Monitoring\DiskRun;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Start a project size measurement (plan 37, DM-25, DM-27). Needs
 * monitoring.manage (no password: the job only reads sizes). The start is
 * atomic: a cache lock plus a row lock on "disk:progress", then one
 * "queued" write with a new random run_id and one job. It refuses (and
 * returns false, the page shows the current state) while a run is queued or
 * running and not stale, for 15 minutes after a finished run and for 2
 * after a failed one. A stale run (worker lost or never started) is closed
 * inside the lock and a new start is allowed at once. 5 starts per 10
 * minutes per user.
 */
final class StartDiskMeasurement
{
    use GuardsMonitoringWrites;

    public const LOCK = 'monitoring:disk:start';

    public const PER_WINDOW = 5;

    public const WINDOW = 600;

    /**
     * @return bool whether a new measurement was queued
     *
     * @throws AuthorizationException
     * @throws MonitoringActionException rate
     */
    public function handle(User $user): bool
    {
        if (! $user->can('monitoring.manage')) {
            throw new AuthorizationException;
        }

        $limiter = 'monitoring:disk-start:'.$user->id;
        $this->limit($limiter, self::PER_WINDOW);

        $runId = $this->claim();

        if ($runId === null) {
            return false;
        }

        RateLimiter::hit($limiter, self::WINDOW);
        MeasureProjectSize::dispatch($runId);

        return true;
    }

    /**
     * Take the single run slot: the new run_id (state "queued"), or null when a run is
     * active or too recent. Also used by a scheduled run of the job.
     */
    public function claim(): ?string
    {
        try {
            return Cache::lock(self::LOCK, 15)->block(5, fn (): ?string => DB::transaction(function (): ?string {
                MonitoringState::query()->insertOrIgnore(['key' => DiskRun::KEY, 'updated_at' => now()]);

                $row = MonitoringState::query()->lockForUpdate()->findOrFail(DiskRun::KEY);
                $run = DiskRun::fromState($row);

                if (! $run->mayStart()) {
                    return null;
                }

                if ($run->stale) {
                    // The old run is dead: free its mutex so the new job can start at once.
                    Cache::lock(MeasureProjectSize::MUTEX)->forceRelease();
                }

                $runId = DiskRun::newRunId();
                $row->forceFill(['meta' => DiskRun::meta($runId, DiskRunStatus::Queued, now())])->save();

                return $runId;
            }));
        } catch (LockTimeoutException) {
            return null;
        }
    }
}
