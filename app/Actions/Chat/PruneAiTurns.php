<?php

namespace App\Actions\Chat;

use App\Models\ChatAiTurn;
use Illuminate\Support\Facades\Log;

/**
 * Deletes AI turns older than chatbot.ai.retention_days (30) (plan 30 §4,
 * AI-21, AI-B12, CB-25). Chunked by id, so a large backlog never holds one
 * long lock; an assistant row goes with its customer turn (answers_turn_id
 * cascades). The unmatched phrases they link to stay. Logs counts only.
 */
class PruneAiTurns
{
    public const CHUNK = 1000;

    public function handle(): int
    {
        $cutoff = now()->subDays(max(1, (int) config('chatbot.ai.retention_days', 30)));
        $deleted = 0;

        do {
            $ids = ChatAiTurn::query()->where('created_at', '<', $cutoff)->orderBy('id')->limit(self::CHUNK)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += ChatAiTurn::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::CHUNK);

        Log::info('chatbot.ai.pruned', ['deleted' => $deleted]);

        return $deleted;
    }
}
