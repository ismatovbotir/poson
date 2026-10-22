<?php

namespace App\Actions\Analytics;

use App\Support\Analytics\BotIpRanges;
use App\Support\Analytics\TrafficRecorder;
use Carbon\CarbonImmutable;

/**
 * Nightly crawler verification (analytics:verify-bots):
 *
 *  1. download the published IP ranges first when the file is missing or
 *     older than config analytics.verification.ranges_max_age_days (a fresh
 *     deploy has no file: deploy step `php artisan analytics:refresh-bot-ranges`,
 *     otherwise the first nightly run does it);
 *  2. verify the last N days (config analytics.verification.days, default 3:
 *     yesterday and the two days before), so pairs left undecided by the
 *     DNS budget are retried;
 *  3. re-aggregate each of those days with the verdicts.
 *
 * With an explicit date, only that day is verified and re-aggregated.
 */
class VerifyRecentBotHits
{
    public function __construct(
        protected VerifyBotHits $verify,
        protected AggregateTraffic $aggregate,
        protected RefreshBotIpRanges $refreshRanges,
        protected BotIpRanges $ranges,
    ) {}

    /**
     * @return array{ranges_refreshed: bool, days: array<string, array{checked: int, verified: int, spoofed: int, undecided: int}>}
     */
    public function handle(?string $date = null): array
    {
        $refreshed = false;

        if ($this->ranges->isStale()) {
            $this->refreshRanges->handle();
            $this->ranges->forget();
            $refreshed = true;
        }

        $days = [];

        foreach ($this->dates($date) as $day) {
            $days[$day] = $this->verify->handle($day);
            $this->aggregate->handle($day);
        }

        return ['ranges_refreshed' => $refreshed, 'days' => $days];
    }

    /**
     * @return list<string> Oldest first
     */
    protected function dates(?string $date): array
    {
        if ($date !== null) {
            return [CarbonImmutable::parse($date, TrafficRecorder::timezone())->toDateString()];
        }

        $today = CarbonImmutable::parse(TrafficRecorder::today(), TrafficRecorder::timezone());
        $count = max(1, (int) config('analytics.verification.days', 3));
        $dates = [];

        for ($i = $count; $i >= 1; $i--) {
            $dates[] = $today->subDays($i)->toDateString();
        }

        return $dates;
    }
}
