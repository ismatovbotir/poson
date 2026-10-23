<?php

namespace App\Actions\Ai;

use App\Models\AiRequest;

/**
 * Retention (plan 02 §4): prompt and response texts of requests older than
 * config('ai-assist.retention_days') (90) are cleared; the row with its
 * counts, cost and status stays for the usage statistics. Returns the
 * number of rows cleared.
 */
class PruneAiRequests
{
    public function handle(?int $days = null): int
    {
        $days ??= (int) config('ai-assist.retention_days', 90);

        return AiRequest::query()
            ->where('created_at', '<', now()->subDays($days))
            ->where(fn ($query) => $query->whereNotNull('prompt')->orWhereNotNull('response'))
            ->update(['prompt' => null, 'response' => null, 'pruned_at' => now()]);
    }
}
