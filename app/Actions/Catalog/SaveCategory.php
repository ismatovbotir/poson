<?php

namespace App\Actions\Catalog;

use App\Models\Category;
use App\Support\Catalog\CategoryTree;
use App\Support\HtmlSanitizer;
use App\Support\Slug;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create or update a category (fields only; attributes go through
 * SyncCategoryAttributes, the image through UpdateCatalogImage).
 *
 * Guards the tree (plan 03 D4): no cycles and at most CategoryTree::MAX_DEPTH
 * levels, including the category's own subtree. The slug (D5) is generated
 * once from the en name, else uz, and never changes after that (incoming
 * slugs are ignored); descriptions are sanitized (D12).
 */
class SaveCategory
{
    /**
     * @param  array{
     *     parent_id: int|null,
     *     name: array<string, string|null>,
     *     description: array<string, string|null>,
     *     meta_title: array<string, string|null>,
     *     meta_description: array<string, string|null>,
     *     icon: string|null,
     *     sort: int|null,
     *     is_active: bool
     * }  $data
     *
     * @throws ValidationException
     */
    public function __construct(private SyncCategoryAttributes $syncAttributes) {}

    public function handle(?Category $category, array $data): Category
    {
        $category ??= new Category;
        $parentId = $data['parent_id'] ?? null;

        if (trim((string) ($data['name']['uz'] ?? '')) === '') {
            throw ValidationException::withMessages(['name.uz' => __('validation.required', ['attribute' => __('admin.catalog.name').' (UZ)'])]);
        }

        return DB::transaction(function () use ($category, $data, $parentId): Category {
            // Re-read the tree inside the transaction, with the categories locked, so
            // the cycle / depth check never runs against a stale cached tree.
            Category::query()->lockForUpdate()->pluck('id');
            CategoryTree::flush();

            if ($error = CategoryTree::parentError($category->exists ? $category->id : null, $parentId)) {
                throw ValidationException::withMessages(['parent_id' => $error]);
            }

            $parentChanged = $category->exists && $category->parent_id !== $parentId;

            $category->storeTranslations('name', $data['name']);
            $category->storeTranslations('description', array_map(HtmlSanitizer::cleanOrNull(...), $data['description']));
            $category->storeTranslations('meta_title', $data['meta_title']);
            $category->storeTranslations('meta_description', $data['meta_description']);

            $category->fill([
                'parent_id' => $parentId,
                'slug' => $this->slug($category, $data),
                'icon' => in_array($data['icon'] ?? null, Category::ICONS, true) ? $data['icon'] : null,
                'sort' => $data['sort'] ?? $this->nextSort($category, $parentId),
                'is_active' => $data['is_active'],
            ])->save();

            if ($parentChanged) {
                // Attributes the new ancestors already give must not repeat in this subtree.
                $this->syncAttributes->pruneSubtree($category);
            }

            return $category;
        });
    }

    /**
     * The stored slug once there is one (never changed, whatever the input);
     * otherwise a unique slug from the en name, else uz. Incoming slugs are ignored.
     *
     * @param  array{name: array<string, string|null>}  $data
     */
    private function slug(Category $category, array $data): string
    {
        $stored = (string) $category->getOriginal('slug');

        if ($category->exists && $stored !== '') {
            return $stored;
        }

        $ignoreId = $category->exists ? $category->id : null;
        $source = filled($data['name']['en'] ?? null) ? $data['name']['en'] : ($data['name']['uz'] ?? '');

        return Slug::uniqueFor(Category::class, Slug::make($source), $ignoreId);
    }

    private function nextSort(Category $category, ?int $parentId): int
    {
        if ($category->exists && $category->parent_id === $parentId) {
            return $category->sort;
        }

        return (int) Category::query()->where('parent_id', $parentId)->max('sort') + 1;
    }
}
