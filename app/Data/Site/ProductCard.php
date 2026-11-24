<?php

namespace App\Data\Site;

use App\Enums\ProductBadge;
use App\Enums\SaleMode;
use App\Enums\StockStatus;
use App\Support\Catalog\LabelDiagram;
use App\Support\Catalog\ProductDiagram;
use App\Support\Catalog\RollDiagram;
use InvalidArgumentException;

/**
 * Product card on listings (App\Support\Catalog\ProductCards::fromProduct()):
 * name and key-spec line in the current locale, Retail price in tiyin (null =
 * price on request), stock status, sale mode and an optional "hit" / "new"
 * badge. image is a public conversion URL; without one the card draws the
 * sprite symbol "placeholder" (p-scanner, p-printer, ...), or the generated
 * label diagram for a label product or the roll diagram for a ribbon / receipt
 * roll (diagram, App\Support\Catalog\ProductDiagram).
 *
 * showStock is false for software (no stock badge).
 * pricedPerLabel (plan 08): price is per one label ("12 soʻm / label").
 * ctas are the card's buttons from App\Support\Catalog\ProductCtas (compact).
 *
 * Brand rule: there is no old price and no sale badge, by design.
 *
 * Cached by HomeCache (plan 34): when toArray()/fromArray() change shape, raise
 * HomeCache::SCHEMA.
 */
final readonly class ProductCard
{
    public function __construct(
        public string $name,
        public string $slug,
        public string $sku,
        public string $specs,
        public ?int $price,
        public StockStatus $stockStatus,
        public SaleMode $saleMode,
        public ?string $image,
        public string $url,
        public ?ProductBadge $badge = null,
        public ?string $brand = null,
        public ?string $category = null,
        public string $placeholder = 'p-barcode',
        public ?string $imageAlt = null,
        /** @var list<ProductCta> */
        public array $ctas = [],
        public bool $showStock = true,
        public bool $pricedPerLabel = false,
        public LabelDiagram|RollDiagram|null $diagram = null,
    ) {
        if ($price !== null && $price < 0) {
            throw new InvalidArgumentException('Product price must not be negative.');
        }
    }

    /**
     * Plain array for the home cache (HC-2): enums as values, nested DTOs as arrays.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'specs' => $this->specs,
            'price' => $this->price,
            'stock_status' => $this->stockStatus->value,
            'sale_mode' => $this->saleMode->value,
            'image' => $this->image,
            'url' => $this->url,
            'badge' => $this->badge?->value,
            'brand' => $this->brand,
            'category' => $this->category,
            'placeholder' => $this->placeholder,
            'image_alt' => $this->imageAlt,
            'ctas' => array_map(fn (ProductCta $cta): array => $cta->toArray(), $this->ctas),
            'show_stock' => $this->showStock,
            'priced_per_label' => $this->pricedPerLabel,
            'diagram' => $this->diagram === null ? null : ProductDiagram::toArray($this->diagram),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            slug: (string) $data['slug'],
            sku: (string) $data['sku'],
            specs: (string) $data['specs'],
            price: $data['price'] === null ? null : (int) $data['price'],
            stockStatus: StockStatus::from($data['stock_status']),
            saleMode: SaleMode::from($data['sale_mode']),
            image: $data['image'],
            url: (string) $data['url'],
            badge: $data['badge'] === null ? null : ProductBadge::from($data['badge']),
            brand: $data['brand'],
            category: $data['category'],
            placeholder: (string) $data['placeholder'],
            imageAlt: $data['image_alt'],
            ctas: array_map(ProductCta::fromArray(...), $data['ctas']),
            showStock: (bool) $data['show_stock'],
            pricedPerLabel: (bool) $data['priced_per_label'],
            diagram: $data['diagram'] === null ? null : ProductDiagram::fromArray($data['diagram']),
        );
    }
}
