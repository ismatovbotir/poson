<?php

namespace App\Actions\Chat\Knowledge;

use App\Actions\Chat\BuildAiKnowledge;
use App\Enums\AiKnowledgeSource;
use App\Models\AiKnowledgeChunk;
use App\Support\Chat\AiOutputFilter;
use App\Support\Chat\AiPrompt;
use App\Support\Chat\KnowledgeStats;
use App\Support\Chat\KnowledgeText;
use App\Support\Search;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Brings the chunks of one source in line with the website (plan 33 §4, K-1
 * to K-4, K-8): reads the source through the public scopes only, turns each
 * locale into prompt-safe chunks, masks money amounts (fails closed: a chunk
 * the AI output filter still dislikes, or one that sounds like an
 * instruction, is skipped and counted by reason) and writes the result in one
 * transaction: upsert changed chunks, delete extra chunks and the locales
 * that are no longer public. Unchanged chunks are not written. A source that
 * is not visible loses all its chunks. One Cache::lock per source is shared
 * by the job and the command. Writes only ai_knowledge_chunks (never a
 * source model) and makes no HTTP call.
 */
class IndexKnowledgeSource
{
    public const LOCK_SECONDS = 120;

    public function __construct(
        private readonly KnowledgeSources $sources,
        private readonly BuildAiKnowledge $knowledge,
    ) {}

    /**
     * @return array{written: int, deleted: int, skipped: int}
     *
     * @throws LockTimeoutException when another run holds the source for more than $wait seconds
     */
    public function handle(AiKnowledgeSource $type, int $id, int $wait = 10): array
    {
        $lock = Cache::lock(self::lockKey($type, $id), self::LOCK_SECONDS);
        $lock->block($wait);

        try {
            return $this->index($type, $id);
        } catch (QueryException $e) {
            // K-16: a database error message carries the SQL bindings (the chunk text): keep only the class.
            throw new RuntimeException('Knowledge index write failed ('.$e::class.').');
        } finally {
            $lock->release();
        }
    }

    public static function lockKey(AiKnowledgeSource $type, int $id): string
    {
        return "ai-knowledge:{$type->value}:{$id}";
    }

    /**
     * @return array{written: int, deleted: int, skipped: int}
     */
    private function index(AiKnowledgeSource $type, int $id): array
    {
        $rows = [];
        $skips = [];
        $filter = $this->knowledge->outputFilter();

        foreach ($this->sources->documents($type, $id) as $document) {
            $this->rowsFor($type, $id, $document, $filter, $rows, $skips);
        }

        $counts = $this->persist($type, $id, $rows);
        KnowledgeStats::recordSkips("{$type->value}:{$id}", $skips);

        if ($counts['written'] + $counts['deleted'] > 0) {
            KnowledgeStats::bump();
        }

        return $counts + ['skipped' => count($skips)];
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows  Keyed by "locale|chunk"
     * @param  list<string>  $skips
     */
    private function rowsFor(AiKnowledgeSource $type, int $id, KnowledgeDocument $document, AiOutputFilter $filter, array &$rows, array &$skips): void
    {
        $mask = (string) __('chatbot.knowledge.amount_masked', [], $document->locale);
        $title = KnowledgeText::maskPercents(KnowledgeText::clean($document->title, (int) config('chatbot.ai.knowledge.title_max', 200)), $mask);
        $body = KnowledgeText::maskPercents(KnowledgeText::fromHtml($document->body, (int) config('chatbot.ai.knowledge.source_max', 8000)), $mask);

        if ($title === '' || $body === '') {
            return;
        }

        $titleReason = $this->reason($title, $mask, $filter);
        $position = 0;

        foreach (KnowledgeText::chunks($body, (int) config('chatbot.ai.knowledge.chunk_max', 1200), (int) config('chatbot.ai.knowledge.chunks_max', 6)) as $raw) {
            $reason = $titleReason ?? $this->reason($raw, $mask, $filter);

            if ($reason !== null) {
                $skips[] = $reason;

                continue;
            }

            // The mask phrase can be longer than what it replaces: cut again after masking (varchar 200, chunk_max).
            $text = mb_substr(KnowledgeText::maskAmounts($raw, $mask), 0, (int) config('chatbot.ai.knowledge.chunk_max', 1200));
            $maskedTitle = AiPrompt::field(KnowledgeText::maskAmounts($title, $mask), (int) config('chatbot.ai.knowledge.title_max', 200));

            if (! mb_check_encoding($text, 'UTF-8') || ! mb_check_encoding($maskedTitle, 'UTF-8')) {
                $skips[] = 'encoding';

                continue;
            }

            $visibleFrom = $document->visibleFrom?->copy()->utc()->format('Y-m-d H:i:s');

            $rows["{$document->locale}|{$position}"] = [
                'source_type' => $type->value,
                'source_id' => $id,
                'locale' => $document->locale,
                'chunk' => $position,
                'title' => $maskedTitle,
                'url' => mb_substr($document->url, 0, 255),
                'text' => $text,
                'search_text' => Search::normalize(str_replace($mask, ' ', $maskedTitle.' '.$text)),
                'priority' => $type->priority(),
                'visible_from' => $visibleFrom,
                'content_hash' => hash('sha256', implode("\n", [$maskedTitle, $document->url, $text, $type->priority(), $visibleFrom])),
            ];
            $position++;
        }
    }

    /**
     * Why a text may not be knowledge, or null (K-3, K-4). The unmasked
     * text is judged first (a phone number must never be half-masked as an
     * amount); then the masked text must pass the full filter.
     * Reasons: instruction_like, contact, link, brand_unsafe, amount.
     */
    private function reason(string $text, string $mask, AiOutputFilter $filter): ?string
    {
        if (KnowledgeText::looksLikeInstruction($text)) {
            return 'instruction_like';
        }

        $reason = $filter->checkWithoutMoney($text) ?? $filter->check(KnowledgeText::maskAmounts($text, $mask));

        return $reason === null ? null : match ($reason) {
            'link' => 'link',
            'contact', 'phone', 'card' => 'contact',
            'money' => 'amount',
            default => 'brand_unsafe',
        };
    }

    /**
     * Upsert what changed, delete the rest, in one transaction (K-8).
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @return array{written: int, deleted: int}
     */
    private function persist(AiKnowledgeSource $type, int $id, array $rows): array
    {
        return DB::transaction(function () use ($type, $id, $rows): array {
            $existing = AiKnowledgeChunk::query()
                ->where('source_type', $type->value)
                ->where('source_id', $id)
                ->get(['id', 'locale', 'chunk', 'content_hash'])
                ->keyBy(fn (AiKnowledgeChunk $chunk): string => "{$chunk->locale}|{$chunk->chunk}");

            $now = now()->utc()->format('Y-m-d H:i:s');
            $changed = [];

            foreach ($rows as $key => $row) {
                if (($existing[$key]->content_hash ?? null) !== $row['content_hash']) {
                    $changed[] = $row + ['indexed_at' => $now];
                }
            }

            if ($changed !== []) {
                AiKnowledgeChunk::query()->upsert(
                    $changed,
                    ['source_type', 'source_id', 'locale', 'chunk'],
                    ['title', 'url', 'text', 'search_text', 'priority', 'visible_from', 'content_hash', 'indexed_at'],
                );
            }

            $stale = $existing->diffKeys($rows)->pluck('id')->all();

            if ($stale !== []) {
                AiKnowledgeChunk::query()->whereKey($stale)->delete();
            }

            return ['written' => count($changed), 'deleted' => count($stale)];
        });
    }
}
