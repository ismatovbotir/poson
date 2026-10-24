<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Page;
use App\Models\Product;
use App\Support\Site\Navigation;
use App\Support\Site\PageDirectory;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PagesSeeder;

/**
 * The main nav pages, active, with a title per locale.
 */
function navPages(): void
{
    foreach (['b2b' => 'B2B', 'warranty-service' => 'Kafolat', 'delivery-payment' => 'Yetkazish', 'about' => 'Biz haqimizda', 'contacts' => 'Kontaktlar'] as $slug => $title) {
        Page::factory()->system($slug)->create(['title' => ['uz' => $title, 'ru' => "RU {$title}", 'en' => "EN {$title}"]]);
    }
}

/**
 * The desktop main nav bar of a page.
 */
function mainNavHtml(string $html): string
{
    preg_match('#<nav class="hidden border-t border-line md:block".*?</nav>#s', $html, $matches);

    return $matches[0] ?? '';
}

it('lists the main nav items in order with fixed localized labels', function (string $locale, string $brands, string $services) {
    $this->seed(CatalogSeeder::class);
    navPages();
    NewsPost::factory()->create();
    app()->setLocale($locale);

    $items = Navigation::main();
    $page = fn (string $key): string => __("site.footer.{$key}", [], $locale);

    expect(array_column($items, 'key'))->toBe(['brands', 'b2b', 'services', 'warranty-service', 'delivery-payment', 'news', 'about', 'contacts'])
        ->and(array_column($items, 'label'))->toBe([$brands, $page('b2b'), $services, $page('warranty_service'), $page('delivery_payment'), __('site.nav.news', [], $locale), $page('about'), $page('contacts')])
        ->and(collect($items)->pluck('label')->intersect(['B2B', 'RU B2B', 'EN Kafolat', 'Kafolat'])->all())->toBe([])
        ->and(array_column($items, 'accent'))->toBe([false, false, true, false, false, false, false, false])
        ->and($items[0]['url'])->toBe(url("/{$locale}/brands"))
        ->and($items[1]['url'])->toBe(url("/{$locale}/page/b2b"))
        ->and($items[2]['url'])->toBe(url("/{$locale}/catalog/services"))
        ->and($items[5]['url'])->toBe(url("/{$locale}/news"));
})->with([
    'uz' => ['uz', 'Brendlar', 'Xizmatlar'],
    'ru' => ['ru', 'Бренды', 'Услуги'],
    'en' => ['en', 'Brands', 'Services'],
]);

it('no longer lists categories in the main nav', function () {
    $this->seed(CatalogSeeder::class);

    $nav = mainNavHtml($this->get('/en')->assertOk()->getContent());

    expect($nav)->toContain('data-nav="brands"')
        ->not->toContain(url('/en/catalog/scanners'))
        ->not->toContain('Scanners');
});

it('hides inactive pages from the main nav, without an anchor fallback', function () {
    navPages();
    Page::query()->whereIn('slug', ['b2b', 'contacts'])->update(['is_active' => false]);
    PageDirectory::flush();
    app()->setLocale('ru');

    $items = Navigation::main();

    expect(array_column($items, 'key'))->toBe(['brands', 'services', 'warranty-service', 'delivery-payment', 'about'])
        ->and(collect($items)->pluck('url')->filter(fn (string $url): bool => str_contains($url, '#'))->reject(fn (string $url): bool => str_ends_with($url, '#services'))->all())->toBe([]);

    $nav = mainNavHtml($this->get('/ru')->assertOk()->getContent());

    expect($nav)->not->toContain('/ru/page/b2b')->not->toContain('#contacts');
});

it('shows News only while the locale has visible news', function () {
    NewsPost::factory()->untranslated('ru', 'en')->create();
    NewsPost::factory()->draft()->create();

    app()->setLocale('uz');
    expect(array_column(Navigation::main(), 'key'))->toContain('news');

    app()->setLocale('en');
    expect(array_column(Navigation::main(), 'key'))->not->toContain('news');

    expect(mainNavHtml($this->get('/uz')->getContent()))->toContain('data-nav="news"')
        ->and(mainNavHtml($this->get('/en')->getContent()))->not->toContain('data-nav="news"');
});

it('marks the current item with aria-current', function () {
    navPages();

    $nav = mainNavHtml($this->get('/en/page/about')->assertOk()->getContent());

    expect($nav)->toMatch('#href="'.preg_quote(url('/en/page/about'), '#').'"\s+data-nav="about"\s+aria-current="page"#')
        ->and(substr_count($nav, 'aria-current='))->toBe(1);

    $nav = mainNavHtml($this->get('/en/brands')->assertOk()->getContent());

    expect($nav)->toMatch('#data-nav="brands"\s+aria-current="page"#');
});

it('keeps the categories in the footer catalog column and the mobile drawer', function (string $locale) {
    $this->seed(CatalogSeeder::class);
    Category::query()->where('slug', 'rfid')->firstOrFail()->update(['is_active' => false]);
    app()->setLocale($locale);

    $footer = Navigation::footerCatalog();

    expect(array_column($footer, 'url'))->toBe(array_map(fn (string $slug): string => url("/{$locale}/catalog/{$slug}"), ['scanners', 'label-printers', 'mobile-computers', 'labels-ribbons', 'pos']))
        ->and($footer[0]['label'])->toBe(Category::query()->where('slug', 'scanners')->first()->getTranslation('name', $locale));

    $html = $this->get("/{$locale}")->assertOk()->getContent();
    preg_match('#<nav aria-labelledby="footer-catalog">.*?</nav>#s', $html, $column);
    preg_match('#<ul class="[^"]*" data-drawer-catalog>.*?</ul>#s', $html, $drawer);

    expect($column[0])->toContain('href="'.url("/{$locale}/catalog/scanners").'"')
        ->toContain('href="'.url("/{$locale}/brands").'"')
        ->not->toContain('/catalog/services')
        ->and($drawer[0])->toContain('href="'.url("/{$locale}/catalog/scanners").'"')
        ->toContain('href="'.url("/{$locale}/catalog").'"')
        ->not->toContain('/catalog/rfid');
})->with(['uz', 'ru', 'en']);

it('updates the footer catalog when a category changes', function () {
    $this->seed(CatalogSeeder::class);
    app()->setLocale('en');

    expect(array_column(Navigation::footerCatalog(), 'url'))->toContain(url('/en/catalog/scanners'));

    Category::query()->where('slug', 'scanners')->firstOrFail()->update(['is_active' => false]);

    expect(array_column(Navigation::footerCatalog(), 'url'))->not->toContain(url('/en/catalog/scanners'));
});

it('resolves every menu and footer link of the seeded site', function (string $locale) {
    $this->seed(CatalogSeeder::class);
    $this->seed(PagesSeeder::class);

    $html = $this->get("/{$locale}")->assertOk()->getContent();
    app()->setLocale($locale);

    $urls = collect(Navigation::main())->pluck('url')
        ->merge(collect(Navigation::footerCatalog())->pluck('url'))
        ->push(Navigation::catalog(), Navigation::brands())
        ->unique();

    foreach ($urls as $url) {
        expect($html)->toContain('href="'.$url.'"');
        $this->get($url)->assertOk();
    }
})->with(['uz', 'ru', 'en']);

it('marks Brands and Services as the current section on their subpages', function () {
    $this->seed(CatalogSeeder::class);
    $category = Category::query()->where('slug', 'scanners')->firstOrFail();
    $brand = Brand::factory()->create(['slug' => 'nav-brand']);
    Product::factory()->inCategory($category)->for($brand)->create();

    $nav = mainNavHtml($this->get('/en/brands/nav-brand')->assertOk()->getContent());

    expect($nav)->toMatch('#data-nav="brands"\s+aria-current="true"#')
        ->and(substr_count($nav, 'aria-current='))->toBe(1);

    $nav = mainNavHtml($this->get('/en/catalog/setup-integration')->assertOk()->getContent());

    expect($nav)->toMatch('#data-nav="services"\s+aria-current="true"#')
        ->and(substr_count($nav, 'aria-current='))->toBe(1);
});

it('has no duplicate links in the drawer and none of the nav links in the topbar', function () {
    $this->seed(CatalogSeeder::class);
    navPages();
    NewsPost::factory()->create();

    $html = $this->get('/uz')->assertOk()->getContent();
    preg_match('#<div\s+id="site-drawer".*?</nav>\s*<nav aria-label="[^"]+">.*?</nav>#s', $html, $drawer);
    preg_match_all('#href="(http[^"]+)"#', $drawer[0], $links);
    preg_match('#<div class="bg-ink text-\[13px\].*?</div>\s*</div>#s', $html, $topbar);

    expect($links[1])->toBe(array_values(array_unique($links[1])))
        ->and($links[1])->toContain(url('/uz/catalog/services'), url('/uz/page/b2b'), url('/uz/news'))
        ->and(substr_count($drawer[0], 'href="'.url('/uz/catalog/services').'"'))->toBe(1)
        ->and($topbar[0])->not->toContain('/page/')
        ->not->toContain('/news')
        ->not->toContain('data-nav-news');
});
