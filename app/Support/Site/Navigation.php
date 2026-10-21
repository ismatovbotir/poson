<?php

namespace App\Support\Site;

use App\Support\Catalog\CategoryTree;
use Illuminate\Support\Facades\Route;

/**
 * Public site menus (header nav, mobile drawer, footer).
 *
 * The main menu lists the visible top-level catalog categories from the
 * cached CategoryTree (flushed when a category is saved), then "Services",
 * which opens the services category (/catalog/services); the home services
 * teaser links to its subcategories. Static pages don't exist yet: until they
 * do, those links point to sections of the localized home page, and each
 * switches over once its named route exists.
 */
class Navigation
{
    /**
     * Slug of the services category: the right-aligned "Services" item.
     */
    public const SERVICES_SLUG = 'services';

    /**
     * Home services teaser key => subcategory of the services category.
     *
     * @var array<string, string>
     */
    public const SERVICE_CATEGORIES = [
        'warehouse' => 'setup-integration',
        'marking' => 'setup-integration',
        'repair' => 'repair-maintenance',
    ];

    /**
     * Menu labels used while the catalog has no visible categories.
     *
     * @var array<string, string> slug => lang key under site.nav
     */
    protected const FALLBACK = [
        'scanners' => 'scanners',
        'label-printers' => 'label_printers',
        'mobile-computers' => 'mobile_computers',
        'labels-ribbons' => 'labels_ribbons',
        'rfid' => 'rfid',
        'pos' => 'pos',
        'software' => 'software',
    ];

    /**
     * Main menu: catalog categories, then Services (the template's right-aligned slot).
     * "key" is the category slug.
     *
     * @return list<array{key: string, label: string, url: string, accent: bool}>
     */
    public static function main(): array
    {
        $locale = app()->getLocale();
        $items = [];
        $services = null;

        foreach (CategoryTree::visibleChildren(null) as $node) {
            $item = [
                'key' => $node['slug'],
                'label' => CategoryTree::name($node['id']),
                'url' => route('site.catalog.category', ['locale' => $locale, 'category' => $node['slug']]),
                'accent' => false,
            ];

            if ($node['slug'] === self::SERVICES_SLUG) {
                $services = $item['url'];
            } else {
                $items[] = $item;
            }
        }

        if ($items === []) {
            foreach (self::FALLBACK as $slug => $key) {
                $items[] = [
                    'key' => $slug,
                    'label' => __("site.nav.{$key}"),
                    'url' => route('site.catalog.index', ['locale' => $locale]),
                    'accent' => false,
                ];
            }
        }

        $items[] = [
            'key' => self::SERVICES_SLUG,
            'label' => __('site.nav.services'),
            'url' => $services ?? static::url('site.services.index', [], '#services'),
            'accent' => true,
        ];

        return $items;
    }

    /**
     * Footer "Catalog" column: the catalog categories of the main menu (at most five).
     *
     * @return list<array{label: string, url: string}>
     */
    public static function footerCatalog(): array
    {
        $items = array_filter(static::main(), fn (array $item): bool => ! $item['accent']);

        return array_values(array_map(
            fn (array $item): array => ['label' => $item['label'], 'url' => $item['url']],
            array_slice($items, 0, 5),
        ));
    }

    /**
     * Footer "For customers" column and the topbar links.
     *
     * @return list<array{key: string, label: string, url: string}>
     */
    public static function customerPages(): array
    {
        $pages = [
            'delivery_payment' => 'delivery-payment',
            'warranty_service' => 'warranty-service',
            'b2b' => 'b2b',
            'contacts' => 'contacts',
        ];

        $items = [];

        foreach ($pages as $key => $slug) {
            $items[] = [
                'key' => $key,
                'label' => __("site.footer.{$key}"),
                'url' => static::page($slug),
            ];
        }

        return $items;
    }

    /**
     * Catalog index URL (header "Catalog" button, hero CTA).
     */
    public static function catalog(): string
    {
        return route('site.catalog.index', ['locale' => app()->getLocale()]);
    }

    /**
     * Static page URL (about, delivery, privacy, ...); the footer contacts block until pages exist.
     */
    public static function page(string $slug): string
    {
        return static::url('site.page', ['slug' => $slug], '#contacts');
    }

    /**
     * Service teaser link: its services subcategory (SERVICE_CATEGORIES), else
     * the services category, else the home page lead form.
     */
    public static function service(string $key): string
    {
        foreach ([self::SERVICE_CATEGORIES[$key] ?? null, self::SERVICES_SLUG] as $slug) {
            $node = $slug !== null ? static::visibleCategory($slug) : null;

            if ($node !== null) {
                return route('site.catalog.category', ['locale' => app()->getLocale(), 'category' => $node['slug']]);
            }
        }

        return static::url('site.services.show', ['service' => $key], '#lead');
    }

    /**
     * A visible category from the tree by slug.
     *
     * @return array{id: int, slug: string}|null
     */
    protected static function visibleCategory(string $slug): ?array
    {
        foreach (CategoryTree::nodes() as $node) {
            if ($node['slug'] === $slug) {
                return CategoryTree::isVisible($node['id']) ? $node : null;
            }
        }

        return null;
    }

    /**
     * Quote request target (the lead form on the home page until a quote page exists).
     */
    public static function quote(): string
    {
        return static::url('site.quote', [], '#lead');
    }

    /**
     * Route URL when the route exists, otherwise the localized home page plus a fragment.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected static function url(string $route, array $parameters, string $fragment): string
    {
        if (Route::has($route)) {
            return route($route, $parameters);
        }

        return route('site.home', ['locale' => app()->getLocale()]).$fragment;
    }
}
