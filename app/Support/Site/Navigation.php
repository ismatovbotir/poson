<?php

namespace App\Support\Site;

use Illuminate\Support\Facades\Route;

/**
 * Public site menus (header nav, mobile drawer, footer).
 *
 * Catalog, service and static page routes don't exist yet: until they do,
 * links point to sections of the localized home page. Each item names the
 * route it will use, so it switches over automatically once that route exists.
 */
class Navigation
{
    /**
     * Main menu: catalog sections, then Services (the template's right-aligned slot).
     *
     * @return list<array{key: string, label: string, url: string, accent: bool}>
     */
    public static function main(): array
    {
        $categories = ['scanners', 'label_printers', 'mobile_computers', 'labels_ribbons', 'rfid', 'pos', 'software'];

        $items = [];

        foreach ($categories as $key) {
            $items[] = [
                'key' => $key,
                'label' => __("site.nav.{$key}"),
                'url' => static::url('site.catalog.category', ['category' => str_replace('_', '-', $key)], '#categories'),
                'accent' => false,
            ];
        }

        $items[] = [
            'key' => 'services',
            'label' => __('site.nav.services'),
            'url' => static::url('site.services.index', [], '#services'),
            'accent' => true,
        ];

        return $items;
    }

    /**
     * Footer "Catalog" column.
     *
     * @return list<array{label: string, url: string}>
     */
    public static function footerCatalog(): array
    {
        return array_values(array_map(
            fn (array $item): array => ['label' => $item['label'], 'url' => $item['url']],
            array_filter(static::main(), fn (array $item): bool => in_array($item['key'], ['scanners', 'label_printers', 'mobile_computers', 'labels_ribbons', 'software'], true)),
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
     * Static page URL (about, delivery, privacy, ...); the footer contacts block until pages exist.
     */
    public static function page(string $slug): string
    {
        return static::url('site.page', ['slug' => $slug], '#contacts');
    }

    /**
     * Service page URL; the home page lead form until service pages exist.
     */
    public static function service(string $slug): string
    {
        return static::url('site.services.show', ['service' => $slug], '#lead');
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
