<?php

namespace App\Actions\Catalog;

use App\Models\Product;
use App\Models\ProductDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Permanently delete a product (Admin only, from the trash): its values,
 * skips, documents and relations go with it (FK cascade), medialibrary
 * removes the images, and the document files are deleted after the commit.
 */
class ForceDeleteProduct
{
    public function handle(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $paths = ProductDocument::query()
                ->where('product_id', $product->id)
                ->whereNotNull('path')
                ->pluck('path')
                ->all();

            $product->forceDelete();

            if ($paths !== []) {
                DB::afterCommit(fn () => Storage::disk(ProductDocument::DISK)->delete($paths));
            }
        });
    }
}
