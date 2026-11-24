<?php

namespace App\Console\Commands;

use App\Actions\Analytics\PruneAdClickIds as PruneAdClickIdsAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Nightly retention of the Yandex ad click ids (plan 40, routes/console.php).
 */
#[Signature('analytics:prune-click-ids')]
#[Description('Clear Yandex click ids and Metrika ClientIDs on leads and orders older than 12 months')]
class PruneAdClickIds extends Command
{
    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Clears the stored Yandex Direct click id (yclid) and Metrika ClientID on leads and orders created more than 12 months ago (config analytics.retention.ad_click_ids_months), and prints how many rows were cleared per table.
The leads and orders themselves are kept; only the two identifiers are set to empty. Safe to run again at any time.
Side effect: those identifiers can no longer be used for offline conversions. Scheduled nightly.';

    /**
     * Execute the console command.
     */
    public function handle(PruneAdClickIdsAction $prune): int
    {
        foreach ($prune->handle() as $table => $cleared) {
            $this->components->twoColumnDetail($table, "{$cleared} cleared");
        }

        return self::SUCCESS;
    }
}
