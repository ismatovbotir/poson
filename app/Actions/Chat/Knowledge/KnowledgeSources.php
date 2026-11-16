<?php

namespace App\Actions\Chat\Knowledge;

use App\Enums\AiKnowledgeSource;
use App\Enums\NewsKind;
use App\Enums\PageTemplate;
use App\Enums\ProductType;
use App\Models\Brand;
use App\Models\NewsPost;
use App\Models\Page;
use App\Models\Product;
use App\Support\Site\CanonicalHost;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What the AI may know from the website (plan 33 K-1): the public scopes
 * only, nothing else.
 *  - pages: active, not the Legal template, never the privacy and offer slugs;
 *  - news and articles: visible() (published, date passed) and translated in
 *    the locale; news also not older than chatbot.ai.knowledge.news_months;
 *  - brands: listed() (active with a visible product);
 *  - services and software: Product::visible() of that type; hardware and
 *    consumable descriptions are marketing text and never indexed.
 * Reads no price level, order, customer or lead data.
 */
class KnowledgeSources
{
    /**
     * Slugs that are never knowledge, whatever their template (K-1).
     *
     * @var list<string>
     */
    public const BLOCKED_PAGE_SLUGS = ['privacy', 'offer'];

    /**
     * The visible sources of a type (ids only are needed by callers).
     *
     * @return Builder<Model>
     */
    public function query(AiKnowledgeSource $type): Builder
    {
        return match ($type) {
            AiKnowledgeSource::Page => Page::query()->active()
                ->where('template', '!=', PageTemplate::Legal->value)
                ->whereNotIn('slug', self::BLOCKED_PAGE_SLUGS),
            AiKnowledgeSource::News => NewsPost::query()->visible()
                ->where('kind', NewsKind::News->value)
                ->where('published_at', '>=', now()->subMonths((int) config('chatbot.ai.knowledge.news_months', 24))),
            AiKnowledgeSource::Article => NewsPost::query()->visible()->where('kind', NewsKind::Article->value),
            AiKnowledgeSource::Brand => Brand::query()->listed(),
            AiKnowledgeSource::Service => Product::query()->visible()->where('type', ProductType::Service->value),
            AiKnowledgeSource::Software => Product::query()->visible()->where('type', ProductType::Software->value),
        };
    }

    /**
     * The source in every locale it is public in; empty when it is not
     * visible (the caller then deletes its chunks).
     *
     * @return list<KnowledgeDocument>
     */
    public function documents(AiKnowledgeSource $type, int $id): array
    {
        $model = $this->query($type)->whereKey($id)->first();

        if ($model === null) {
            return [];
        }

        $documents = [];

        foreach ((array) config('app.supported_locales') as $locale) {
            $document = match (true) {
                $model instanceof Page => $this->page($model, $locale),
                $model instanceof NewsPost => $this->news($model, $locale),
                $model instanceof Brand => $this->brand($model, $locale),
                $model instanceof Product => $this->product($model, $locale),
                default => null,
            };

            if ($document !== null) {
                $documents[] = $document;
            }
        }

        return $documents;
    }

    /**
     * The sources of a type that are public right now in a locale, keyed by
     * id (slug only, nothing else is loaded): the retrieval re-check and the
     * button builder use it (K-1, K-5). News and articles must still be
     * translated in the locale.
     *
     * @param  list<int>  $ids
     * @return array<int, string> id => slug
     */
    public function live(AiKnowledgeSource $type, array $ids, string $locale): array
    {
        if ($ids === []) {
            return [];
        }

        $query = $this->query($type)->whereIn('id', array_values(array_unique($ids)));

        if ($type->newsKind() !== null) {
            $query->whereJsonContains('locales', $locale);
        }

        return $query->pluck('slug', 'id')->map(fn (mixed $slug): string => (string) $slug)->all();
    }

    /**
     * The public URL of a source, built from its type, slug and locale (never
     * a stored value, K-5), on the site's own host.
     */
    public function url(AiKnowledgeSource $type, string $slug, string $locale): string
    {
        return CanonicalHost::using(fn (): string => match ($type) {
            AiKnowledgeSource::Page => route('site.page', ['locale' => $locale, 'page' => $slug]),
            AiKnowledgeSource::News, AiKnowledgeSource::Article => ($type->newsKind() ?? NewsKind::News)->url($slug, $locale),
            AiKnowledgeSource::Brand => route('site.brands.show', ['locale' => $locale, 'brand' => $slug]),
            AiKnowledgeSource::Service, AiKnowledgeSource::Software => route('site.product', ['locale' => $locale, 'product' => $slug]),
        });
    }

    private function page(Page $page, string $locale): ?KnowledgeDocument
    {
        return $this->document($locale, $page->getTranslations('title'), [$page->getTranslations('body')], fn (): string => route('site.page', ['locale' => $locale, 'page' => $page->slug]));
    }

    private function news(NewsPost $post, string $locale): ?KnowledgeDocument
    {
        if (! $post->isTranslatedIn($locale)) {
            return null;
        }

        return $this->document(
            $locale,
            $post->getTranslations('title'),
            [$post->getTranslations('excerpt'), $post->getTranslations('body')],
            fn (): string => $post->kind->url($post->slug, $locale),
            $post->published_at,
        );
    }

    private function brand(Brand $brand, string $locale): ?KnowledgeDocument
    {
        return $this->document($locale, [$locale => $brand->name], [$brand->getTranslations('description')], fn (): string => route('site.brands.show', ['locale' => $locale, 'brand' => $brand->slug]));
    }

    private function product(Product $product, string $locale): ?KnowledgeDocument
    {
        return $this->document(
            $locale,
            $product->getTranslations('name'),
            [$product->getTranslations('short_description'), $product->getTranslations('description')],
            fn (): string => route('site.product', ['locale' => $locale, 'product' => $product->slug]),
        );
    }

    /**
     * A locale's title and body parts exactly as stored: no fallback to
     * another locale, and no document when the title or all parts are empty.
     *
     * @param  array<string, mixed>  $titles
     * @param  list<array<string, mixed>>  $parts
     * @param  callable(): string  $url
     */
    private function document(string $locale, array $titles, array $parts, callable $url, mixed $visibleFrom = null): ?KnowledgeDocument
    {
        $title = trim((string) ($titles[$locale] ?? ''));
        $body = implode("\n", array_filter(array_map(fn (array $part): string => trim((string) ($part[$locale] ?? '')), $parts), fn (string $text): bool => $text !== ''));

        if ($title === '' || $body === '') {
            return null;
        }

        return new KnowledgeDocument($locale, $title, $body, CanonicalHost::using($url), $visibleFrom);
    }
}
