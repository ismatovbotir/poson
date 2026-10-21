<?php

namespace App\Actions\Catalog;

use App\Models\Product;
use App\Models\ProductRelation;

/**
 * Remove one of the product's relations (ids of other products are ignored).
 */
class RemoveProductRelation
{
    public function handle(Product $product, int $relationId): void
    {
        ProductRelation::query()->where('product_id', $product->id)->whereKey($relationId)->delete();
    }
}
