<?php

namespace App\Console\Commands;

use App\Actions\Chat\Knowledge\RebuildAiKnowledge;
use App\Enums\AiKnowledgeSource;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Hourly incremental sync of the website knowledge index (plan 33 K-10,
 * routes/console.php). Runs in the foreground; stops after its time budget
 * and continues from there next hour. Output and logs: counts only.
 */
#[Signature('chat:index-knowledge {--source= : Only one source: page, news, article, brand, service or software}')]
#[Description('Sync the chat bot AI website knowledge index with the public pages, news, brands and services')]
class IndexKnowledge extends Command
{
    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Compares the public site (active pages except the legal ones, visible news of the last 24 months, articles, listed brands, visible service and software products) with the chunks the chat bot AI can use, and updates only what changed. Chunks of hidden or deleted content are removed; money amounts are masked and unsafe text is skipped.
Use --source=page|news|article|brand|service|software to limit it to one kind; an unknown value does nothing and fails.
Stops after about 20 seconds and continues where it stopped on the next run. Normally changes are indexed by queued jobs on the "knowledge" queue as soon as they are saved; this command only catches up.
Side effects: writes the ai_knowledge_chunks table, makes no external call. Scheduled hourly.';

    public function handle(RebuildAiKnowledge $rebuild): int
    {
        $only = null;
        $option = $this->option('source');

        if ($option !== null && $option !== '') {
            $only = AiKnowledgeSource::tryFrom((string) $option);

            if ($only === null) {
                $this->components->error('Unknown source. Use one of: '.implode(', ', array_column(AiKnowledgeSource::cases(), 'value')).'.');

                return self::FAILURE;
            }
        }

        $counts = $rebuild->handle($only, (int) config('chatbot.ai.knowledge.command_seconds', 20));

        Log::info('chat:index-knowledge', $counts);
        $this->components->info(sprintf(
            '%d source(s) checked, %d chunk(s) written, %d deleted, %d skipped, %d busy%s.',
            $counts['sources'],
            $counts['written'],
            $counts['deleted'],
            $counts['skipped'],
            $counts['busy'],
            $counts['complete'] ? '' : ' (time budget used, continues next run)',
        ));

        return self::SUCCESS;
    }
}
