<?php

namespace App\Support\Monitoring;

use Illuminate\Support\Facades\DB;

/**
 * Database queue figures (plan 17). pending = not reserved and already
 * available, so delayed retries (available_at in the future) don't count and
 * can't look "stuck". Two queries.
 */
final class QueueStats
{
    /**
     * @return array{pending: int, oldest_age: int|null, failed: int}
     */
    public static function snapshot(): array
    {
        $row = DB::table('jobs')
            ->whereNull('reserved_at')
            ->where('available_at', '<=', now()->getTimestamp())
            ->selectRaw('count(*) as pending, min(available_at) as oldest')
            ->first();

        return [
            'pending' => (int) ($row->pending ?? 0),
            'oldest_age' => ($row->oldest ?? null) === null ? null : max(0, now()->getTimestamp() - (int) $row->oldest),
            'failed' => DB::table('failed_jobs')->count(),
        ];
    }
}
