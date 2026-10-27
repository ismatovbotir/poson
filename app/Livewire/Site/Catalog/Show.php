<?php

namespace App\Livewire\Site\Catalog;

use App\Data\Site\ProductCard;
use App\Enums\ProductType;
use App\Enums\RelationType;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductDocument;
use App\Support\Catalog\CategoryTree;
use App\Support\Catalog\ProductAttributeResolver;
use App\Support\Catalog\ProductCards;
use App\Support\Catalog\ProductCtas;
use App\Support\Catalog\SoftwareSpecs;
use App\Support\HtmlSanitizer;
use App\Support\Site\FaqContent;
use App\Support\Site\NewsCards;
use App\Support\Site\SeoText;
use App\Support\Site\StructuredData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Product page (plan 03 T18), ported from product.html: gallery, title,
 * SKU, brand, stock, Retail price (or "price on request"), key specs, tabs
 * (description, specs, documents, delivery & warranty), related products,
 * related news (plan 07 7b), useful articles from its category tree
 * (decision 2026-10-24), the quote form and a mobile sticky bar. The
 * buy box and the sticky bar buttons come from ProductCtas (shared with the cards and the Mini App);
 * software shows its details (SoftwareSpecs) as chips and a modules list.
 *
 * 404 unless the product is shown on the site (active, not trashed, in a
 * visible category). No quantity tiers, rating, reviews, old price or
 * favorites; Dealer and Wholesale prices are never rendered.
 */
class Show extends Component
{
    /**
     * Relation types listed under the product, in this order.
     *
     * @var list<RelationType>
     */
    public const RELATED_TYPES = [RelationType::Accessory, RelationType::Consumable, RelationType::Alternative];

    /**
     * Related products shown per type.
     */
    public const RELATED_LIMIT = 8;

    #[Locked]
    public Product $product;

    public function mount(Product $product): void
    {
        abort_unless($product->isVisible(), 404);

        $this->product = $product;
    }

    /**
     * Update requests: the product may have left the site since the page loaded.
     */
    public function hydrate(): void
    {
        abort_unless($this->product->exists && $this->product->fresh()?->isVisible(), 404);
    }

    public function render(ProductAttributeResolver $resolver, StructuredData $structuredData, FaqContent $faqContent): View
    {
        $product = $this->product->load([
            'brand',
            'media',
            'attributeValues.attribute',
            'attributeValues.option',
            'documents',
        ]);
        $product->setRelation('keySpecValues', $product->attributeValues->where('is_key', true)->values());

        $url = route('site.product', ['product' => $product->slug]);
        $breadcrumbs = $this->breadcrumbs();
        $image = $product->imageUrl('large');
        // The product's FAQ, else its category's (or the nearest ancestor's), plan 04 T12.
        // FAQPage JSON-LD only for the product's own items (inherited ones belong to the category page).
        $faq = $faqContent->forProduct($product);
        $skipped = $product->attributeSkips()->pluck('attribute_id')->map(fn ($id): int => (int) $id)->all();

        return view('livewire.site.catalog.show', [
            'images' => $this->images(),
            'keySpecs' => ProductCards::keySpecs($product),
            'specs' => $this->specs($resolver, $skipped),
            'software' => $product->type === ProductType::Software ? SoftwareSpecs::forProduct($product, $skipped) : [],
            'documents' => $this->documents($product->documents),
            'related' => $this->related(),
            // Plan 07 7b: one query (none while the locale has no news), media eager-loaded.
            'news' => NewsCards::latest(NewsCards::LATEST_LIMIT, $product->id),
            // Up to 3 articles linked to the product's category, its ancestors (top-level included) or descendants; two queries (posts, media).
            'articles' => NewsCards::articlesFor($product->category_id),
            'breadcrumbs' => $breadcrumbs,
            'categoryName' => CategoryTree::name($product->category_id),
            'placeholder' => ProductCards::placeholder($product->category_id),
            'brand' => $product->publicBrand(),
            // Sanitized on save already; again on output as defense in depth.
            'description' => HtmlSanitizer::clean((string) $product->description),
            'ctas' => ProductCtas::for($product),
            'faq' => $faq['items'],
            'jsonLd' => array_values(array_filter([
                StructuredData::toScript($structuredData->product($product, $url)),
                StructuredData::toScript($structuredData->breadcrumbs($breadcrumbs, $url)),
                $faq['own'] && $faq['items'] !== [] ? StructuredData::toScript($structuredData->faqPage($faq['items'])) : null,
            ])),
        ])
            ->title(SeoText::title($product->getTranslation('meta_title', app()->getLocale(), false), (string) $product->name))
            ->layoutData([
                'description' => SeoText::description(
                    $product->getTranslation('meta_description', app()->getLocale(), false),
                    $product->getTranslation('short_description', app()->getLocale(), false),
                    $product->getTranslation('description', app()->getLocale(), false),
                ) ?? __('site.catalog.seo.category_description', ['name' => (string) $product->name]),
                'image' => $image,
                'type' => 'product',
            ]);
    }

    /**
     * Gallery images: only generated conversions (originals are private).
     *
     * @return list<array{thumb: string, large: string, alt: string}>
     */
    protected function images(): array
    {
        $images = [];

        foreach ($this->product->imageMedia() as $media) {
            if (! $media->hasGeneratedConversion('large')) {
                continue;
            }

            $images[] = [
                'large' => $media->getUrl('large'),
                'thumb' => $media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : $media->getUrl('large'),
                'alt' => $this->product->imageAlt($media),
            ];
        }

        return $images;
    }

    /**
     * Spec rows in resolver order (ancestors, own category, extras): one row
     * per attribute with its values joined; skipped and empty rows are left
     * out, and so are the software details shown in their own block.
     *
     * @param  list<int>  $skipped
     * @return list<array{label: string, value: string}>
     */
    protected function specs(ProductAttributeResolver $resolver, array $skipped): array
    {
        $values = $this->product->attributeValues->groupBy('attribute_id');
        $isSoftware = $this->product->type === ProductType::Software;
        $rows = [];

        foreach ($resolver->resolve($this->product->category_id, $skipped, $values->keys()->map(fn ($id): int => (int) $id)->all()) as $row) {
            if ($row->skipped || ($isSoftware && in_array($row->attribute->code, SoftwareSpecs::BLOCK_CODES, true))) {
                continue;
            }

            $display = $values->get($row->attribute->id, collect())
                ->map(fn (ProductAttributeValue $value): ?string => $value->displayValue())
                ->filter()
                ->unique()
                ->implode(ProductCards::VALUE_SEPARATOR);

            if ($display !== '') {
                $rows[] = ['label' => (string) $row->attribute->name, 'value' => $display];
            }
        }

        return $rows;
    }

    /**
     * Every document with its language badge (null = any language) and its
     * public URL: the download route for files, the https link otherwise.
     *
     * @param  Collection<int, ProductDocument>  $documents
     * @return list<array{title: string, type: string, kind: string, locale: ?string, url: string, external: bool, sha256: ?string}>
     */
    protected function documents(Collection $documents): array
    {
        return $documents
            ->map(fn (ProductDocument $document): array => [
                'title' => (string) ($document->title ?: $document->type->label()),
                'type' => $document->type->label(),
                'kind' => $document->kind(),
                'locale' => $document->locale !== null ? mb_strtoupper($document->locale) : null,
                'url' => $document->isFile()
                    ? route('site.product.document', ['product' => $this->product->slug, 'document' => $document->id])
                    : (string) $document->url,
                'external' => ! $document->isFile(),
                'sha256' => $document->isFile() ? $document->sha256 : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Published related products per relation type.
     *
     * @return array<string, list<ProductCard>>
     */
    protected function related(): array
    {
        $links = $this->product->relations()
            ->whereIn('type', array_map(fn (RelationType $type): string => $type->value, self::RELATED_TYPES))
            ->get(['related_id', 'type']);

        if ($links->isEmpty()) {
            return [];
        }

        $products = Product::query()
            ->visible()
            ->whereIn('products.id', $links->pluck('related_id')->unique()->all())
            ->with(ProductCards::EAGER_LOADS)
            ->get()
            ->keyBy('id');

        $sections = [];

        foreach (self::RELATED_TYPES as $type) {
            $cards = $links->where('type', $type)
                ->map(fn ($link) => $products->get($link->related_id))
                ->filter()
                ->take(self::RELATED_LIMIT)
                ->map(fn (Product $related) => ProductCards::fromProduct($related))
                ->values()
                ->all();

            if ($cards !== []) {
                $sections[$type->value] = $cards;
            }
        }

        return $sections;
    }

    /**
     * @return list<array{label: string, url: ?string}>
     */
    protected function breadcrumbs(): array
    {
        $items = [
            ['label' => __('site.breadcrumbs.home'), 'url' => route('site.home')],
            ['label' => __('site.catalog.title'), 'url' => route('site.catalog.index')],
        ];

        foreach ([...array_reverse(CategoryTree::ancestorIds($this->product->category_id)), $this->product->category_id] as $categoryId) {
            $items[] = [
                'label' => CategoryTree::name($categoryId),
                'url' => route('site.catalog.category', ['category' => CategoryTree::find($categoryId)['slug']]),
            ];
        }

        $items[] = ['label' => (string) $this->product->name, 'url' => null];

        return $items;
    }
}
