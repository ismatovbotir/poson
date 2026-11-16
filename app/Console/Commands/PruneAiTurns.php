<?php

namespace App\Console\Commands;

use App\Actions\Chat\PruneAiTurns as PruneAiTurnsAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Nightly clean-up of the chat bot's AI conversation turns (plan 30, routes/console.php).
 */
#[Signature('chat:prune-ai')]
#[Description('Delete chat bot AI conversation turns older than 30 days')]
class PruneAiTurns extends Command
{
    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Deletes the redacted AI conversation turns of the chat bot (questions and AI answers) older than 30 days (config chatbot.ai.retention_days), in chunks.
The phrases on Chat bot -> Teach and the daily AI usage counters are kept.
Runs by itself every night at 03:25 (Tashkent); there is normally no reason to run it by hand.
Side effect: permanently deletes old AI turns; the "Bot conversation" panel in the inbox shows only the last 30 days. Scheduled nightly.';

    /**
     * Execute the console command.
     */
    public function handle(PruneAiTurnsAction $prune): int
    {
        $this->components->info($prune->handle().' AI turn(s) deleted.');

        return self::SUCCESS;
    }
}
