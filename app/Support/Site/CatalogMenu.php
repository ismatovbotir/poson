<?php

namespace App\Support\Site;

use App\Models\Category;
use App\Support\Catalog\CategoryTree;

/**
 * Header "Catalog" dropdown: the visible top-level categories in admin sort
 * order, each with its visible subcategories (desktop second column).
 *
 * Built from the cached CategoryTree (flushed when a category is saved or
 * deleted), so rendering it costs no queries once the tree is cached.
 */
class CatalogMenu
{
    /**
     * Icon of a category without one (same as the home category tiles).
     */
    public const DEFAULT_ICON = HomeContent::DEFAULT_ICON;

    /**
     * @return list<array{id: int, label: string, url: string, icon: string, children: list<array{id: int, label: string, url: string}>}>
     */
    public static function items(): array
    {
        return array_map(fn (array $node): array => [
            'id' => $node['id'],
            'label' => CategoryTree::name($node['id']),
            'url' => static::url($node['slug']),
            'icon' => $node['icon'] ?: self::DEFAULT_ICON,
            'children' => array_map(fn (array $child): array => [
                'id' => $child['id'],
                'label' => CategoryTree::name($child['id']),
                'url' => static::url($child['slug']),
            ], CategoryTree::visibleChildren($node['id'])),
        ], CategoryTree::visibleChildren(null));
    }

    /**
     * The category of the current catalog page and its ancestors (nearest
     * first), for the active state. Empty outside a category page.
     *
     * @return list<int>
     */
    public static function currentTrail(): array
    {
        $category = request()->route('category');

        $id = match (true) {
            $category instanceof Category => $category->id,
            is_string($category) => collect(CategoryTree::nodes())->firstWhere('slug', $category)['id'] ?? null,
            default => null,
        };

        if ($id === null || ! request()->routeIs('site.catalog.category')) {
            return [];
        }

        return [$id, ...CategoryTree::ancestorIds($id)];
    }

    protected static function url(string $slug): string
    {
        return route('site.catalog.category', ['locale' => app()->getLocale(), 'category' => $slug]);
    }
}
