<?php

namespace App\Support\Catalog;

use App\Models\Category;
use Illuminate\Support\Facades\Cache;

/**
 * Cached category tree (plan 03 D4): every category as a plain array, so
 * parent/child/ancestor lookups and the admin selects need one query.
 *
 * The cache is flushed by Category's saved/deleted model events. Mass
 * updates (e.g. reordering) must call CategoryTree::flush() themselves.
 *
 * @phpstan-type Node array{id: int, parent_id: int|null, slug: string, name: array<string, string>, icon: string|null, sort: int, is_active: bool}
 */
class CategoryTree
{
    public const CACHE_KEY = 'catalog.category-tree';

    /**
     * Levels allowed: root (1), child (2), grandchild (3).
     */
    public const MAX_DEPTH = 3;

    /**
     * Per-request copy, so one request doesn't hit the cache store for every lookup.
     *
     * @var array<int, Node>|null
     */
    private static ?array $memo = null;

    /**
     * All categories keyed by id, ordered by sort then id.
     *
     * @return array<int, Node>
     */
    public static function nodes(): array
    {
        return static::$memo ??= Cache::rememberForever(self::CACHE_KEY, fn (): array => Category::query()
            ->orderBy('sort')
            ->orderBy('id')
            ->get(['id', 'parent_id', 'slug', 'name', 'icon', 'sort', 'is_active'])
            ->mapWithKeys(fn (Category $category): array => [$category->id => [
                'id' => $category->id,
                'parent_id' => $category->parent_id,
                'slug' => $category->slug,
                'name' => $category->getTranslations('name'),
                'icon' => $category->icon,
                'sort' => $category->sort,
                'is_active' => $category->is_active,
            ]])
            ->all());
    }

    public static function flush(): void
    {
        static::$memo = null;
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return Node|null
     */
    public static function find(int $id): ?array
    {
        return static::nodes()[$id] ?? null;
    }

    /**
     * Direct children of a category (null: the roots), in sort order.
     *
     * @return list<Node>
     */
    public static function children(?int $parentId): array
    {
        return array_values(array_filter(static::nodes(), fn (array $node): bool => $node['parent_id'] === $parentId));
    }

    /**
     * Ancestor ids, nearest first.
     *
     * @return list<int>
     */
    public static function ancestorIds(int $id): array
    {
        $nodes = static::nodes();
        $ids = [];
        $parentId = $nodes[$id]['parent_id'] ?? null;

        while ($parentId !== null && isset($nodes[$parentId]) && ! in_array($parentId, $ids, true)) {
            $ids[] = $parentId;
            $parentId = $nodes[$parentId]['parent_id'];
        }

        return $ids;
    }

    /**
     * All descendant ids (children, grandchildren, ...).
     *
     * @return list<int>
     */
    public static function descendantIds(int $id): array
    {
        $ids = [];

        foreach (static::children($id) as $child) {
            $ids[] = $child['id'];
            array_push($ids, ...static::descendantIds($child['id']));
        }

        return $ids;
    }

    /**
     * Level of a category: 1 for a root.
     */
    public static function depth(int $id): int
    {
        return count(static::ancestorIds($id)) + 1;
    }

    /**
     * Levels in the subtree starting at the category: 1 for a leaf.
     */
    public static function height(int $id): int
    {
        $height = 1;

        foreach (static::children($id) as $child) {
            $height = max($height, static::height($child['id']) + 1);
        }

        return $height;
    }

    /**
     * Every category in tree order (parents before their children) with its level.
     *
     * @return list<Node&array{depth: int}>
     */
    public static function flattened(?int $parentId = null, int $depth = 1): array
    {
        $rows = [];

        foreach (static::children($parentId) as $node) {
            $rows[] = [...$node, 'depth' => $depth];
            array_push($rows, ...static::flattened($node['id'], $depth + 1));
        }

        return $rows;
    }

    /**
     * Localized name of a category from the tree (falls back to uz).
     */
    public static function name(int $id, ?string $locale = null): string
    {
        $name = static::find($id)['name'] ?? [];

        return (string) ($name[$locale ?? app()->getLocale()] ?? null ?: ($name[config('app.default_locale', 'uz')] ?? ''));
    }

    /**
     * Breadcrumb path: "Scanners › Handheld scanners".
     */
    public static function path(int $id, ?string $locale = null): string
    {
        $ids = [...array_reverse(static::ancestorIds($id)), $id];

        return implode(' › ', array_map(fn (int $nodeId): string => static::name($nodeId, $locale), $ids));
    }

    /**
     * Why $parentId can't be the parent of $categoryId (null = allowed):
     * the category itself, one of its descendants (a cycle), or a level
     * deeper than MAX_DEPTH for the category or its subtree.
     */
    public static function parentError(?int $categoryId, ?int $parentId): ?string
    {
        if ($parentId === null) {
            return null;
        }

        if (static::find($parentId) === null) {
            return __('admin.categories.errors.parent_missing');
        }

        if ($categoryId !== null && ($parentId === $categoryId || in_array($parentId, static::descendantIds($categoryId), true))) {
            return __('admin.categories.errors.parent_cycle');
        }

        $height = $categoryId !== null && static::find($categoryId) !== null ? static::height($categoryId) : 1;

        if (static::depth($parentId) + $height > self::MAX_DEPTH) {
            return __('admin.categories.errors.too_deep', ['max' => self::MAX_DEPTH]);
        }

        return null;
    }
}
