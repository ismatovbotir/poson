<?php

namespace App\Support\Catalog;

use App\Models\Brand;
use App\Models\Product;
use App\Support\Search;

/**
 * Builds products.search_text (plan 03 D7): SKU, model, the name in every
 * locale and the brand name, normalized with Search::normalize() (lower
 * case, Uzbek apostrophes removed) so one portable LIKE finds a product on
 * SQLite and MySQL. Query it with Search::normalizedPattern().
 */
class ProductSearchText
{
    public static function build(Product $product, ?string $brandName = null): string
    {
        $brandName ??= match (true) {
            $product->brand_id === null => null,
            $product->relationLoaded('brand') => $product->getRelation('brand')?->name,
            default => Brand::query()->whereKey($product->brand_id)->value('name'),
        };

        $parts = [
            $product->sku,
            $product->model,
            ...array_values($product->getTranslations('name')),
            $brandName,
        ];

        return Search::normalize(implode(' ', array_filter($parts, fn (?string $part): bool => filled($part))));
    }
}
