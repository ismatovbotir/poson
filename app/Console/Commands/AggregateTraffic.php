<?php

namespace App\Console\Commands;

use App\Actions\Analytics\AggregateTraffic as AggregateTrafficAction;
use App\Support\Analytics\TrafficRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Roll raw traffic up into the daily tables. Scheduled every 15 minutes for
 * today and nightly with --yesterday (routes/console.php). Safe to re-run.
 */
#[Signature('analytics:aggregate {date? : Day to aggregate (Y-m-d, analytics timezone); default today} {--yesterday : Aggregate yesterday}')]
#[Description('Aggregate raw site visits and crawler hits of one day into traffic_daily(_pages)')]
class AggregateTraffic extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AggregateTrafficAction $aggregate): int
    {
        $today = CarbonImmutable::parse(TrafficRecorder::today(), TrafficRecorder::timezone());
        $argument = $this->argument('date');

        if (is_string($argument) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $argument) !== 1) {
            $this->components->error('The date must be Y-m-d.');

            return self::FAILURE;
        }

        $date = match (true) {
            (bool) $this->option('yesterday') => $today->subDay()->toDateString(),
            is_string($argument) => $argument,
            default => $today->toDateString(),
        };

        $result = $aggregate->handle($date);

        if ($result['skipped']) {
            $this->components->warn("Another run is aggregating {$date}; skipped.");

            return self::SUCCESS;
        }

        $this->components->info("Aggregated {$date}: {$result['daily']} daily row(s), {$result['pages']} page row(s).");

        return self::SUCCESS;
    }
}
