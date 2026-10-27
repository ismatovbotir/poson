<?php

namespace App\Livewire\Site\News;

use App\Enums\NewsKind;
use App\Models\NewsPost;
use App\Support\LocaleUrl;
use App\Support\Site\NewsCards;
use App\Support\Site\NewsDirectory;
use App\Support\Site\StructuredData;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\Livewire;
use Livewire\WithPagination;

/**
 * News list (plan 07 7b) at /{locale}/news: posts of kind() visible in the
 * current locale (published, date passed, title and body translated),
 * newest first, with cover, date and excerpt; paginated. Empty: a short
 * notice and noindex. hreflang lists only the locales with visible posts of
 * the kind. The news list links the locale's RSS feed (articles have none).
 * BreadcrumbList JSON-LD. App\Livewire\Site\Articles\Index is the articles
 * list (kind = article, with a category filter).
 */
class Index extends Component
{
    use WithPagination;

    protected function kind(): NewsKind
    {
        return NewsKind::News;
    }

    /**
     * The visible posts of the kind in the locale, newest first.
     *
     * @return Builder<NewsPost>
     */
    protected function query(string $locale): Builder
    {
        return NewsPost::query()
            ->ofKind($this->kind())
            ->visibleIn($locale)
            ->with('media')
            ->latestFirst();
    }

    /**
     * Filter chips above the list (none for news).
     *
     * @return list<array{label: string, url: string, current: bool}>
     */
    protected function filters(): array
    {
        return [];
    }

    /**
     * Query parameters kept in the pager links (the active filter).
     *
     * @return array<string, string>
     */
    protected function queryParameters(): array
    {
        return [];
    }

    /**
     * A filter is applied (the list then gets noindex).
     */
    protected function filtered(): bool
    {
        return false;
    }

    public function render(StructuredData $structuredData): View
    {
        $locale = app()->getLocale();
        $kind = $this->kind();

        $posts = $this->query($locale)
            ->paginate((int) config('site.news_per_page', 12))
            ->withPath(Livewire::originalUrl())
            ->appends($this->queryParameters());

        // A page past the end doesn't exist.
        abort_if($posts->currentPage() > max(1, $posts->lastPage()), 404);

        // hreflang: only the locales that have visible posts of the kind (the others are empty, noindex lists).
        LocaleUrl::restrictTo(
            array_values(array_filter(config('app.supported_locales'), fn (string $code): bool => NewsDirectory::hasVisible($code, $kind))),
            fn (string $other): string => $kind->listUrl($other),
        );

        $url = $kind->listUrl();
        $breadcrumbs = [
            ['label' => __('site.breadcrumbs.home'), 'url' => route('site.home')],
            ['label' => $kind->siteText('title'), 'url' => null],
        ];

        return view('livewire.site.news.index', [
            'kind' => $kind,
            'posts' => $posts,
            'cards' => NewsCards::fromPosts($posts->items()),
            'filters' => $this->filters(),
            'breadcrumbs' => $breadcrumbs,
            'rssUrl' => $kind === NewsKind::News ? route('site.news.rss') : null,
            'jsonLd' => [StructuredData::toScript($structuredData->breadcrumbs($breadcrumbs, $url))],
        ])
            ->title($kind->siteText('seo_title'))
            ->layoutData(array_filter([
                'description' => $kind->siteText('seo_description'),
                'robots' => $posts->isEmpty() || $this->filtered() ? 'noindex,follow' : null,
            ]));
    }
}
