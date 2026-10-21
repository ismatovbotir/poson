<?php

namespace App\Actions\Catalog;

use App\Models\Product;

/**
 * Move a product to the trash (soft delete, plan 03 D9). It disappears from
 * the site; images, documents, values and relations stay until it is
 * permanently deleted (ForceDeleteProduct) or restored (RestoreProduct).
 */
class DeleteProduct
{
    public function handle(Product $product): void
    {
        $product->delete();
    }
}
