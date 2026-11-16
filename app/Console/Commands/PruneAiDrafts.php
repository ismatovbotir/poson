<?php

namespace App\Console\Commands;

use App\Actions\Catalog\Ai\PruneAiDrafts as PruneAiDraftsAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Hourly temp cleanup of the AI product wizard (plan 32 PW-28, routes/console.php).
 */
#[Signature('ai:prune-drafts')]
#[Description('Expire old AI product wizard drafts and delete their temporary image folders')]
class PruneAiDrafts extends Command
{
    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Marks AI wizard steps that got stuck (no queue worker on ai-admin, a crashed worker or a crashed apply) as failed so the user can retry, expires AI product wizard drafts past their lifetime, deletes the photo copies sent to the AI after an hour, the cleaned and kept images of expired drafts, folders without a draft, and draft rows older than 7 days.
Runs by itself every hour; run by hand only to free disk space at once.
Side effect: permanently deletes temporary wizard files in storage/app/private/ai-drafts and old draft rows (products and their images are never touched). Scheduled hourly.';

    /**
     * Execute the console command.
     */
    public function handle(PruneAiDraftsAction $prune): int
    {
        foreach ($prune->handle() as $what => $count) {
            $this->components->twoColumnDetail($what, (string) $count);
        }

        return self::SUCCESS;
    }
}
