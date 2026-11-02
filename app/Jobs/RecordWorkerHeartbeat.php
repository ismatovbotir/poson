<?php

namespace App\Jobs;

use App\Support\Monitoring\Heartbeat;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Worker heartbeat (plan 17): scheduled every minute. When a queue worker
 * runs it, it ticks heartbeat:worker and stores the queue lag (time between
 * dispatch and start). If the worker is dead the job just waits, so the
 * tick goes stale. Unique, so a stuck queue doesn't pile up copies.
 */
class RecordWorkerHeartbeat implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public float $dispatchedAt;

    public function __construct()
    {
        $this->dispatchedAt = microtime(true);
    }

    public function handle(): void
    {
        $lagMs = max(0, (int) round((microtime(true) - $this->dispatchedAt) * 1000));

        Heartbeat::tick(Heartbeat::WORKER, ['lag_ms' => $lagMs]);
    }
}
