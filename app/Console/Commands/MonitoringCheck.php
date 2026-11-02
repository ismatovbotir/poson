<?php

namespace App\Console\Commands;

use App\Support\Monitoring\Heartbeat;
use App\Support\Monitoring\InterruptedRuns;
use App\Support\Monitoring\MonitoredTasks;
use App\Support\Monitoring\PingUrl;
use App\Support\Monitoring\ProblemMonitor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Every-minute monitor (plan 17 §3, routes/console.php). Not recorded in
 * scheduled_task_runs. Each step is isolated so one failure can't skip the
 * rest: heartbeat, schedule snapshot, interrupted runs, problems and alerts,
 * external ping.
 */
#[Signature('monitoring:check')]
#[Description('Tick the scheduler heartbeat, evaluate problems, send alerts and ping the external check')]
class MonitoringCheck extends Command
{
    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'The system monitor: ticks the scheduler heartbeat, stores the schedule snapshot, closes interrupted task runs, evaluates problems, sends Telegram alerts and pings the external check URL.
Each step is isolated, so one failure does not skip the others. Exits with failure if any step failed.
Side effects: alerts and pings. Scheduled every minute; do not run it by hand unless debugging.';

    public function handle(): int
    {
        $steps = [
            fn () => Heartbeat::tick(Heartbeat::SCHEDULER),
            fn () => Heartbeat::seed(Heartbeat::WORKER),
            fn () => MonitoredTasks::writeSnapshot(),
            fn () => InterruptedRuns::sweep(),
            fn () => (new ProblemMonitor)->evaluate(),
            fn () => PingUrl::send(),
        ];

        $failed = 0;

        foreach ($steps as $step) {
            try {
                $step();
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
