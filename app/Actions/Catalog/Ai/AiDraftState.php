<?php

namespace App\Actions\Catalog\Ai;

use App\Enums\AiDraftStatus;
use App\Models\AiProductDraft;
use Illuminate\Support\Carbon;

/**
 * The AI draft state machine (plan 32 PW-8): every status change is ONE
 * conditional UPDATE ("… WHERE id = ? AND status IN (…)"), so two racing
 * requests or a cancel and a finishing job can never both win.
 *
 * Stale stages (M2): a seeing / writing row not touched for longer than the
 * job timeout + the queue's retry_after + a margin has no live job any more
 * (no worker on the ai-admin queue, a crashed worker): it no longer counts
 * as pending and the poll / the prune move it to failed ("error").
 */
final class AiDraftState
{
    /**
     * @param  list<AiDraftStatus>  $from
     * @param  array<string, mixed>  $values  other columns to set (arrays for the json columns are encoded here)
     */
    public static function move(int $draftId, array $from, AiDraftStatus $to, array $values = [], ?int $userId = null): bool
    {
        $columns = ['status' => $to->value, 'updated_at' => now()];

        foreach ($values as $column => $value) {
            $columns[$column] = in_array($column, ['input', 'facts', 'result', 'snapshot', 'apply_state'], true) && $value !== null
                ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $value;
        }

        return AiProductDraft::query()
            ->whereKey($draftId)
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->whereIn('status', AiDraftStatus::values($from))
            ->update($columns) === 1;
    }

    /**
     * Seconds after which a pending stage is considered lost.
     */
    public static function staleSeconds(): int
    {
        $connection = (string) config('queue.default');
        $retryAfter = (int) config("queue.connections.{$connection}.retry_after", 300);
        $job = max((int) config('chatbot.ai.product.vision.job_timeout', 60), (int) config('chatbot.ai.product.write.job_timeout', 150));

        return $job + max(0, $retryAfter) + 60;
    }

    public static function staleBefore(): Carbon
    {
        return now()->subSeconds(self::staleSeconds());
    }

    /**
     * A pending draft whose stage is lost.
     */
    public static function isStale(AiProductDraft $draft): bool
    {
        return $draft->status->isPending() && ($draft->updated_at === null || $draft->updated_at->lt(self::staleBefore()));
    }

    /**
     * Move lost stages (one draft or all) to failed with the error key "error".
     */
    public static function failStale(?int $draftId = null): int
    {
        return AiProductDraft::query()
            ->when($draftId !== null, fn ($query) => $query->whereKey($draftId))
            ->whereIn('status', AiDraftStatus::values(AiDraftStatus::pending()))
            ->where(fn ($query) => $query->whereNull('updated_at')->orWhere('updated_at', '<', self::staleBefore()))
            ->update(['status' => AiDraftStatus::Failed->value, 'error' => 'error', 'updated_at' => now()]);
    }
}
