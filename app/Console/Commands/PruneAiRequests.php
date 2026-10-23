<?php

namespace App\Console\Commands;

use App\Actions\Ai\PruneAiRequests as PruneAiRequestsAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Nightly retention of the AI request log (routes/console.php, plan 02 §4).
 */
#[Signature('ai:prune-requests')]
#[Description('Clear prompt and response texts of AI requests older than the retention period (90 days)')]
class PruneAiRequests extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PruneAiRequestsAction $prune): int
    {
        $this->components->info($prune->handle().' AI request(s) cleared.');

        return self::SUCCESS;
    }
}
