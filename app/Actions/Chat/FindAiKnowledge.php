<?php

namespace App\Actions\Chat;

use App\Actions\Chat\Knowledge\KnowledgeSources;
use App\Enums\AiKnowledgeSource;
use App\Models\AiKnowledgeChunk;
use App\Models\Page;
use App\Support\Chat\AiPrompt;
use App\Support\Search as SearchText;

/**
 * Website passages for one AI question (plan 33 §6, K-1, K-12).
 *
 *  - The words are FindAiProducts::words() (none: no query).
 *  - One query on ai_knowledge_chunks only: live() (locale, published, news
 *    age), any word as a bound LIKE on search_text
 *    (Search::normalizedPattern, escape '!'), the same bound patterns in a
 *    CASE score, best score, then priority, then newest. With 3+ words a
 *    hit must match at least 2.
 *  - The first chunk of the key-policy pages is already in the static
 *    knowledge block and is left out.
 *  - At most 2 chunks per source, then each source is checked again with
 *    the public scopes of KnowledgeSources (a brand or product depends on
 *    categories that fire no hook, K-1): a source that is not public right
 *    now never gets in.
 *  - At most chatbot.ai.knowledge.entries (4) entries within site_max (3,000)
 *    characters.
 * Reads no order, repair, customer, lead or message table and no price level.
 */
class FindAiKnowledge
{
    private const FETCH = 24;

    public function __construct(private readonly KnowledgeSources $sources) {}

    /**
     * @return list<AiKnowledgeHit>
     */
    public function handle(string $text, string $locale): array
    {
        if (! in_array($locale, (array) config('app.supported_locales'), true)) {
            return [];
        }

        $words = FindAiProducts::words($text);

        if ($words === []) {
            return [];
        }

        $rows = $this->rows($words, $locale);

        return $rows === [] ? [] : $this->hits($this->recheck($this->perSource($rows), $locale), $locale);
    }

    /**
     * @param  list<string>  $words
     * @return list<AiKnowledgeChunk>
     */
    private function rows(array $words, string $locale): array
    {
        $patterns = array_map(fn (string $word): string => SearchText::normalizedPattern($word), $words);
        $score = implode(' + ', array_fill(0, count($patterns), "(case when search_text like ? escape '!' then 1 else 0 end)"));

        $rows = AiKnowledgeChunk::query()
            ->live($locale)
            ->selectRaw("id, source_type, source_id, locale, chunk, title, text, priority, indexed_at, ({$score}) as score", $patterns)
            ->where(function ($query) use ($patterns): void {
                foreach ($patterns as $pattern) {
                    $query->orWhereRaw("search_text like ? escape '!'", [$pattern]);
                }
            })
            ->orderByDesc('score')
            ->orderByDesc('priority')
            ->orderByDesc('indexed_at')
            ->limit(self::FETCH)
            ->get()
            ->all();

        $minimum = count($words) >= 3 ? 2 : 1;
        $rows = array_values(array_filter($rows, fn (AiKnowledgeChunk $row): bool => (int) $row->getAttribute('score') >= $minimum));
        $pageIds = array_map(fn (AiKnowledgeChunk $row): int => $row->source_id, array_filter($rows, fn (AiKnowledgeChunk $row): bool => $row->source_type === AiKnowledgeSource::Page && $row->chunk === 0));

        if ($pageIds === []) {
            return $rows;
        }

        $keyIds = Page::query()->whereIn('id', $pageIds)->whereIn('slug', BuildAiKnowledge::KEY_POLICY_SLUGS)->pluck('id')->all();

        return array_values(array_filter($rows, fn (AiKnowledgeChunk $row): bool => ! ($row->source_type === AiKnowledgeSource::Page && $row->chunk === 0 && in_array($row->source_id, $keyIds, true))));
    }

    /**
     * @param  list<AiKnowledgeChunk>  $rows
     * @return list<AiKnowledgeChunk>
     */
    private function perSource(array $rows): array
    {
        $count = [];
        $kept = [];

        foreach ($rows as $row) {
            $key = $row->source_type->value.':'.$row->source_id;
            $count[$key] = ($count[$key] ?? 0) + 1;

            if ($count[$key] <= 2) {
                $kept[] = $row;
            }
        }

        return $kept;
    }

    /**
     * Keep the rows whose source is public now (K-1) and build the hits.
     *
     * @param  list<AiKnowledgeChunk>  $rows
     * @return list<array{0: AiKnowledgeChunk, 1: string}> row and slug
     */
    private function recheck(array $rows, string $locale): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $ids[$row->source_type->value][] = $row->source_id;
        }

        $live = [];

        foreach ($ids as $type => $list) {
            $live[$type] = $this->sources->live(AiKnowledgeSource::from($type), $list, $locale);
        }

        $kept = [];

        foreach ($rows as $row) {
            $slug = $live[$row->source_type->value][$row->source_id] ?? null;

            if ($slug !== null) {
                $kept[] = [$row, $slug];
            }
        }

        return $kept;
    }

    /**
     * @param  list<array{0: AiKnowledgeChunk, 1: string}>  $rows
     * @return list<AiKnowledgeHit>
     */
    private function hits(array $rows, string $locale): array
    {
        $hits = [];
        $length = 0;
        $max = (int) config('chatbot.ai.knowledge.site_max', 3000);

        foreach ($rows as [$row, $slug]) {
            if (count($hits) >= (int) config('chatbot.ai.knowledge.entries', 4)) {
                break;
            }

            $url = $this->sources->url($row->source_type, $slug, $locale);
            $title = AiPrompt::field($row->title, (int) config('chatbot.ai.knowledge.title_max', 200));
            $text = AiPrompt::field($row->text, (int) config('chatbot.ai.knowledge.chunk_max', 1200));
            $hit = new AiKnowledgeHit($row->source_type, $row->source_id, $locale, $title, $text, $url);
            $size = mb_strlen($hit->entry(count($hits) + 1, $text)) + 1;

            if ($length + $size > $max) {
                $room = $max - $length - ($size - mb_strlen($text));

                if ($room < 200) {
                    break;
                }

                $hit = new AiKnowledgeHit($row->source_type, $row->source_id, $locale, $title, AiPrompt::field($text, $room), $url);
                $size = $max - $length;
            }

            $hits[] = $hit;
            $length += $size;
        }

        return $hits;
    }

    /**
     * The `<site>` block of the prompt, or '' without hits.
     *
     * @param  list<AiKnowledgeHit>  $hits
     */
    public static function block(array $hits): string
    {
        if ($hits === []) {
            return '';
        }

        $entries = [];

        foreach ($hits as $index => $hit) {
            $entries[] = $hit->entry($index + 1, $hit->text);
        }

        return "<site>\n".implode("\n", $entries)."\n</site>";
    }

    /**
     * Ids of the service and software products among the hits (candidates for <products>, K-13).
     *
     * @param  list<AiKnowledgeHit>  $hits
     * @return list<int>
     */
    public static function productIds(array $hits): array
    {
        $ids = [];

        foreach ($hits as $hit) {
            if ($hit->type->productType() !== null) {
                $ids[$hit->sourceId] = true;
            }
        }

        return array_map('intval', array_keys($ids));
    }
}
