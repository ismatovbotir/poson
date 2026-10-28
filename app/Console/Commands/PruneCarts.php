<?php

namespace App\Console\Commands;

use App\Actions\Cart\PruneCarts as PruneCartsAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Nightly clean-up of old and empty carts (routes/console.php).
 */
#[Signature('carts:prune')]
#[Description('Delete empty carts after 1 day, converted carts after 1 day and other carts after 30 days')]
class PruneCarts extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PruneCartsAction $prune): int
    {
        $this->components->info($prune->handle().' cart(s) deleted.');

        return self::SUCCESS;
    }
}
