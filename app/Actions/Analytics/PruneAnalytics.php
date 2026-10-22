<?php

namespace App\Actions\Analytics;

use App\Support\Analytics\TrafficRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Retention (plan 04 §2): site_visits 30 days, bot_hits 14 days, daily
 * aggregates 25 months (config analytics.retention). Deletes in batches so
 * no long lock is held. Nightly.
 */
class PruneAnalytics
{
    public const BATCH = 5000;

    /**
     * @return array<string, int> Deleted rows per table
     */
    public function handle(): array
    {
        $retention = (array) config('analytics.retention', []);
        $aggregateCutoff = CarbonImmutable::now(TrafficRecorder::timezone())
            ->subMonths((int) ($retention['aggregates_months'] ?? 25))
            ->toDateString();

        return [
            'site_visits' => $this->prune(DB::table('site_visits')->where('occurred_at', '<', now()->subDays((int) ($retention['site_visits_days'] ?? 30)))),
            'bot_hits' => $this->prune(DB::table('bot_hits')->where('occurred_at', '<', now()->subDays((int) ($retention['bot_hits_days'] ?? 14)))),
            'traffic_daily' => $this->prune(DB::table('traffic_daily')->where('date', '<', $aggregateCutoff)),
            'traffic_daily_pages' => $this->prune(DB::table('traffic_daily_pages')->where('date', '<', $aggregateCutoff)),
        ];
    }

    protected function prune(Builder $query): int
    {
        $deleted = 0;

        do {
            $ids = (clone $query)->orderBy('id')->limit(self::BATCH)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += DB::table($query->from)->whereIn('id', $ids->all())->delete();
        } while ($ids->count() === self::BATCH);

        return $deleted;
    }
}
