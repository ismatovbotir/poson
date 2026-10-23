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
     * Execute the console command.
     */
    public function handle(ExpireQuotesAction $expire): int
    {
        $this->components->info($expire->handle().' quote(s) expired.');

        return self::SUCCESS;
    }
}
