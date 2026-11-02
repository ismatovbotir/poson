<?php

namespace App\Console\Commands;

use App\Actions\Payments\Uzqr\SweepUzqrInvoices;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Every minute (routes/console.php, without overlapping, on one server):
 * checks due dynamic UzQR invoices at the bank (plan 11e).
 */
#[Signature('uzqr:sync')]
#[Description('Check due dynamic UzQR invoices at the bank')]
class SyncUzqrInvoices extends Command
{
    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Asks the bank for the status of dynamic UzQR invoices that are due, handles expiry, and closes stale rows. Batch size: config uzqr.sweep_batch (default 100).
The bank has no callbacks, so this is how UzQR payments get confirmed. A manual run is only useful for debugging.
Side effects: outgoing bank requests and order payment status changes. Scheduled every minute.';

    /**
     * Execute the console command.
     */
    public function handle(SweepUzqrInvoices $sweep): int
    {
        $this->components->info($sweep->handle().' UzQR invoice(s) checked.');

        return self::SUCCESS;
    }
}
