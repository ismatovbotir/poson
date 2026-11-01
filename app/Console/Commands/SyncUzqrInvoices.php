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
     * Execute the console command.
     */
    public function handle(SweepUzqrInvoices $sweep): int
    {
        $this->components->info($sweep->handle().' UzQR invoice(s) checked.');

        return self::SUCCESS;
    }
}
