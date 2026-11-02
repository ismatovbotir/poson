<?php

namespace App\Actions\Monitoring;

use App\Enums\MonitoringAction as MonitoringActionType;
use App\Models\MonitoringAction;
use App\Models\User;
use App\Support\Monitoring\FailedJobs;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Delete failed jobs by uuid (plan 17, MON-S3, S9): any class, same id checks
 * and limits as RetryFailedJobs. Audited with the count and the uuids.
 */
final class ForgetFailedJobs
{
    use GuardsMonitoringWrites;

    /**
     * @param  array<mixed>  $ids
     * @return int jobs deleted
     *
     * @throws MonitoringActionException
     */
    public function handle(User $user, array $ids): int
    {
        $this->guard($user);

        $uuids = FailedJobs::parseIds($ids);
        $limiter = 'monitoring:bulk:'.$user->id;
        $this->limit($limiter, RetryFailedJobs::PER_WINDOW);

        $classes = FailedJobs::classes($uuids);

        if (count($classes) !== count($uuids)) {
            throw new MonitoringActionException('invalid');
        }

        RateLimiter::hit($limiter, RetryFailedJobs::WINDOW);

        $unique = array_values(array_unique($classes));

        MonitoringAction::query()->create([
            'user_id' => $user->id,
            'action' => MonitoringActionType::ForgetJob,
            'target' => json_encode($uuids),
            'job_class' => count($unique) === 1 ? Str::limit($unique[0], 191, '') : null,
            'count' => count($uuids),
            'ip' => request()->ip(),
        ]);

        $deleted = 0;

        foreach ($uuids as $uuid) {
            $deleted += app('queue.failer')->forget($uuid) ? 1 : 0;
        }

        return $deleted;
    }
}
