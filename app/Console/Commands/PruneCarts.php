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
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Deletes empty carts after 1 day, converted carts 1 day after the order, and all other carts after 30 days untouched.
Runs by itself every night; there is normally no reason to run it by hand.
Side effect: permanently deletes carts and their items. Scheduled nightly.';

    /**
     * Execute the console command.
     */
    public function handle(PruneCartsAction $prune): int
    {
        $this->components->info($prune->handle().' cart(s) deleted.');

        return self::SUCCESS;
    }
}
