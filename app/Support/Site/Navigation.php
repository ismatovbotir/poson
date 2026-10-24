<?php

namespace App\Support\Site;

use App\Support\Catalog\CategoryTree;
use Illuminate\Support\Facades\Route;

/**
 * Public site menus (header nav, mobile drawer, footer).
 *
 * Main nav (decision 2026-10-24): Brands, B2B, Services (accent), Warranty &
 * service, Delivery & payment, News, About, Contacts. Categories live in the
 * header Catalog dropdown (CatalogMenu), not here. Page items appear only
 * while the page is active (PageDirectory, no anchor fallback) and use the
 * page's fixed short label (pageLabel(), as in the footer); News only while the locale has a
 * visible post (NewsDirectory). "Services" opens the services category
 * (/catalog/services) while it is a visible top-level category; the home
 * services teaser links to its subcategories.
 *
 * The footer "Catalog" column lists the visible top-level categories from
 * the cached CategoryTree (flushed when a category is saved). Footer and
 * topbar page links fall back to a section of the localized home page while
 * a page is inactive (PAGE_FALLBACK).
 */
class Navigation
{
    /**
     * Slug of the services category: the accented "Services" item.
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
     * Home page fragment used while a static page is inactive (slug => fragment, default "#contacts").
     *
     * @var array<string, string>
     */
    public const PAGE_FALLBACK = [
        'about' => '',
    ];

    /**
     * Main nav entries in display order: a page slug, or one of the
     * "@brands", "@services" and "@news" items.
     *
     * @var list<string>
     */
    public const MAIN = ['@brands', 'b2b', '@services', 'warranty-service', 'delivery-payment', '@news', 'about', 'contacts'];

    /**
     * Footer "Catalog" column: most categories listed.
     */
    public const FOOTER_CATEGORIES = 5;

    /**
     * Request attribute holding main() for the current request.
     */
    private const MAIN_MEMO = 'site.nav.main';

    /**
     * Main nav (desktop bar and mobile drawer). "current" is the
     * aria-current value: "page" on the item's own URL, "true" inside its
     * section (a brand page, a news post, a services subcategory), else null.
     *
     * Built once per request and locale (the bar and the drawer share it).
     * The copy lives on the request, not in once(): tests send several
     * requests in one process, and a page or news change between them must
     * show up.
     *
     * @return list<array{key: string, label: string, url: string, accent: bool, current: string|null}>
     */
    public static function main(): array
    {
        $key = self::MAIN_MEMO.'.'.app()->getLocale();
        $memo = request()->attributes->get($key);

        if (is_array($memo)) {
            return $memo;
        }

        $items = static::buildMain();
        request()->attributes->set($key, $items);

        return $items;
    }

    /**
     * @return list<array{key: string, label: string, url: string, accent: bool, current: string|null}>
     */
    protected static function buildMain(): array
    {
        $locale = app()->getLocale();
        $items = [];

        foreach (self::MAIN as $entry) {
            $item = match ($entry) {
                '@brands' => static::item(
                    'brands',
                    __('site.nav.brands'),
                    route('site.brands.index', ['locale' => $locale]),
                    request()->routeIs('site.brands.show'),
                ),
                '@services' => static::servicesItem(),
                '@news' => ($news = static::news()) !== null
                    ? static::item('news', $news['label'], $news['url'], request()->routeIs('site.news.show'))
                    : null,
                default => static::pageItem($entry),
            };

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @return array{key: string, label: string, url: string, accent: bool, current: string|null}
     */
    protected static function item(string $key, string $label, string $url, bool $inSection = false, bool $accent = false): array
    {
        $current = match (true) {
            url()->current() === $url => 'page',
            $inSection => 'true',
            default => null,
        };

        return ['key' => $key, 'label' => $label, 'url' => $url, 'accent' => $accent, 'current' => $current];
    }

    /**
     * A static page item while the page is active, with the page's fixed
     * short label (site.footer.*, the same label in every menu).
     *
     * @return array{key: string, label: string, url: string, accent: bool, current: string|null}|null
     */
    protected static function pageItem(string $slug): ?array
    {
        if (! PageDirectory::isActive($slug)) {
            return null;
        }

        return static::item($slug, static::pageLabel($slug), route('site.page', ['locale' => app()->getLocale(), 'page' => $slug]));
    }

    /**
     * Fixed short menu label of a static page: "delivery-payment" → site.footer.delivery_payment.
     */
    public static function pageLabel(string $slug): string
    {
        return __('site.footer.'.str_replace('-', '_', $slug));
    }

    /**
     * "Services": the services category while it is a visible top-level
     * category, else the services route or the home page services section.
     *
     * @return array{key: string, label: string, url: string, accent: bool, current: string|null}
     */
    protected static function servicesItem(): array
    {
        $services = null;

        foreach (CategoryTree::visibleChildren(null) as $node) {
            if ($node['slug'] === self::SERVICES_SLUG) {
                $services = $node;
                break;
            }
        }

        if ($services === null) {
            return static::item(self::SERVICES_SLUG, __('site.nav.services'), static::url('site.services.index', [], '#services'), accent: true);
        }

        return static::item(
            self::SERVICES_SLUG,
            __('site.nav.services'),
            route('site.catalog.category', ['locale' => app()->getLocale(), 'category' => $services['slug']]),
            in_array($services['id'], CatalogMenu::currentTrail(), true),
            accent: true,
        );
    }

    /**
     * Footer "Catalog" column: the visible top-level categories in admin
     * order (CategoryTree), without the services category, at most FOOTER_CATEGORIES.
     *
     * @return list<array{label: string, url: string}>
     */
    public static function footerCatalog(): array
    {
        $nodes = array_filter(
            CategoryTree::visibleChildren(null),
            fn (array $node): bool => $node['slug'] !== self::SERVICES_SLUG,
        );

        return array_values(array_map(
            fn (array $node): array => [
                'label' => CategoryTree::name($node['id']),
                'url' => route('site.catalog.category', ['locale' => app()->getLocale(), 'category' => $node['slug']]),
            ],
            array_slice($nodes, 0, self::FOOTER_CATEGORIES),
        ));
    }

    /**
     * Brands list URL (main nav, footer "Catalog" column).
     */
    public static function brands(): string
    {
        return route('site.brands.index', ['locale' => app()->getLocale()]);
    }

    /**
     * Footer "For customers" column and the topbar links; ends with News
     * while the locale has visible news.
     *
     * @return list<array{key: string, label: string, url: string}>
     */
    public static function customerPages(): array
    {
        $pages = [
            'about' => 'about',
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

        $news = static::news();

        if ($news !== null) {
            $items[] = ['key' => 'news', ...$news];
        }

        return $items;
    }

    /**
     * The news list link while the current locale has a visible post
     * (footer, topbar, mobile drawer), else null: no link to an empty list.
     *
     * @return array{label: string, url: string}|null
     */
    public static function news(): ?array
    {
        $locale = app()->getLocale();

        if (! NewsDirectory::hasVisible($locale)) {
            return null;
        }

        return ['label' => __('site.nav.news'), 'url' => route('site.news.index', ['locale' => $locale])];
    }

    /**
     * Footer legal links (privacy policy, public offer): only the active
     * pages, never a fallback anchor (decision 2026-10-23: the offer stays
     * hidden until the lawyer approves it).
     *
     * @return list<array{key: string, label: string, url: string}>
     */
    public static function legalPages(): array
    {
        $items = [];

        foreach (['privacy' => 'privacy', 'offer' => 'offer'] as $key => $slug) {
            if (PageDirectory::isActive($slug)) {
                $items[] = ['key' => $key, 'label' => __("site.footer.{$key}"), 'url' => static::page($slug)];
            }
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
     * Static page URL (about, delivery, privacy, ...) while the page is active,
     * else the home page section of PAGE_FALLBACK (the footer contacts block).
     */
    public static function page(string $slug): string
    {
        $locale = app()->getLocale();

        if (PageDirectory::isActive($slug)) {
            return route('site.page', ['locale' => $locale, 'page' => $slug]);
        }

        return route('site.home', ['locale' => $locale]).(self::PAGE_FALLBACK[$slug] ?? '#contacts');
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
