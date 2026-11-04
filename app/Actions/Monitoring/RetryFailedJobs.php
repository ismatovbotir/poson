<?php

namespace App\Actions\Monitoring;

use App\Enums\MonitoringAction as MonitoringActionType;
use App\Models\MonitoringAction;
use App\Models\User;
use App\Support\Monitoring\FailedJobs;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Retry failed jobs by uuid (plan 17, MON-S3, S4, S9): every id is a uuid
 * that exists in failed_jobs (≤ 50), every job's class is in
 * config('monitoring.retry_classes'), and queue:retry gets the id array only
 * (never "all" or --range). Customer inbox alerts are never retried (T21-31). 10 retry / forget calls per 10 minutes per user.
 * One audit row with the count and the uuids.
 */
final class RetryFailedJobs
{
    use GuardsMonitoringWrites;

    public const PER_WINDOW = 10;

    public const WINDOW = 600;

    /**
     * @param  array<mixed>  $ids
     * @return int jobs pushed back
     *
     * @throws MonitoringActionException
     */
    public function handle(User $user, array $ids): int
    {
        $this->guard($user);

        $uuids = FailedJobs::parseIds($ids);
        $limiter = 'monitoring:bulk:'.$user->id;
        $this->limit($limiter, self::PER_WINDOW);

        $classes = FailedJobs::classes($uuids);

        if (count($classes) !== count($uuids)) {
            throw new MonitoringActionException('invalid');
        }

        foreach ($classes as $class) {
            if (! FailedJobs::isRetryable($class)) {
                throw new MonitoringActionException('class');
            }
        }

        // T21-31: an inbox alert would re-send a customer excerpt, maybe after the thread or chat changed.
        if (FailedJobs::hasInboxAlert($uuids)) {
            throw new MonitoringActionException('inbox_alert');
        }

        RateLimiter::hit($limiter, self::WINDOW);

        $unique = array_values(array_unique($classes));

        MonitoringAction::query()->create([
            'user_id' => $user->id,
            'action' => MonitoringActionType::RetryJob,
            'target' => json_encode($uuids),
            'job_class' => count($unique) === 1 ? Str::limit($unique[0], 191, '') : null,
            'count' => count($uuids),
            'ip' => request()->ip(),
        ]);

        Artisan::call('queue:retry', ['id' => $uuids]);

        return count($uuids);
    }
}
