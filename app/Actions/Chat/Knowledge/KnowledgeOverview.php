<?php

namespace App\Actions\Chat\Knowledge;

use App\Enums\AiKnowledgeSource;
use App\Models\AiKnowledgeChunk;
use App\Models\Category;
use App\Models\FaqItem;
use App\Models\Page;
use App\Support\Chat\KnowledgeStats;
use App\Support\Chat\WorkingHours;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * What the Settings card "Website knowledge" shows (plan 33 §8, K-4): chunk
 * count, last indexing time, the skip counters and the last 20 indexed
 * sources with an admin link (a FAQ item links to its owner's edit screen,
 * plan 33 A2-S11). Counts and titles of public content only.
 */
class KnowledgeOverview
{
    /**
     * @return array{chunks: int, last_indexed_at: ?Carbon, skipped: array<string, int>, recent: list<array{type: string, title: string, url: ?string}>}
     */
    public function handle(): array
    {
        $last = AiKnowledgeChunk::query()->max('indexed_at');

        $recent = AiKnowledgeChunk::query()
            ->selectRaw('source_type, source_id, max(indexed_at) as indexed')
            ->groupBy('source_type', 'source_id')
            ->orderByDesc('indexed')
            ->orderByDesc('source_id')
            ->limit(20)
            ->get();

        $titles = $this->titles($recent);
        $faqOwners = $this->faqOwners($recent);

        return [
            'chunks' => AiKnowledgeChunk::query()->count(),
            'last_indexed_at' => $last === null ? null : Carbon::parse($last, 'UTC')->setTimezone(WorkingHours::TIMEZONE),
            'skipped' => KnowledgeStats::skipCounts(),
            'recent' => $recent->map(fn (AiKnowledgeChunk $row): array => [
                'type' => $row->source_type->label(),
                'title' => $titles[$row->source_type->value.':'.$row->source_id] ?? '',
                'url' => $this->adminUrl($row->source_type, $row->source_id, $faqOwners),
            ])->all(),
        ];
    }

    /**
     * The title of each source from its first chunk, in the admin's language
     * (uz when that locale has none).
     *
     * @param  Collection<int, AiKnowledgeChunk>  $recent
     * @return array<string, string>
     */
    private function titles(Collection $recent): array
    {
        if ($recent->isEmpty()) {
            return [];
        }

        $locale = app()->getLocale();
        $titles = [];

        $rows = AiKnowledgeChunk::query()
            ->where('chunk', 0)
            ->whereIn('source_id', $recent->pluck('source_id')->all())
            ->whereIn('locale', array_unique([$locale, 'uz']))
            ->get(['source_type', 'source_id', 'locale', 'title']);

        foreach ($rows->sortBy(fn (AiKnowledgeChunk $row): int => $row->locale === $locale ? 1 : 0) as $row) {
            $titles[$row->source_type->value.':'.$row->source_id] = $row->title;
        }

        return $titles;
    }

    /**
     * The owner (morph type, id) of each listed FAQ item, in one query.
     *
     * @param  Collection<int, AiKnowledgeChunk>  $recent
     * @return array<int, array{0: ?string, 1: ?int}>
     */
    private function faqOwners(Collection $recent): array
    {
        $ids = $recent->filter(fn (AiKnowledgeChunk $row): bool => $row->source_type === AiKnowledgeSource::Faq)->pluck('source_id')->all();

        if ($ids === []) {
            return [];
        }

        return FaqItem::query()->whereKey($ids)->get(['id', 'faqable_type', 'faqable_id'])
            ->mapWithKeys(fn (FaqItem $item): array => [$item->id => [$item->faqable_type, $item->faqable_id]])
            ->all();
    }

    /**
     * @param  array<int, array{0: ?string, 1: ?int}>  $faqOwners
     */
    private function adminUrl(AiKnowledgeSource $type, int $id, array $faqOwners = []): ?string
    {
        return match ($type) {
            AiKnowledgeSource::Page => route('admin.pages.edit', $id),
            AiKnowledgeSource::News => route('admin.news.edit', $id),
            AiKnowledgeSource::Article => route('admin.articles.edit', $id),
            AiKnowledgeSource::Brand => route('admin.brands.edit', $id),
            AiKnowledgeSource::Service, AiKnowledgeSource::Software => route('admin.products.edit', $id),
            AiKnowledgeSource::Faq => $this->faqOwnerUrl($faqOwners[$id] ?? [null, null]),
        };
    }

    /**
     * @param  array{0: ?string, 1: ?int}  $owner
     */
    private function faqOwnerUrl(array $owner): ?string
    {
        [$type, $id] = $owner;

        return match (true) {
            $id === null => null,
            $type === (new Category)->getMorphClass() => route('admin.categories.edit', $id),
            $type === (new Page)->getMorphClass() => route('admin.pages.edit', $id),
            default => null,
        };
    }
}
