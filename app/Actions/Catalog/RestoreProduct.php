<?php

namespace App\Actions\Catalog;

use App\Models\Product;

/**
 * Take a product out of the trash. SKU and slug are unique including
 * trashed rows, so the restore never clashes. The product comes back with
 * the publish state it had.
 */
class RestoreProduct
{
    public function handle(Product $product): void
    {
        $product->restore();
    }
}
