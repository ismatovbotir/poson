<?php

namespace App\Actions\Catalog;

use App\Models\Product;
use App\Models\ProductDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Permanently delete a product (Admin only, from the trash). Inside the
 * transaction only database rows go: the product (values, skips, documents
 * and relations by FK cascade) and its media rows. Image files (originals
 * and conversions) and document files are purged after the commit, so a
 * rollback never leaves rows pointing at deleted files.
 */
class ForceDeleteProduct
{
    public function __construct(private Filesystem $mediaFiles) {}

    public function handle(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $paths = ProductDocument::query()
                ->where('product_id', $product->id)
                ->whereNotNull('path')
                ->pluck('path')
                ->all();

            $media = Media::query()
                ->where('model_type', $product->getMorphClass())
                ->where('model_id', $product->id)
                ->get();

            // Query-builder delete: no model events, so the files stay for now.
            Media::query()->whereKey($media->modelKeys())->delete();
            $product->forceDeletePreservingMedia();

            DB::afterCommit(function () use ($media, $paths): void {
                foreach ($media as $item) {
                    $this->mediaFiles->removeAllFiles($item);
                }

                if ($paths !== []) {
                    Storage::disk(ProductDocument::DISK)->delete($paths);
                }
            });
        });
    }
}
