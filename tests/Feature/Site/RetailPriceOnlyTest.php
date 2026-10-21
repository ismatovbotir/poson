<?php

use App\Enums\PriceLevel;
use App\Models\Category;
use App\Models\Product;
use App\Support\Money;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DemoProductsSeeder;

/**
 * Brand rule and D14: the public site shows the Retail price only. Dealer and
 * Wholesale prices never reach catalog, product, search or home HTML (including
 * JSON-LD) or the sitemap.
 */
it('never exposes Dealer or Wholesale prices on public pages or in the sitemap', function (string $locale) {
    $category = Category::factory()->create(['slug' => 'scanners']);
    $product = Product::factory()->featured()->inCategory($category)->create([
        'slug' => 'retail-only',
        'model' => 'RETAILTEST',
        'price' => 123_456_700,
        'price_dealer' => 987_654_300,
        'price_wholesale' => 876_543_200,
    ]);

    $pages = [
        "/{$locale}",
        "/{$locale}/catalog",
        "/{$locale}/catalog/scanners",
        "/{$locale}/catalog/scanners?sort=price_desc",
        "/{$locale}/product/retail-only",
        "/{$locale}/search?q=retailtest",
        '/sitemap.xml',
    ];

    foreach ($pages as $page) {
        $html = str_replace([Money::NBSP, "\u{202F}"], ' ', $this->get($page)->assertOk()->getContent());

        foreach (['9 876 543', '9876543', '98765430', '8 765 432', '8765432', '87654320'] as $hidden) {
            expect($html)->not->toContain($hidden, "{$page} shows a non-Retail price ({$hidden})");
        }

        if ($page !== '/sitemap.xml') {
            expect($html)->toContain('1 234 567');
        }
    }

    $offer = collect(jsonLdBlocks($this->get("/{$locale}/product/retail-only")->getContent()))->firstWhere('@type', 'Product')['offers'];

    expect($offer['price'])->toBe('1234567.00')
        ->and($product->priceFor(PriceLevel::Dealer))->toBe(987_654_300);
})->with(['uz', 'ru', 'en']);

it('uses no discount, sale or low-price wording on the public catalog pages', function (string $locale) {
    $this->seed([CatalogSeeder::class, DemoProductsSeeder::class]);

    $pages = ["/{$locale}", "/{$locale}/catalog", "/{$locale}/catalog?sort=price_asc", "/{$locale}/search?q=zebra", "/{$locale}/search?q=nothing"];

    foreach (Category::query()->whereNull('parent_id')->pluck('slug') as $slug) {
        $pages[] = "/{$locale}/catalog/{$slug}";
    }

    foreach (Product::query()->pluck('slug') as $slug) {
        $pages[] = "/{$locale}/product/{$slug}";
    }

    foreach ($pages as $page) {
        $html = $this->get($page)->assertOk()->getContent();

        expectNoBannedWording($html);
        expect($html)->not->toMatch('#<(s|del|strike)[\s>]#i')->not->toContain('line-through');
    }

    expectNoBannedWording(json_encode([__('site.catalog'), __('site.product'), __('site.search')], JSON_UNESCAPED_UNICODE));
})->with(['uz', 'ru', 'en']);
