<?php

namespace App\Actions\Catalog;

use App\Models\Attribute;
use App\Models\Category;
use App\Support\Catalog\CategoryTree;
use Illuminate\Support\Facades\DB;

/**
 * Replace a category's own attributes (category_attribute pivot) with the
 * given rows, in order (sort 1, 2, 3, ...).
 *
 * Each attribute is assigned once per ancestor chain: attributes the
 * category already inherits are skipped, and afterwards the same attributes
 * are removed from the category's descendants (pruneSubtree). Text
 * attributes are never filters.
 */
class SyncCategoryAttributes
{
    public function __construct(private RecomputeSpecCompleteness $recompute) {}

    /**
     * @param  list<array{attribute_id: int, is_required: bool, is_filterable: bool}>  $rows
     */
    public function handle(Category $category, array $rows): void
    {
        $inherited = $this->inheritedAttributeIds($category);

        $attributes = Attribute::query()
            ->whereIn('id', array_column($rows, 'attribute_id'))
            ->get(['id', 'type'])
            ->keyBy('id');

        $sync = [];

        foreach ($rows as $row) {
            $attribute = $attributes->get((int) $row['attribute_id']);

            if ($attribute === null || isset($sync[$attribute->id]) || in_array($attribute->id, $inherited, true)) {
                continue;
            }

            $sync[$attribute->id] = [
                'is_required' => (bool) $row['is_required'],
                'is_filterable' => $attribute->type->canBeFilterable() && (bool) $row['is_filterable'],
                'sort' => count($sync) + 1,
            ];
        }

        DB::transaction(function () use ($category, $sync): void {
            $category->attributes()->sync($sync);

            // Prunes duplicates and recomputes has_missing_specs for the subtree.
            $this->pruneSubtree($category);
        });
    }

    /**
     * Remove duplicates down the tree: the category loses attributes its
     * ancestors already assign, and its descendants lose attributes the
     * category assigns or inherits. Then has_missing_specs is recomputed for
     * the products of the category and its descendants (their chain changed).
     */
    public function pruneSubtree(Category $category): void
    {
        $inherited = $this->inheritedAttributeIds($category);

        if ($inherited !== []) {
            DB::table('category_attribute')
                ->where('category_id', $category->id)
                ->whereIn('attribute_id', $inherited)
                ->delete();
        }

        $descendants = CategoryTree::descendantIds($category->id);

        if ($descendants === []) {
            $this->recomputeProducts($category);

            return;
        }

        $chain = array_values(array_unique([
            ...$inherited,
            ...DB::table('category_attribute')->where('category_id', $category->id)->pluck('attribute_id')->map(fn ($id): int => (int) $id)->all(),
        ]));

        if ($chain !== []) {
            DB::table('category_attribute')
                ->whereIn('category_id', $descendants)
                ->whereIn('attribute_id', $chain)
                ->delete();
        }

        $this->recomputeProducts($category);
    }

    /**
     * Attribute ids assigned to any ancestor of the category.
     *
     * @return list<int>
     */
    public function inheritedAttributeIds(Category $category): array
    {
        $ancestorIds = CategoryTree::ancestorIds($category->id);

        if ($ancestorIds === []) {
            return [];
        }

        return DB::table('category_attribute')
            ->whereIn('category_id', $ancestorIds)
            ->pluck('attribute_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Recompute products.has_missing_specs for the category and its subtree (plan 03 D8).
     */
    public function recomputeProducts(Category $category): void
    {
        $this->recompute->forCategories([$category->id, ...CategoryTree::descendantIds($category->id)]);
    }
}
