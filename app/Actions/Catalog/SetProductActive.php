<?php

namespace App\Actions\Catalog;

use App\Models\Product;

/**
 * Publish or unpublish a product (bulk actions on the products list).
 *
 * Activating needs complete specs, like SaveProduct: a draft with missing
 * required specs stays a draft and false is returned. Deactivating always
 * works.
 */
class SetProductActive
{
    public function __construct(private RecomputeSpecCompleteness $recompute) {}

    public function handle(Product $product, bool $active): bool
    {
        if ($product->is_active === $active) {
            return true;
        }

        if ($active && $this->recompute->forProduct($product)) {
            return false;
        }

        $product->is_active = $active;
        $product->save();

        return true;
    }
}
