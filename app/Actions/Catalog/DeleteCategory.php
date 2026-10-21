<?php

namespace App\Actions\Catalog;

use App\Models\Category;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Delete a category with its image and attribute assignments. Blocked while
 * it has subcategories or products (move them first).
 */
class DeleteCategory
{
    /**
     * @throws ValidationException
     */
    public function handle(Category $category): void
    {
        try {
            DB::transaction(function () use ($category): void {
                if (Category::query()->where('parent_id', $category->id)->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['category' => __('admin.categories.errors.has_children')]);
                }

                if ($category->hasProducts()) {
                    throw ValidationException::withMessages(['category' => __('admin.categories.errors.has_products')]);
                }

                $category->delete();
            });
        } catch (QueryException $exception) {
            // A row added concurrently (restrict FK on parent_id, products in 3c).
            report($exception);

            throw ValidationException::withMessages(['category' => __('admin.categories.errors.has_children')]);
        }
    }
}
