<?php

namespace App\Jobs;

use App\Support\Site\IndexNow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Sends one batch of URLs to IndexNow (plan 18). Carries URLs only: the key is
 * read when sending, so it is never in the queue payload, failed_jobs or a log.
 * Results are logged as status and count only. At most one such job is queued
 * or retrying at a time (the INFLIGHT flag set by indexnow:flush).
 */
class SubmitIndexNow implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @param  list<string>  $urls
     */
    public function __construct(public array $urls) {}

    /**
     * Ends the retries well before the in-flight flag (8 h) expires.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        if (! IndexNow::isActive()) {
            Cache::forget(IndexNow::INFLIGHT_KEY);

            return;
        }

        $count = count($this->urls);

        try {
            $response = IndexNow::send($this->urls);
        } catch (ConnectionException) {
            IndexNow::log($count, 'retry', 'connection');
            Log::warning('IndexNow request failed', ['reason' => 'connection', 'urls' => $count]);

            throw new RuntimeException('IndexNow connection failed.');
        }

        $status = $response->status();

        if (in_array($status, [200, 202], true)) {
            IndexNow::log($count, 'ok', $status);
            Cache::forget(IndexNow::INFLIGHT_KEY);

            return;
        }

        if ($status === 429) {
            $wait = max(60, min(3600, (int) ($response->header('Retry-After') ?: 600)));
            IndexNow::log($count, 'retry', $status);
            $this->release($wait);

            return;
        }

        if (in_array($status, [400, 403, 422], true) || ($status >= 300 && $status < 400)) {
            IndexNow::log($count, 'failed', $status);
            Log::warning('IndexNow rejected the batch', ['status' => $status, 'urls' => $count]);
            $this->fail(new RuntimeException('IndexNow rejected the batch: HTTP '.$status));

            return;
        }

        IndexNow::log($count, 'retry', $status);
        Log::warning('IndexNow request failed', ['status' => $status, 'urls' => $count]);

        throw new RuntimeException('IndexNow request failed: HTTP '.$status);
    }

    public function failed(?Throwable $exception = null): void
    {
        Cache::forget(IndexNow::INFLIGHT_KEY);
    }
}
