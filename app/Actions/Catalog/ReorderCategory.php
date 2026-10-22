<?php

namespace App\Actions\Catalog;

use App\Models\Category;
use App\Support\Catalog\CategoryTree;
use App\Support\Site\LlmsTxt;
use Illuminate\Support\Facades\DB;

/**
 * Move a category to a position among its siblings (0 = first) and renumber
 * the siblings' sort 1, 2, 3, ... Categories never change parent here.
 */
class ReorderCategory
{
    public function handle(Category $category, int $position): void
    {
        DB::transaction(function () use ($category, $position): void {
            // Re-read the parent under a lock: only siblings of the current parent move,
            // and parent_id itself is never changed here.
            $parentId = Category::query()->whereKey($category->id)->lockForUpdate()->value('parent_id');

            $ids = Category::query()
                ->where('parent_id', $parentId)
                ->orderBy('sort')
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->reject(fn (int $id): bool => $id === $category->id)
                ->values()
                ->all();

            $position = max(0, min($position, count($ids)));
            array_splice($ids, $position, 0, [$category->id]);

            foreach ($ids as $index => $id) {
                Category::query()->whereKey($id)->update(['sort' => $index + 1]);
            }
        });

        // Mass updates skip model events.
        CategoryTree::flush();
        DB::afterCommit(function (): void {
            CategoryTree::flush();
            LlmsTxt::flush();
        });
    }
}
