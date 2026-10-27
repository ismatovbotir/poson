<?php

namespace App\Livewire\Site\Articles;

use App\Enums\NewsKind;
use App\Livewire\Site\News\Index as NewsIndex;
use App\Models\NewsPost;
use App\Support\Catalog\CategoryTree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

/**
 * Knowledge articles list (decision 2026-10-24) at /{locale}/articles: the
 * news list for kind = article (evergreen guides, newest first, no RSS),
 * plus a chip row of the visible top-level categories that have articles in
 * the locale (linked to the category or one of its subcategories). A
 * category filter (?category=slug) narrows the list and gets noindex; the
 * canonical stays the unfiltered list and the pager keeps the filter. An
 * unknown slug, or one of a subcategory, is ignored: the full, indexable list.
 */
class Index extends NewsIndex
{
    /**
     * Fewest categories for the chip row to be worth showing.
     */
    public const MIN_FILTER_CHIPS = 2;

    #[Url(except: '')]
    public string $category = '';

    protected function kind(): NewsKind
    {
        return NewsKind::Article;
    }

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    /**
     * @return Builder<NewsPost>
     */
    protected function query(string $locale): Builder
    {
        $categoryId = $this->categoryId();

        return parent::query($locale)->when(
            $categoryId !== null,
            fn (Builder $query) => $query->whereHas('categories', fn (Builder $categories) => $categories->whereIn('categories.id', [$categoryId, ...CategoryTree::descendantIds($categoryId)])),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function queryParameters(): array
    {
        return $this->categoryId() !== null ? ['category' => $this->category] : [];
    }

    protected function filtered(): bool
    {
        return $this->categoryId() !== null;
    }

    /**
     * "All" plus the visible top-level categories that have articles in the
     * locale, in catalog order; one query.
     *
     * @return list<array{label: string, url: string, current: bool}>
     */
    protected function filters(): array
    {
        $linked = DB::table('category_news_post')
            ->whereIn('news_post_id', NewsPost::query()->ofKind(NewsKind::Article)->visibleIn(app()->getLocale())->select('news_posts.id'))
            ->distinct()
            ->pluck('category_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $roots = [];

        foreach ($linked as $id) {
            $ancestors = CategoryTree::ancestorIds($id);
            $roots[end($ancestors) ?: $id] = true;
        }

        $current = $this->categoryId();
        $chips = [];

        foreach (CategoryTree::visibleChildren(null) as $node) {
            if (isset($roots[$node['id']])) {
                $chips[] = [
                    'label' => CategoryTree::name($node['id']),
                    'url' => $this->kind()->listUrl().'?'.http_build_query(['category' => $node['slug']]),
                    'current' => $current === $node['id'],
                ];
            }
        }

        if (count($chips) < self::MIN_FILTER_CHIPS && $current === null) {
            return [];
        }

        return [
            ['label' => __('site.articles.all_categories'), 'url' => $this->kind()->listUrl(), 'current' => $current === null],
            ...$chips,
        ];
    }

    /**
     * The filtered category: a visible top-level category with that slug, else null.
     */
    protected function categoryId(): ?int
    {
        if ($this->category === '') {
            return null;
        }

        foreach (CategoryTree::visibleChildren(null) as $node) {
            if ($node['slug'] === $this->category) {
                return $node['id'];
            }
        }

        return null;
    }
}
