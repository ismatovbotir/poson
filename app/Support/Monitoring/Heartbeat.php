<?php

namespace App\Support\Monitoring;

use App\Models\MonitoringState;
use Illuminate\Support\Carbon;

/**
 * Liveness ticks kept in monitoring_states (plan 17): the scheduler
 * (monitoring:check) and the queue worker (RecordWorkerHeartbeat).
 */
final class Heartbeat
{
    public const SCHEDULER = 'heartbeat:scheduler';

    public const WORKER = 'heartbeat:worker';

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public static function tick(string $key, ?array $meta = null): void
    {
        MonitoringState::query()->updateOrCreate(
            ['key' => $key],
            ['ticked_at' => now(), 'meta' => $meta],
        );
    }

    /**
     * Start a heartbeat's clock at "now" if it never ticked, so a worker that
     * never starts is noticed after the threshold.
     */
    public static function seed(string $key): void
    {
        MonitoringState::query()->firstOrCreate(['key' => $key], ['ticked_at' => now(), 'meta' => ['seeded' => true]]);
    }

    public static function lastTick(string $key): ?Carbon
    {
        return MonitoringState::query()->whereKey($key)->value('ticked_at');
    }

    /**
     * Seconds since the last tick, or null when it never ticked.
     */
    public static function age(string $key): ?int
    {
        $tick = self::lastTick($key);

        return $tick === null ? null : max(0, (int) $tick->diffInSeconds(now(), true));
    }
}
