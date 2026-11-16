<?php

namespace App\Actions\Chat\Knowledge;

use App\Enums\AiKnowledgeSource;
use App\Models\AiKnowledgeChunk;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Walks the visible sources and re-indexes each (plan 33 K-10): chunks whose
 * source is gone, hidden or too old are deleted first (a source that is not
 * visible indexes to nothing), then every visible source goes through
 * IndexKnowledgeSource, which writes only what changed. With a time budget
 * (the hourly command) it stops when the budget is used and remembers where
 * (a cursor in the cache), so the next run continues there; without one (a
 * queued rebuild) it does everything. Counts only, never text.
 */
class RebuildAiKnowledge
{
    public const CURSOR_KEY = 'chatbot:knowledge:cursor';

    public function __construct(private readonly KnowledgeSources $sources, private readonly IndexKnowledgeSource $index) {}

    /**
     * @param  ?AiKnowledgeSource  $only  One source type, or all
     * @param  ?int  $seconds  Time budget (null = no limit). Only a budgeted full run uses and moves the cursor
     * @param  string  $cursorKey  Where that cursor lives (the hourly command and the queued rebuild keep their own)
     * @return array{sources: int, written: int, deleted: int, skipped: int, busy: int, complete: bool}
     */
    public function handle(?AiKnowledgeSource $only = null, ?int $seconds = null, string $cursorKey = self::CURSOR_KEY): array
    {
        $deadline = $seconds === null ? null : microtime(true) + $seconds;
        $totals = ['sources' => 0, 'written' => 0, 'deleted' => 0, 'skipped' => 0, 'busy' => 0, 'complete' => true];
        $types = $only === null ? AiKnowledgeSource::cases() : [$only];
        $resumable = $only === null && $seconds !== null;
        $cursor = $resumable ? $this->cursor($cursorKey) : null;
        $found = $cursor === null ? false : array_search($cursor['type'], $types, true);
        $resumeAt = $found === false ? 0 : (int) $found;
        $cursor = $found === false ? null : $cursor;

        foreach ($types as $position => $type) {
            if ($position < $resumeAt) {
                continue;
            }

            $after = $cursor !== null && $position === $resumeAt ? $cursor['id'] : 0;
            $visible = $this->sources->query($type)->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            $listed = array_flip($visible);
            $todo = $after === 0 ? [...$this->orphans($type, $listed), ...$visible] : array_values(array_filter($visible, fn (int $id): bool => $id > $after));

            foreach ($todo as $id) {
                if ($deadline !== null && microtime(true) >= $deadline) {
                    if ($resumable) {
                        Cache::forever($cursorKey, $type->value.':'.$after);
                    }

                    $totals['complete'] = false;

                    return $totals;
                }

                try {
                    $counts = $this->index->handle($type, $id, 1);
                } catch (LockTimeoutException) {
                    $totals['busy']++;

                    continue;
                }

                $totals['sources']++;
                $totals['written'] += $counts['written'];
                $totals['deleted'] += $counts['deleted'];
                $totals['skipped'] += $counts['skipped'];
                $after = isset($listed[$id]) ? $id : $after;
            }
        }

        if ($resumable) {
            Cache::forget($cursorKey);
        }

        return $totals;
    }

    /**
     * Source ids that still have chunks but are no longer visible.
     *
     * @param  array<int, int>  $visible  Visible ids as keys
     * @return list<int>
     */
    private function orphans(AiKnowledgeSource $type, array $visible): array
    {
        $known = AiKnowledgeChunk::query()->where('source_type', $type->value)->distinct()->pluck('source_id')->map(fn (mixed $id): int => (int) $id)->all();

        return array_values(array_filter($known, fn (int $id): bool => ! isset($visible[$id])));
    }

    /**
     * @return array{type: AiKnowledgeSource, id: int}|null
     */
    private function cursor(string $key): ?array
    {
        $value = Cache::get($key);

        if (! is_string($value) || ! str_contains($value, ':')) {
            return null;
        }

        [$type, $id] = explode(':', $value, 2);
        $type = AiKnowledgeSource::tryFrom($type);

        return $type === null ? null : ['type' => $type, 'id' => (int) $id];
    }
}
