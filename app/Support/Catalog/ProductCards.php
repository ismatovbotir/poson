<?php

namespace App\Support\Catalog;

use App\Data\Site\ProductCard;
use App\Enums\ProductType;
use App\Models\Attribute;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Maps published products to App\Data\Site\ProductCard for listings (home,
 * catalog, search, related products).
 *
 * Brand rule: only the Retail price ("price") reaches the card; Dealer and
 * Wholesale prices never leave the model. Query with
 * ->with(ProductCards::EAGER_LOADS) so cards cause no extra queries.
 */
class ProductCards
{
    /**
     * Relations a card reads (strict models forbid lazy loading). The diagram
     * specs (label sizes, roll specs) are added by loadDiagramSpecs() (fromProducts()).
     * activeRollTypes: whether a label product can go to the cart (ProductCtas, plan 11a).
     *
     * @var list<string>
     */
    public const EAGER_LOADS = ['brand', 'media', 'keySpecValues.attribute', 'keySpecValues.option', 'activeRollTypes'];

    /**
     * Between the values of one attribute (a multiselect).
     */
    public const VALUE_SEPARATOR = '; ';

    /**
     * Category icon (x-site.icon) → product placeholder symbol of the icon sprite.
     *
     * @var array<string, string>
     */
    private const PLACEHOLDERS = [
        'scan' => 'p-scanner',
        'print' => 'p-printer',
        'term' => 'p-terminal',
        'label' => 'p-labels',
        'pos' => 'p-printer',
    ];

    public static function fromProduct(Product $product): ProductCard
    {
        $media = $product->getFirstMedia(Product::IMAGES);

        return new ProductCard(
            name: (string) $product->name,
            slug: $product->slug,
            sku: $product->sku,
            specs: static::keySpecLine($product),
            price: $product->price,
            stockStatus: $product->stock_status,
            saleMode: $product->effectiveSaleMode(),
            image: $product->imageUrl('thumb'),
            url: route('site.product', ['locale' => app()->getLocale(), 'product' => $product->slug]),
            badge: $product->badge,
            brand: $product->publicBrand()?->name,
            category: CategoryTree::name($product->category_id),
            placeholder: static::placeholder($product->category_id),
            imageAlt: $media !== null ? $product->imageAlt($media) : (string) $product->name,
            ctas: ProductCtas::for($product, compact: true),
            showStock: $product->showsStockStatus(),
            pricedPerLabel: $product->isPricedPerLabel(),
            diagram: $media === null && $product->imageUrl('thumb') === null ? ProductDiagram::for($product) : null,
        );
    }

    /**
     * @param  iterable<Product>  $products
     * @return list<ProductCard>
     */
    public static function fromProducts(iterable $products): array
    {
        $cards = [];

        static::loadDiagramSpecs($products);

        foreach ($products as $product) {
            $cards[] = static::fromProduct($product);
        }

        return $cards;
    }

    /**
     * Eager-loads what the generated diagrams of products without an image need
     * (no query when no product qualifies; a constant number otherwise):
     * the label size values of label-priced products, the roll specs (with
     * attribute and option) of the other consumables (ribbons, receipt paper).
     *
     * @param  iterable<Product>  $products
     */
    public static function loadDiagramSpecs(iterable $products): void
    {
        $labels = [];
        $rolls = [];

        foreach ($products as $product) {
            if (! $product->relationLoaded('media') || $product->media->isNotEmpty()) {
                continue;
            }

            if ($product->isPricedPerLabel()) {
                $labels[] = $product;
            } elseif ($product->type === ProductType::Consumable) {
                $rolls[] = $product;
            }
        }

        if ($labels !== []) {
            (new EloquentCollection($labels))->loadMissing('labelSizeValues.attribute');
        }

        if ($rolls !== []) {
            (new EloquentCollection($rolls))->loadMissing(['rollSpecValues.attribute', 'rollSpecValues.option']);
        }
    }

    /**
     * Older name of loadDiagramSpecs(), kept as an alias.
     *
     * @param  iterable<Product>  $products
     */
    public static function loadLabelSizes(iterable $products): void
    {
        static::loadDiagramSpecs($products);
    }

    /**
     * Up to Product::MAX_KEY_SPECS key spec values: "2D · USB · IP54"
     * (a multiselect lists its options separated by "; ").
     */
    public static function keySpecLine(Product $product): string
    {
        return implode(' · ', array_slice(array_column(static::keySpecs($product), 'value'), 0, Product::MAX_KEY_SPECS));
    }

    /**
     * Key specs as label / value pairs, one per attribute, in spec order.
     *
     * @return list<array{label: string, value: string}>
     */
    public static function keySpecs(Product $product): array
    {
        $specs = [];

        /** @var ProductAttributeValue $value */
        foreach ($product->getRelationValue('keySpecValues') ?? [] as $value) {
            $display = $value->displayValue();

            if ($display === null) {
                continue;
            }

            // Plan 43 #26: an option value keeps the attribute's unit ("203 dpi").
            $attribute = $value->getRelationValue('attribute');

            if ($attribute instanceof Attribute && $attribute->type->hasOptions()) {
                $display = $attribute->optionWithUnit($display);
            }

            $id = $value->attribute_id;
            $specs[$id] ??= ['label' => (string) $attribute?->name, 'values' => []];
            $specs[$id]['values'][] = $display;
        }

        return array_values(array_map(
            fn (array $spec): array => ['label' => $spec['label'], 'value' => implode(ProductCards::VALUE_SEPARATOR, array_unique($spec['values']))],
            $specs,
        ));
    }

    /**
     * Placeholder symbol from the icon of the category or its nearest ancestor with one.
     */
    public static function placeholder(int $categoryId): string
    {
        foreach ([$categoryId, ...CategoryTree::ancestorIds($categoryId)] as $id) {
            $icon = CategoryTree::find($id)['icon'] ?? null;

            if ($icon !== null && isset(self::PLACEHOLDERS[$icon])) {
                return self::PLACEHOLDERS[$icon];
            }
        }

        return 'p-barcode';
    }
}
