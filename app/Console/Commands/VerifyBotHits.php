<?php

namespace App\Console\Commands;

use App\Actions\Analytics\VerifyRecentBotHits;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Nightly crawler verification (routes/console.php): refreshes stale IP
 * ranges, verifies the last 3 days (undecided IPs retried) and
 * re-aggregates them. Deploy step: `php artisan analytics:refresh-bot-ranges`
 * once, so verification has ranges before the first night.
 */
#[Signature('analytics:verify-bots {date? : Only this day (Y-m-d, analytics timezone); default the last 3 days}')]
#[Description('Verify crawler hits (published IP ranges / forward-confirmed reverse DNS) and re-aggregate those days')]
class VerifyBotHits extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(VerifyRecentBotHits $verify): int
    {
        $argument = $this->argument('date');

        if (is_string($argument) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $argument) !== 1) {
            $this->components->error('The date must be Y-m-d.');

            return self::FAILURE;
        }

        $result = $verify->handle(is_string($argument) ? $argument : null);

        if ($result['ranges_refreshed']) {
            $this->components->info('Downloaded the published crawler IP ranges first (missing or stale).');
        }

        foreach ($result['days'] as $date => $stats) {
            $this->components->twoColumnDetail($date, "{$stats['checked']} checked, {$stats['verified']} genuine, {$stats['spoofed']} spoofed, {$stats['undecided']} undecided");
        }

        return self::SUCCESS;
    }
}
