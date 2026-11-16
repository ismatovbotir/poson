<?php

namespace App\Jobs;

use App\Actions\Chat\Knowledge\QueueKnowledgeRebuild;
use App\Actions\Chat\Knowledge\RebuildAiKnowledge;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Rebuild now" of the website knowledge (plan 33 K-10, K-11): the full
 * incremental walk of RebuildAiKnowledge on the "knowledge" queue, in hops:
 * each run has its own time budget (below the job timeout and the queue's
 * retry_after) and its own cursor, and queues the next hop of this same
 * unique job while the walk is not complete. The rebuild lock is renewed on
 * every hop. No payload at all. Logs counts only.
 */
class RebuildAiKnowledgeIndex implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const CURSOR_KEY = 'chatbot:knowledge:rebuild:cursor';

    public const BUDGET_SECONDS = 60;

    public int $tries = 1;

    public int $timeout = 80;

    public int $uniqueFor = 300;

    public function __construct()
    {
        $this->onQueue((string) config('chatbot.ai.knowledge.queue', 'knowledge'));
    }

    public function uniqueId(): string
    {
        return 'ai-knowledge-rebuild';
    }

    public function handle(RebuildAiKnowledge $rebuild): void
    {
        $counts = $rebuild->handle(null, self::BUDGET_SECONDS, self::CURSOR_KEY);

        Log::info('Knowledge rebuild', $counts);

        if (! $counts['complete']) {
            Cache::put(QueueKnowledgeRebuild::LOCK_KEY, 1, QueueKnowledgeRebuild::LOCK_SECONDS);
            self::dispatch()->delay(now()->addSeconds(5));
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('Knowledge rebuild failed', ['exception' => $exception::class]);
    }
}
