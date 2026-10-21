<?php

namespace App\Support\Catalog;

use App\Models\Attribute;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * The spec rows of a product (catalog-module "Attribute inheritance"):
 * category attributes (including ancestors) − skipped + extras.
 *
 * Order: attributes inherited from the ancestors, nearest ancestor first,
 * then the category's own ones, each in pivot sort order — the same order
 * as the "In the product form" preview of the category form. An attribute
 * appears once; the nearest ancestor that assigns it wins (SyncCategoryAttributes
 * normally prevents duplicates in a chain). Extras follow, in the given order.
 *
 * Required attributes can't be skipped: a stale skip row for an attribute
 * that became required is ignored. Attribute options are eager-loaded.
 */
class ProductAttributeResolver
{
    /**
     * Category ids in resolution order: ancestors nearest first, then the category.
     *
     * @return list<int>
     */
    public function chain(int $categoryId): array
    {
        if (CategoryTree::find($categoryId) === null) {
            return [];
        }

        return [...CategoryTree::ancestorIds($categoryId), $categoryId];
    }

    /**
     * Attributes the category (and its ancestors) assign to its products.
     *
     * @return list<ResolvedAttribute>
     */
    public function categoryAttributes(?int $categoryId): array
    {
        $chain = $categoryId !== null ? $this->chain($categoryId) : [];

        if ($chain === []) {
            return [];
        }

        $pivots = DB::table('category_attribute')
            ->whereIn('category_id', $chain)
            ->orderBy('sort')
            ->orderBy('attribute_id')
            ->get(['category_id', 'attribute_id', 'is_required', 'is_filterable'])
            ->groupBy('category_id');

        $attributes = $this->attributes($pivots->flatten(1)->pluck('attribute_id')->all());
        $rows = [];

        foreach ($chain as $chainCategoryId) {
            foreach ($pivots->get($chainCategoryId, collect()) as $pivot) {
                $attribute = $attributes[(int) $pivot->attribute_id] ?? null;

                if ($attribute === null || isset($rows[$attribute->id])) {
                    continue;
                }

                $rows[$attribute->id] = new ResolvedAttribute(
                    attribute: $attribute,
                    categoryId: $chainCategoryId,
                    required: (bool) $pivot->is_required,
                    filterable: (bool) $pivot->is_filterable,
                    skipped: false,
                );
            }
        }

        return array_values($rows);
    }

    /**
     * Category rows (with the skipped flag) followed by the extras: the given
     * attributes that the category chain doesn't assign.
     *
     * @param  list<int>  $skippedIds  attributes the product skips
     * @param  list<int>  $extraIds  attributes to show besides the category's (e.g. those with values)
     * @return list<ResolvedAttribute>
     */
    public function resolve(?int $categoryId, array $skippedIds = [], array $extraIds = []): array
    {
        $rows = [];

        foreach ($this->categoryAttributes($categoryId) as $row) {
            $rows[$row->attribute->id] = new ResolvedAttribute(
                attribute: $row->attribute,
                categoryId: $row->categoryId,
                required: $row->required,
                filterable: $row->filterable,
                skipped: ! $row->required && in_array($row->attribute->id, $skippedIds, true),
            );
        }

        $extraIds = array_values(array_unique(array_filter(
            array_map('intval', $extraIds),
            fn (int $id): bool => ! isset($rows[$id]),
        )));

        $extras = $this->attributes($extraIds);

        foreach ($extraIds as $id) {
            if (isset($extras[$id])) {
                $rows[$id] = new ResolvedAttribute(attribute: $extras[$id], categoryId: null, required: false, filterable: false, skipped: false);
            }
        }

        return array_values($rows);
    }

    /**
     * The saved spec rows of a product, optionally against another category
     * (a category change: values outside the new chain become extras).
     *
     * @return list<ResolvedAttribute>
     */
    public function forProduct(Product $product, ?int $categoryId = null): array
    {
        $skippedIds = $product->attributeSkips()->pluck('attribute_id')->map(fn ($id): int => (int) $id)->all();
        $valueIds = $product->attributeValues()->pluck('attribute_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();

        return $this->resolve($categoryId ?? $product->category_id, $skippedIds, $valueIds);
    }

    /**
     * Required attribute ids of a category (including inherited ones).
     *
     * @return list<int>
     */
    public function requiredIds(?int $categoryId): array
    {
        return array_values(array_map(
            fn (ResolvedAttribute $row): int => $row->attribute->id,
            array_filter($this->categoryAttributes($categoryId), fn (ResolvedAttribute $row): bool => $row->required),
        ));
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<int, Attribute>
     */
    private function attributes(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Attribute::query()
            ->with('options')
            ->whereIn('id', array_unique(array_map('intval', $ids)))
            ->get()
            ->keyBy('id')
            ->all();
    }
}
