<?php

namespace App\Actions\Catalog;

use App\Models\Product;

/**
 * Publish or unpublish a product (bulk actions on the products list).
 *
 * Activating needs complete specs, like SaveProduct: a draft with missing
 * required specs stays a draft and false is returned; so does a label
 * product (plan 08) without an active roll type. Deactivating always works.
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

        if ($active && $product->isPricedPerLabel() && ! $product->activeRollTypes()->exists()) {
            return false;
        }

        $product->is_active = $active;
        $product->save();

        return true;
    }
}
