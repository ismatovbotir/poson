<?php

namespace App\Jobs;

use App\Actions\Ai\ProcessAiRequest;
use App\Enums\AiErrorCode;
use App\Models\AiRequest;
use App\Services\Ai\TextTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one AI text request on the "ai" queue (plan 02 §1.4). Never retried
 * (tries = 1): a retry is a new request the manager starts. When the
 * worker kills the job (timeout) the request is marked failed "timeout".
 */
class RunAiRequest implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public int $timeout;

    public function __construct(public int $requestId, public TextTask $task)
    {
        // The provider call may be retried once by laravel/ai on a short Retry-After;
        // queue.retry_after must stay above this (see .env.example).
        $this->timeout = 2 * (int) config('ai-assist.timeout', 45) + 15;
    }

    public function handle(ProcessAiRequest $process): void
    {
        $process->handle($this->requestId, $this->task);
    }

    public function failed(?Throwable $exception): void
    {
        $request = AiRequest::query()->find($this->requestId);

        if ($request !== null) {
            app(ProcessAiRequest::class)->fail($request, AiErrorCode::Timeout);
        }
    }
}
