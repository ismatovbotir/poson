<?php

namespace App\Actions\Catalog;

use App\Models\Banner;
use App\Models\Category;
use App\Support\Media\MediaReplacer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Delete a category with its image and attribute assignments. Blocked while
 * it has subcategories, products or banners (move or delete them first:
 * banners cascade in the database, which would leave their images behind).
 */
class DeleteCategory
{
    public function __construct(private MediaReplacer $replacer) {}

    /**
     * @throws ValidationException
     */
    public function handle(Category $category): void
    {
        try {
            DB::transaction(function () use ($category): void {
                Category::query()->whereKey($category->id)->lockForUpdate()->first();

                if (Category::query()->where('parent_id', $category->id)->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['category' => __('admin.categories.errors.has_children')]);
                }

                if ($category->hasProducts()) {
                    throw ValidationException::withMessages(['category' => __('admin.categories.errors.has_products')]);
                }

                if (Banner::query()->where('category_id', $category->id)->exists()) {
                    throw ValidationException::withMessages(['category' => __('admin.categories.errors.has_banners')]);
                }

                $this->replacer->clearAll($category);
                $category->deletePreservingMedia();
            });
        } catch (QueryException $exception) {
            // A row added concurrently (restrict FK on parent_id, products in 3c).
            report($exception);

            throw ValidationException::withMessages(['category' => __('admin.categories.errors.has_children')]);
        }
    }
}
