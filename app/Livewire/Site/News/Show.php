<?php

namespace App\Livewire\Site\News;

use App\Data\Site\ProductCard;
use App\Models\NewsPost;
use App\Models\Product;
use App\Support\Catalog\CategoryTree;
use App\Support\Catalog\ProductCards;
use App\Support\HtmlSanitizer;
use App\Support\LocaleUrl;
use App\Support\Site\SeoText;
use App\Support\Site\StructuredData;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * News post (plan 07 7b) at /{locale}/news/{slug}: 404 unless visible
 * (published, date passed) and translated in the current locale (title and
 * body; no fallback to uz). Cover, date, body, related products (published
 * ones, as cards) and categories (visible ones), breadcrumbs.
 *
 * SEO: canonical and hreflang only for the translated locales
 * (LocaleUrl::restrictTo; the language switcher sends other locales to
 * their news list), OG image from the cover, NewsArticle + BreadcrumbList
 * JSON-LD.
 */
class Show extends Component
{
    #[Locked]
    public NewsPost $newsPost;

    public function mount(NewsPost $newsPost): void
    {
        abort_unless($newsPost->isVisibleIn(app()->getLocale()), 404);

        $this->newsPost = $newsPost;
    }

    public function render(StructuredData $structuredData): View
    {
        $post = $this->newsPost->loadMissing('media');
        $locale = app()->getLocale();
        $title = (string) $post->getTranslation('title', $locale, false);
        $url = route('site.news.show', ['newsPost' => $post->slug]);
        $cover = $post->imageUrl('large');
        $breadcrumbs = [
            ['label' => __('site.breadcrumbs.home'), 'url' => route('site.home')],
            ['label' => __('site.news.title'), 'url' => route('site.news.index')],
            ['label' => $title, 'url' => null],
        ];
        $description = SeoText::description(
            $post->getTranslation('meta_description', $locale, false),
            $post->getTranslation('excerpt', $locale, false),
            $post->getTranslation('body', $locale, false),
        );

        LocaleUrl::restrictTo(
            array_values(array_intersect(config('app.supported_locales'), (array) $post->locales)),
            fn (string $other): string => route('site.news.index', ['locale' => $other]),
        );

        return view('livewire.site.news.show', [
            'title' => $title,
            'excerpt' => trim((string) $post->getTranslation('excerpt', $locale, false)),
            // Sanitized on save already; again on output as defense in depth.
            'body' => HtmlSanitizer::clean((string) $post->getTranslation('body', $locale, false)),
            'publishedAt' => $post->localPublishedAt(),
            'cover' => $cover,
            // The current locale's alt, else the title: never the uz alt on a ru or en page.
            'coverAlt' => ($post->imageAlts()[$locale] ?? '') ?: $title,
            'products' => $this->products(),
            'categories' => $this->categories(),
            'breadcrumbs' => $breadcrumbs,
            'rssUrl' => route('site.news.rss'),
            'jsonLd' => [
                StructuredData::toScript($structuredData->newsArticle($post, $title, $url, $description, $cover)),
                StructuredData::toScript($structuredData->breadcrumbs($breadcrumbs, $url)),
            ],
        ])
            ->title(SeoText::title($post->getTranslation('meta_title', $locale, false), $title))
            ->layoutData([
                'description' => $description,
                'image' => $cover,
                'type' => 'article',
            ]);
    }

    /**
     * Related products shown on the site, as cards (catalog order).
     *
     * @return list<ProductCard>
     */
    protected function products(): array
    {
        $ids = $this->newsPost->products()->pluck('products.id')->all();

        if ($ids === []) {
            return [];
        }

        $products = Product::query()
            ->visible()
            ->whereIn('products.id', $ids)
            ->with(ProductCards::EAGER_LOADS)
            ->orderBy('products.sort')
            ->orderBy('products.id')
            ->get();

        return ProductCards::fromProducts($products);
    }

    /**
     * Related categories visible on the site.
     *
     * @return list<array{name: string, url: string}>
     */
    protected function categories(): array
    {
        $items = [];

        foreach ($this->newsPost->categories()->pluck('categories.id') as $id) {
            $node = CategoryTree::find((int) $id);

            if ($node !== null && CategoryTree::isVisible((int) $id)) {
                $items[] = [
                    'name' => CategoryTree::name((int) $id),
                    'url' => route('site.catalog.category', ['category' => $node['slug']]),
                ];
            }
        }

        return $items;
    }
}
