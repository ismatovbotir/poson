<?php

namespace App\Console\Commands;

use App\Actions\Orders\ReleaseStalePaymentHolds as ReleaseStalePaymentHoldsAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Every 10 minutes (routes/console.php): orders left "pending" after their
 * online payment hold ended go back to unpaid.
 */
#[Signature('orders:release-holds')]
#[Description('Set orders whose online payment hold ended back to unpaid')]
class ReleaseStalePaymentHolds extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ReleaseStalePaymentHoldsAction $release): int
    {
        $this->components->info($release->handle().' order(s) released.');

        return self::SUCCESS;
    }
}
