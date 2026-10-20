<?php

namespace App\Support\Site;

use App\Data\Site\BrandItem;
use App\Data\Site\CategoryTile;
use App\Data\Site\ProductCard;
use App\Data\Site\ServiceItem;
use App\Enums\ProductBadge;
use App\Enums\SaleMode;
use App\Enums\StockStatus;

/**
 * Content blocks of the home page.
 *
 * Until the catalog exists, categories, popular products and brands are demo
 * placeholders shown only when config('site.demo_content') is on; otherwise
 * they are empty and the home page hides those sections. The catalog
 * increment replaces them with Eloquent queries returning the same DTOs.
 */
class HomeContent
{
    /**
     * Category key => icon from x-site.icon.
     *
     * @var array<string, string>
     */
    protected const CATEGORY_ICONS = [
        'scanners' => 'scan',
        'label_printers' => 'print',
        'mobile_computers' => 'term',
        'labels_ribbons' => 'label',
        'rfid' => 'rfid',
        'pos' => 'pos',
    ];

    /**
     * @var list<string>
     */
    protected const SERVICES = ['warehouse', 'marking', 'repair'];

    public function demoEnabled(): bool
    {
        return (bool) config('site.demo_content', false);
    }

    /**
     * @return list<CategoryTile>
     */
    public function categories(): array
    {
        if (! $this->demoEnabled()) {
            return [];
        }

        // TODO(catalog): top-level active categories ordered by sort, with active product counts.
        $counts = ['scanners' => 86, 'label_printers' => 54, 'mobile_computers' => 32, 'labels_ribbons' => 310, 'rfid' => 27, 'pos' => 64];

        $tiles = [];

        foreach (Navigation::main() as $item) {
            if (! isset(self::CATEGORY_ICONS[$item['key']])) {
                continue;
            }

            $tiles[] = new CategoryTile(
                name: $item['label'],
                url: $item['url'],
                icon: self::CATEGORY_ICONS[$item['key']],
                productsCount: $counts[$item['key']] ?? null,
            );
        }

        return $tiles;
    }

    /**
     * @return list<ProductCard>
     */
    public function popularProducts(): array
    {
        if (! $this->demoEnabled()) {
            return [];
        }

        // TODO(catalog): Product::query()->active()->where('is_featured', true) with brand, category and first image.
        return [
            new ProductCard(
                name: $this->pick([
                    'uz' => 'Zebra DS2208 2D shtrix-kod skaneri, USB',
                    'ru' => 'Сканер штрих-кода Zebra DS2208, 2D, USB',
                    'en' => 'Zebra DS2208 2D barcode scanner, USB',
                ]),
                slug: 'zebra-ds2208-sr',
                sku: 'ZB-DS2208-SR',
                specs: $this->pick([
                    'uz' => '2D imager · USB · IP42',
                    'ru' => '2D-имиджер · USB · IP42',
                    'en' => '2D imager · USB · IP42',
                ]),
                price: 125_000_000,
                stockStatus: StockStatus::InStock,
                saleMode: SaleMode::Both,
                image: asset('assets/site/products/scanner.svg'),
                url: '#',
                badge: ProductBadge::Hit,
                brand: 'Zebra',
                category: __('site.nav.scanners'),
            ),
            new ProductCard(
                name: $this->pick([
                    'uz' => 'Zebra ZD421t termotransfer yorliq printeri, 203 dpi',
                    'ru' => 'Термотрансферный принтер этикеток Zebra ZD421t, 203 dpi',
                    'en' => 'Zebra ZD421t thermal transfer label printer, 203 dpi',
                ]),
                slug: 'zebra-zd421t-203dpi',
                sku: 'ZB-ZD421T-203',
                specs: $this->pick([
                    'uz' => '4" · 203 dpi · USB/Ethernet',
                    'ru' => '4" · 203 dpi · USB/Ethernet',
                    'en' => '4" · 203 dpi · USB/Ethernet',
                ]),
                price: null,
                stockStatus: StockStatus::OnOrder,
                saleMode: SaleMode::Quote,
                image: asset('assets/site/products/printer.svg'),
                url: '#',
                badge: ProductBadge::New,
                brand: 'Zebra',
                category: __('site.nav.label_printers'),
            ),
            new ProductCard(
                name: $this->pick([
                    'uz' => 'Urovo DT50 maʼlumot yigʻish terminali, Android 11',
                    'ru' => 'Терминал сбора данных Urovo DT50, Android 11',
                    'en' => 'Urovo DT50 mobile computer, Android 11',
                ]),
                slug: 'urovo-dt50-4g',
                sku: 'UR-DT50-4G',
                specs: $this->pick([
                    'uz' => '2D · Wi-Fi · 4G · IP67',
                    'ru' => '2D · Wi-Fi · 4G · IP67',
                    'en' => '2D · Wi-Fi · 4G · IP67',
                ]),
                price: 690_000_000,
                stockStatus: StockStatus::InStock,
                saleMode: SaleMode::Both,
                image: asset('assets/site/products/terminal.svg'),
                url: '#',
                brand: 'Urovo',
                category: __('site.nav.mobile_computers'),
            ),
            new ProductCard(
                name: $this->pick([
                    'uz' => 'Termoyorliqlar 58×40 mm, 700 dona, gilza 40 mm',
                    'ru' => 'Термоэтикетки 58×40 мм, 700 шт., втулка 40 мм',
                    'en' => 'Direct thermal labels 58×40 mm, 700 per roll, 40 mm core',
                ]),
                slug: 'thermal-labels-58x40-700',
                sku: 'LB-DT-5840-700',
                specs: $this->pick([
                    'uz' => 'Eko-termo · 1 rulon',
                    'ru' => 'Эко-термо · 1 рулон',
                    'en' => 'Eco thermal · 1 roll',
                ]),
                price: 1_950_000,
                stockStatus: StockStatus::InStock,
                saleMode: SaleMode::Cart,
                image: asset('assets/site/products/labels.svg'),
                url: '#',
                category: __('site.nav.labels_ribbons'),
            ),
        ];
    }

    /**
     * The company's services (always shown; copy lives in lang/site.php).
     *
     * @return list<ServiceItem>
     */
    public function services(): array
    {
        return array_map(
            fn (string $key): ServiceItem => new ServiceItem(
                key: $key,
                title: __("site.home.services.items.{$key}.title"),
                text: __("site.home.services.items.{$key}.text"),
                url: Navigation::service($key),
            ),
            self::SERVICES,
        );
    }

    /**
     * @return list<BrandItem>
     */
    public function brands(): array
    {
        if (! $this->demoEnabled()) {
            return [];
        }

        // TODO(catalog): Brand::query()->where('is_active', true)->orderBy('sort').
        $brands = ['Zebra' => 'zebra', 'Honeywell' => 'honeywell', 'Datalogic' => 'datalogic', 'Urovo' => 'urovo', 'TSC' => 'tsc', 'Sunmi' => 'sunmi'];

        $items = [];

        foreach ($brands as $name => $slug) {
            $items[] = new BrandItem(name: $name, slug: $slug, url: '#');
        }

        return $items;
    }

    /**
     * Value for the current locale from a {uz, ru, en} array, falling back to uz.
     *
     * @param  array<string, string>  $values
     */
    protected function pick(array $values): string
    {
        return $values[app()->getLocale()] ?? $values['uz'];
    }
}
