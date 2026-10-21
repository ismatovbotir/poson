<?php

namespace App\Actions\Catalog;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Order, alt text and removal of a product's images. Media ids that don't
 * belong to the product's "images" collection are ignored.
 */
class UpdateProductImages
{
    /**
     * Put the images in the given order (the first one is the main image).
     *
     * @param  list<int>  $mediaIds
     */
    public function reorder(Product $product, array $mediaIds): void
    {
        $own = $this->media($product)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $ordered = array_values(array_intersect(array_map('intval', $mediaIds), $own));

        // Images missing from the list keep their relative order at the end.
        Media::setNewOrder([...$ordered, ...array_values(array_diff($own, $ordered))]);
    }

    /**
     * Move one image to a position (drag and drop; 0 = main image).
     */
    public function move(Product $product, int $mediaId, int $position): void
    {
        $ids = $this->media($product)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $index = array_search($mediaId, $ids, true);

        if ($index === false) {
            return;
        }

        array_splice($ids, $index, 1);
        array_splice($ids, max(0, min($position, count($ids))), 0, [$mediaId]);

        $this->reorder($product, $ids);
    }

    /**
     * @param  array<string, string|null>  $alt  locale => alt text
     */
    public function setAlt(Product $product, int $mediaId, array $alt): void
    {
        $media = $this->media($product)->firstWhere('id', $mediaId);

        if ($media === null) {
            return;
        }

        $clean = [];

        foreach (config('app.supported_locales') as $locale) {
            $clean[$locale] = mb_substr(trim((string) ($alt[$locale] ?? '')), 0, 255);
        }

        $media->setCustomProperty('alt', $clean)->save();
    }

    public function delete(Product $product, int $mediaId): void
    {
        DB::transaction(fn () => $this->media($product)->firstWhere('id', $mediaId)?->delete());
    }

    /**
     * @return Collection<int, Media>
     */
    private function media(Product $product): Collection
    {
        return Media::query()
            ->where('model_type', $product->getMorphClass())
            ->where('model_id', $product->id)
            ->where('collection_name', Product::IMAGES)
            ->orderBy('order_column')
            ->get();
    }
}
