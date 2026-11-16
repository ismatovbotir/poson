<?php

namespace App\Actions\Catalog\Ai;

use App\Enums\AiDraftStatus;
use App\Models\AiProductDraft;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * The per-draft lock of the AI product wizard (plan 32 PW-8, PW-20, M4):
 * every write of the draft's "input" JSON (image add / flags), a stage
 * start and the create / apply run inside it, each re-reading the row
 * first, so no read-modify-write works on a stale copy. Lock order: the
 * user's start lock (StartAiDraftStage) → this lock → DB rows (draft →
 * product); nothing holding this lock takes the user's lock.
 */
final class AiDraftLock
{
    /**
     * Statuses in which the draft's images and info may not change.
     *
     * @return list<string>
     */
    public static function frozen(): array
    {
        return AiDraftStatus::values([...AiDraftStatus::pending(), AiDraftStatus::Applying, AiDraftStatus::Applied, AiDraftStatus::Expired]);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws LockTimeoutException
     */
    public static function run(int $draftId, Closure $callback, int $wait = 10): mixed
    {
        return Cache::lock("ai-draft:apply:{$draftId}", 300)->block($wait, $callback);
    }

    /**
     * An apply has begun on this draft (N5): a create draft that already made
     * its product, or any draft with an apply_state. Such a draft is never
     * edited or started again (only "Open product" / "Discard").
     */
    public static function applyStarted(AiProductDraft $draft): bool
    {
        return $draft->apply_state !== null || (! $draft->isImprove() && $draft->product_id !== null);
    }

    /**
     * Store a new "input" for a draft that is not frozen and has no apply
     * begun (one conditional UPDATE). MySQL reports CHANGED rows, so an
     * identical write in the same second returns 0 (N7): then the row is
     * re-read and the write counts when the row still matches the conditions
     * and already holds exactly this input (key order aside).
     *
     * @param  array<string, mixed>  $input
     */
    public static function writeInput(AiProductDraft $draft, array $input): bool
    {
        $encoded = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $updated = self::writable($draft)->update(['input' => $encoded, 'updated_at' => now()]);

        if ($updated === 1) {
            return true;
        }

        $fresh = self::writable($draft)->first();

        return $fresh !== null && self::sameJson($fresh->input, $input);
    }

    /**
     * Two decoded JSON values hold the same data (MySQL's json type reorders object keys).
     */
    public static function sameJson(mixed $stored, mixed $expected): bool
    {
        return self::canonical($stored) === self::canonical($expected);
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }

    /**
     * The draft's row while its input may change: owner, not frozen, no apply begun.
     *
     * @return Builder<AiProductDraft>
     */
    private static function writable(AiProductDraft $draft): Builder
    {
        return AiProductDraft::query()->whereKey($draft->id)->where('user_id', $draft->user_id)
            ->whereNotIn('status', self::frozen())
            ->whereNull('apply_state')
            ->where(fn (Builder $query) => $query->where('mode', AiProductDraft::IMPROVE)->orWhereNull('product_id'));
    }
}
