<?php

namespace App\Actions\Catalog;

use App\Enums\RelationType;
use App\Models\Product;
use App\Models\ProductRelation;
use Illuminate\Validation\ValidationException;

/**
 * Link a related product (printer → labels, scanner → stand, ...). A product
 * can't relate to itself, and each product + related + type pair exists once.
 */
class AddProductRelation
{
    /**
     * @throws ValidationException
     */
    public function handle(Product $product, Product $related, RelationType $type): ProductRelation
    {
        if ($related->is($product)) {
            throw ValidationException::withMessages(['related' => __('admin.products.relations.errors.self')]);
        }

        $exists = ProductRelation::query()
            ->where('product_id', $product->id)
            ->where('related_id', $related->id)
            ->where('type', $type)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['related' => __('admin.products.relations.errors.duplicate')]);
        }

        $relation = ProductRelation::query()->create([
            'product_id' => $product->id,
            'related_id' => $related->id,
            'type' => $type,
            'sort' => (int) ProductRelation::query()->where('product_id', $product->id)->where('type', $type)->max('sort') + 1,
        ]);

        // Relations carry no timestamps; touching the product marks it as edited (PublishSeededProducts::isUntouched).
        $product->touch();

        return $relation;
    }
}
