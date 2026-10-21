<?php

namespace App\Actions\Catalog;

use App\Models\Product;
use App\Support\Catalog\ProductAttributeResolver;
use Illuminate\Support\Facades\DB;

/**
 * Recompute products.has_missing_specs (plan 03 D8): a product misses specs
 * when a required attribute of its category chain has no value row.
 * Trashed products are included (they may be restored). The flag is written
 * without touching updated_at.
 */
class RecomputeSpecCompleteness
{
    private const CHUNK = 500;

    public function __construct(private ProductAttributeResolver $resolver) {}

    /**
     * Recompute one product and return the new flag.
     */
    public function forProduct(Product $product): bool
    {
        $required = $this->resolver->requiredIds($product->category_id);

        $filled = $required === [] ? 0 : DB::table('product_attribute_values')
            ->where('product_id', $product->id)
            ->whereIn('attribute_id', $required)
            ->distinct()
            ->count('attribute_id');

        $missing = $filled < count($required);

        DB::table('products')->where('id', $product->id)->update(['has_missing_specs' => $missing]);

        $product->setAttribute('has_missing_specs', $missing);
        $product->syncOriginalAttribute('has_missing_specs');

        return $missing;
    }

    /**
     * Recompute every product of the given categories (only the products
     * directly in each category; pass descendants explicitly).
     *
     * @param  list<int>  $categoryIds
     */
    public function forCategories(array $categoryIds): void
    {
        foreach (array_unique($categoryIds) as $categoryId) {
            $required = $this->resolver->requiredIds((int) $categoryId);

            DB::table('products')
                ->where('category_id', $categoryId)
                ->select('id')
                ->chunkById(self::CHUNK, function ($rows) use ($required): void {
                    $ids = $rows->pluck('id')->map(fn ($id): int => (int) $id)->all();

                    $complete = $required === [] ? $ids : DB::table('product_attribute_values')
                        ->whereIn('product_id', $ids)
                        ->whereIn('attribute_id', $required)
                        ->groupBy('product_id')
                        ->havingRaw('count(distinct attribute_id) = ?', [count($required)])
                        ->pluck('product_id')
                        ->map(fn ($id): int => (int) $id)
                        ->all();

                    $incomplete = array_values(array_diff($ids, $complete));

                    if ($complete !== []) {
                        DB::table('products')->whereIn('id', $complete)->update(['has_missing_specs' => false]);
                    }

                    if ($incomplete !== []) {
                        DB::table('products')->whereIn('id', $incomplete)->update(['has_missing_specs' => true]);
                    }
                });
        }
    }
}
