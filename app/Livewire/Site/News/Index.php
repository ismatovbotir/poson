<?php

namespace App\Livewire\Site\News;

use App\Models\NewsPost;
use App\Support\LocaleUrl;
use App\Support\Site\NewsCards;
use App\Support\Site\NewsDirectory;
use App\Support\Site\StructuredData;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\Livewire;
use Livewire\WithPagination;

/**
 * News list (plan 07 7b) at /{locale}/news: posts visible in the current
 * locale (published, date passed, title and body translated), newest first,
 * with cover, date and excerpt; paginated. Empty: a short notice and
 * noindex. hreflang lists only the locales with visible news. Links the
 * locale's RSS feed. BreadcrumbList JSON-LD.
 */
class Index extends Component
{
    use WithPagination;

    public function render(StructuredData $structuredData): View
    {
        $locale = app()->getLocale();

        $posts = NewsPost::query()
            ->visibleIn($locale)
            ->with('media')
            ->latestFirst()
            ->paginate((int) config('site.news_per_page', 12))
            ->withPath(Livewire::originalUrl());

        // A page past the end doesn't exist.
        abort_if($posts->currentPage() > max(1, $posts->lastPage()), 404);

        // hreflang: only the locales that have visible news (the others are empty, noindex lists).
        LocaleUrl::restrictTo(
            array_values(array_filter(config('app.supported_locales'), fn (string $code): bool => NewsDirectory::hasVisible($code))),
            fn (string $other): string => route('site.news.index', ['locale' => $other]),
        );

        $url = route('site.news.index');
        $breadcrumbs = [
            ['label' => __('site.breadcrumbs.home'), 'url' => route('site.home')],
            ['label' => __('site.news.title'), 'url' => null],
        ];

        return view('livewire.site.news.index', [
            'posts' => $posts,
            'cards' => NewsCards::fromPosts($posts->items()),
            'breadcrumbs' => $breadcrumbs,
            'rssUrl' => route('site.news.rss'),
            'jsonLd' => [StructuredData::toScript($structuredData->breadcrumbs($breadcrumbs, $url))],
        ])
            ->title(__('site.news.seo_title'))
            ->layoutData(array_filter([
                'description' => __('site.news.seo_description'),
                'robots' => $posts->isEmpty() ? 'noindex,follow' : null,
            ]));
    }
}
