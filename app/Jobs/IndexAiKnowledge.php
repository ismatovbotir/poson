<?php

namespace App\Jobs;

use App\Actions\Chat\Knowledge\IndexKnowledgeSource;
use App\Enums\AiKnowledgeSource;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-index the knowledge chunks of one website source (plan 33 K-7, K-8,
 * K-16). Payload: the source type and id, nothing else (no text, so nothing
 * of the content in the queue table, failed_jobs or a log). Unique per source
 * until it starts processing, so a burst of saves queues one job, and a save
 * during the run queues the next. Own "knowledge" queue; the timeout stays
 * below the queue's retry_after.
 */
class IndexAiKnowledge implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $maxExceptions;

    public int $timeout;

    /**
     * Safety expiry of the unique lock (seconds).
     */
    public int $uniqueFor = 120;

    public function __construct(public string $source, public int $id)
    {
        $this->maxExceptions = (int) config('chatbot.ai.knowledge.job_tries', 3);
        $this->timeout = (int) config('chatbot.ai.knowledge.job_timeout', 60);
        $this->onQueue((string) config('chatbot.ai.knowledge.queue', 'knowledge'));
    }

    /**
     * Waiting for a source lock (release) is not a failure: the job may retry
     * for ten minutes, but fails after job_tries real exceptions.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    public function uniqueId(): string
    {
        return $this->source.':'.$this->id;
    }

    public function handle(IndexKnowledgeSource $index): void
    {
        $type = AiKnowledgeSource::tryFrom($this->source);

        if ($type === null) {
            return;
        }

        try {
            $index->handle($type, $this->id);
        } catch (LockTimeoutException) {
            $this->release(15);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('Knowledge index job failed', ['source' => $this->source, 'id' => $this->id, 'exception' => $exception::class]);
    }
}
