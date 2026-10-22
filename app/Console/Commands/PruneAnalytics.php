<?php

namespace App\Console\Commands;

use App\Actions\Analytics\PruneAnalytics as PruneAnalyticsAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Nightly retention of raw and aggregated analytics (routes/console.php).
 */
#[Signature('analytics:prune')]
#[Description('Delete raw analytics rows and old aggregates past their retention')]
class PruneAnalytics extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PruneAnalyticsAction $prune): int
    {
        foreach ($prune->handle() as $table => $deleted) {
            $this->components->twoColumnDetail($table, "{$deleted} deleted");
        }

        return self::SUCCESS;
    }
}
