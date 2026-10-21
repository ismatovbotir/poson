<?php

namespace App\Actions\Catalog;

use App\Models\Attribute;
use App\Support\Catalog\CategoryTree;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Delete a library attribute with its options and category assignments,
 * unless products have values for it. has_missing_specs is recomputed for
 * the products of the categories that assigned it.
 */
class DeleteAttribute
{
    public function __construct(private RecomputeSpecCompleteness $recompute) {}

    /**
     * @throws ValidationException
     */
    public function handle(Attribute $attribute): void
    {
        if ($attribute->hasValues()) {
            throw ValidationException::withMessages(['attribute' => __('admin.attributes.errors.in_use')]);
        }

        DB::transaction(function () use ($attribute): void {
            $categoryIds = $attribute->categories()->pluck('categories.id')->map(fn ($id): int => (int) $id)->all();

            $attribute->delete();

            // A removed required attribute may complete products of those categories.
            $affected = $categoryIds;

            foreach ($categoryIds as $categoryId) {
                array_push($affected, ...CategoryTree::descendantIds($categoryId));
            }

            $this->recompute->forCategories($affected);
        });
    }
}
