<?php

namespace App\Http\Controllers\Tma;

use App\Enums\PriceLevel;
use App\Enums\ProductCtaKind;
use App\Enums\ProductType;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductDocument;
use App\Models\RollType;
use App\Support\Catalog\CategoryTree;
use App\Support\Catalog\ProductAttributeResolver;
use App\Support\Catalog\ProductCards;
use App\Support\Catalog\ProductCtas;
use App\Support\Catalog\ProductDiagram;
use App\Support\Catalog\RollPricing;
use App\Support\Catalog\SoftwareSpecs;
use App\Support\HtmlSanitizer;
use App\Support\Site\VideoContent;
use App\Support\Telegram\MiniApp\TmaLinks;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * GET /tma/{locale}/p/{product:slug} (plan 20 §3, §6.3, M2): a product shown
 * on the site (else 404) with the Retail price only, the gallery (first
 * GALLERY_LIMIT images: the first "large" and eager, the rest "thumb" and
 * lazy; the generated label / roll diagram without images), key specs and
 * specs, the description (links open outside Telegram, MA-22), documents
 * and videos (openLink, never embedded), and the buttons of ProductCtas
 * mapped by kind:
 *
 *  - cart: quantity form, or roll type radios for a label product → POST cart;
 *  - quote / contact / service_request: the one-tap request form (comment);
 *  - device_service: the in-app service request form (Q4);
 *  - try: openLink to the tracked partner redirect on the site.
 *
 * The site URLs inside ProductCta are never rendered as in-app links (A7).
 */
class ProductController extends TmaController
{
    public const GALLERY_LIMIT = 4;

    public function __invoke(Request $request, Product $product, ProductAttributeResolver $resolver, VideoContent $videoContent): Response
    {
        abort_unless($product->isVisible(), 404);

        $product->load(['brand', 'media', 'attributeValues.attribute', 'attributeValues.option', 'documents']);
        $pricedPerLabel = $product->isPricedPerLabel();

        if ($pricedPerLabel) {
            $product->load('activeRollTypes');
            $product->setRelation('rollTypes', $product->activeRollTypes);
        }

        $product->setRelation('keySpecValues', $product->attributeValues->where('is_key', true)->values());
        $skipped = $product->attributeSkips()->pluck('attribute_id')->map(fn ($id): int => (int) $id)->all();
        $images = $this->images($product);
        $ctas = ProductCtas::for($product);
        $kinds = array_map(fn ($cta): ProductCtaKind => $cta->kind, $ctas);

        return $this->page($request, 'tma.product', [
            'product' => $product,
            'title' => (string) $product->name,
            'back' => ($node = CategoryTree::find($product->category_id)) !== null ? route('tma.category', ['category' => $node['slug']]) : route('tma.home'),
            'images' => $images,
            'diagram' => $images === [] ? ProductDiagram::for($product) : null,
            'brand' => $product->publicBrand(),
            'keySpecs' => ProductCards::keySpecs($product),
            'specs' => $this->specs($product, $resolver, $skipped),
            'software' => $product->type === ProductType::Software ? SoftwareSpecs::forProduct($product, $skipped) : [],
            'description' => TmaLinks::description(HtmlSanitizer::clean((string) $product->description)),
            'documents' => $this->documents($product),
            'videos' => array_values(array_filter(array_map(
                fn (array $video): ?array => ($url = TmaLinks::video($video['watch'])) !== null ? ['title' => $video['title'], 'url' => $url] : null,
                $videoContent->forOwner($product, (string) $product->name),
            ))),
            'ctas' => $ctas,
            'canAddToCart' => in_array(ProductCtaKind::Cart, $kinds, true),
            'inquiry' => collect($ctas)->first(fn ($cta): bool => in_array($cta->kind, [ProductCtaKind::Quote, ProductCtaKind::Contact, ProductCtaKind::ServiceRequest], true)),
            'deviceService' => in_array(ProductCtaKind::DeviceService, $kinds, true),
            'tryUrl' => in_array(ProductCtaKind::Try, $kinds, true) ? TmaLinks::own('site.product.partner', ['locale' => app()->getLocale(), 'product' => $product->slug]) : null,
            'pricedPerLabel' => $pricedPerLabel,
            'rollTypes' => $pricedPerLabel ? $this->rollTypes($product) : [],
            'minQty' => max(1, (int) $product->min_qty),
        ]);
    }

    /**
     * @return list<array{url: string, alt: string, large: bool}>
     */
    protected function images(Product $product): array
    {
        $images = [];

        foreach ($product->imageMedia() as $media) {
            if (! $media->hasGeneratedConversion('large')) {
                continue;
            }

            $first = $images === [];
            $images[] = [
                'url' => $first || ! $media->hasGeneratedConversion('thumb') ? $media->getUrl('large') : $media->getUrl('thumb'),
                'alt' => $product->imageAlt($media),
                'large' => $first,
            ];

            if (count($images) >= self::GALLERY_LIMIT) {
                break;
            }
        }

        return $images;
    }

    /**
     * Active roll types with the Retail price of one roll (none without a price).
     *
     * @return list<array{id: int, label: string, rollPrice: ?int}>
     */
    protected function rollTypes(Product $product): array
    {
        return $product->activeRollTypes->map(function (RollType $rollType) use ($product): array {
            try {
                $line = RollPricing::line($product, $rollType, 1, $product->price, PriceLevel::Retail);
            } catch (ValidationException) {
                $line = null;
            }

            return ['id' => $rollType->id, 'label' => $rollType->label(), 'rollPrice' => $line?->hasPrice() ? $line->rollPrice : null];
        })->values()->all();
    }

    /**
     * Spec rows as on the site product page (resolver order, skipped and empty rows left out).
     *
     * @param  list<int>  $skipped
     * @return list<array{label: string, value: string}>
     */
    protected function specs(Product $product, ProductAttributeResolver $resolver, array $skipped): array
    {
        $values = $product->attributeValues->groupBy('attribute_id');
        $isSoftware = $product->type === ProductType::Software;
        $rows = [];

        foreach ($resolver->resolve($product->category_id, $skipped, $values->keys()->map(fn ($id): int => (int) $id)->all()) as $row) {
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
     * Documents that can open outside Telegram: files through the site's
     * download route, links when they are https (MA-22).
     *
     * @return list<array{title: string, type: string, locale: ?string, url: string}>
     */
    protected function documents(Product $product): array
    {
        return $product->documents
            ->map(function (ProductDocument $document) use ($product): ?array {
                $url = $document->isFile()
                    ? TmaLinks::own('site.product.document', ['locale' => app()->getLocale(), 'product' => $product->slug, 'document' => $document->id])
                    : TmaLinks::external((string) $document->url);

                return $url === null ? null : [
                    'title' => (string) ($document->title ?: $document->type->label()),
                    'type' => $document->type->label(),
                    'locale' => $document->locale !== null ? mb_strtoupper($document->locale) : null,
                    'url' => $url,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
