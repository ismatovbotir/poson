<?php

namespace App\Console\Commands;

use App\Actions\Quotes\ExpireQuotes as ExpireQuotesAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Nightly expiry of sent quotes past their validity (routes/console.php).
 */
#[Signature('quotes:expire')]
#[Description('Mark sent quotes past valid_until as expired (with a history entry)')]
class ExpireQuotes extends Command
{
    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Marks sent quotes whose valid_until date has passed as expired and adds a history entry to each.
Runs by itself every night, so a manual run is only needed after a long scheduler outage.
Side effect: quote statuses change. Scheduled nightly.';

    /**
     * Execute the console command.
     */
    public function handle(ExpireQuotesAction $expire): int
    {
        $this->components->info($expire->handle().' quote(s) expired.');

        return self::SUCCESS;
    }
}
