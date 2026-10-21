<?php

use App\Models\Category;
use App\Support\Site\Navigation;
use Database\Seeders\CatalogSeeder;

it('builds the main menu from the visible top-level categories, with Services last', function (string $locale) {
    $this->seed(CatalogSeeder::class);
    Category::query()->where('slug', 'rfid')->firstOrFail()->update(['is_active' => false]);
    app()->setLocale($locale);

    $items = Navigation::main();
    $services = end($items);

    expect(array_column($items, 'key'))->toBe(['scanners', 'label-printers', 'mobile-computers', 'labels-ribbons', 'pos', 'software', 'services'])
        ->and($items[0]['label'])->toBe(Category::query()->where('slug', 'scanners')->first()->getTranslation('name', $locale))
        ->and($services['accent'])->toBeTrue()
        ->and($services['label'])->toBe(__('site.nav.services', [], $locale))
        ->and($services['url'])->toBe(url("/{$locale}/catalog/services"));
})->with(['uz', 'ru', 'en']);

it('resolves every menu and footer link of the site', function (string $locale) {
    $this->seed(CatalogSeeder::class);

    $html = $this->get("/{$locale}")->assertOk()->getContent();
    app()->setLocale($locale);

    $urls = collect(Navigation::main())->pluck('url')
        ->merge(collect(Navigation::footerCatalog())->pluck('url'))
        ->push(Navigation::catalog())
        ->unique();

    foreach ($urls as $url) {
        expect($html)->toContain('href="'.$url.'"');
        $this->get($url)->assertOk();
    }

    expect($html)->not->toContain('#categories');
})->with(['uz', 'ru', 'en']);

it('falls back to the catalog index while no categories are visible', function () {
    app()->setLocale('ru');

    $items = Navigation::main();

    expect(array_column($items, 'label'))->toContain(__('site.nav.scanners', [], 'ru'), __('site.nav.services', [], 'ru'))
        ->and(collect($items)->where('accent', false)->pluck('url')->unique()->all())->toBe([url('/ru/catalog')]);
});

it('updates the menu when a category changes', function () {
    $this->seed(CatalogSeeder::class);
    app()->setLocale('en');

    expect(array_column(Navigation::main(), 'key'))->toContain('pos');

    Category::query()->where('slug', 'pos')->firstOrFail()->update(['is_active' => false]);

    expect(array_column(Navigation::main(), 'key'))->not->toContain('pos');
});
