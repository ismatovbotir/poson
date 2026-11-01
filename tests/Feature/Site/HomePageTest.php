<?php

use App\Enums\ProductBadge;
use App\Enums\SaleMode;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Support\CompanySettings;
use App\Support\Money;
use App\Support\Site\HomeContent;

it('renders the localized home page with its sections', function (string $locale) {
    $response = $this->get("/{$locale}")->assertOk();

    $response
        ->assertSeeText(__('site.home.hero.title_highlight', [], $locale))
        ->assertSeeText(__('site.home.b2b.title', [], $locale))
        ->assertSeeText(__('site.home.why.title', [], $locale))
        ->assertSeeText(__('site.home.services.title', [], $locale))
        ->assertSeeText(__('site.home.lead.title', [], $locale))
        ->assertSee('id="services"', false)
        ->assertSee('id="lead"', false)
        ->assertSee('<title>'.e(__('site.seo.home.title', [], $locale)).'</title>', false)
        ->assertSee('<meta name="description" content="'.e(__('site.seo.home.description', [], $locale)).'">', false)
        ->assertSeeLivewire('site.lead-form');
})->with(['uz', 'ru', 'en']);

it('shows the hero stats from the company settings', function () {
    app()->setLocale('en');
    $stats = app(CompanySettings::class)->heroStats();

    expect($stats)->not->toBeEmpty();

    $this->get('/en')->assertOk()->assertSeeText(array_column($stats, 'value'));
});

it('links the hero and header catalog buttons to the catalog', function () {
    $html = $this->get('/ru')->assertOk()->getContent();

    expect(substr_count($html, 'href="'.url('/ru/catalog').'"'))->toBeGreaterThanOrEqual(2)
        ->and($html)->not->toContain(url('/ru').'#categories');
});

it('shows visible top-level categories with the published products of their subtree', function () {
    $scanners = Category::factory()->create(['name' => ['uz' => 'Skanerlar', 'ru' => 'Сканеры', 'en' => 'Scanners'], 'sort' => 1, 'icon' => 'scan']);
    $handheld = Category::factory()->child($scanners)->create();
    $printers = Category::factory()->create(['name' => ['uz' => 'Printerlar', 'ru' => 'Принтеры', 'en' => 'Printers'], 'sort' => 2]);
    Category::factory()->inactive()->create(['name' => ['uz' => 'Yashirin', 'ru' => 'Скрытая', 'en' => 'Hidden']]);

    Product::factory()->inCategory($scanners)->create();
    Product::factory()->count(2)->inCategory($handheld)->create();
    Product::factory()->inactive()->inCategory($handheld)->create();
    Product::factory()->inCategory($handheld)->create()->delete();

    app()->setLocale('ru');
    $tiles = app(HomeContent::class)->categories();

    expect(array_map(fn ($tile) => [$tile->name, $tile->productsCount, $tile->icon], $tiles))->toBe([
        ['Сканеры', 3, 'scan'],
        ['Принтеры', 0, HomeContent::DEFAULT_ICON],
    ]);

    $this->get('/ru')->assertOk()
        ->assertSeeText(__('site.home.categories.title', [], 'ru'))
        ->assertSee('href="'.url('/ru/catalog/'.$scanners->slug).'"', false)
        ->assertDontSeeText('Скрытая');
});

it('shows at most eight featured published products, in sort order', function () {
    $featured = Product::factory()->count(9)->featured()->sequence(fn ($sequence) => ['sort' => $sequence->index])->create();
    Product::factory()->create(['name' => ['uz' => 'Oddiy', 'ru' => 'Обычный', 'en' => 'Regular']]);
    Product::factory()->featured()->inactive()->create(['name' => ['uz' => 'Qoralama', 'ru' => 'Черновик', 'en' => 'Draft']]);

    $html = $this->get('/en')->assertOk()->getContent();

    expect(substr_count($html, 'data-product-card'))->toBe(8)
        ->and($html)->toContain(__('site.home.popular.title', [], 'en'))
        ->and($html)->toContain(e($featured[0]->getTranslation('name', 'en')))
        ->and($html)->not->toContain(e($featured[8]->getTranslation('name', 'en')))
        ->and($html)->not->toContain('Regular')
        ->and($html)->not->toContain('Draft');
});

it('gives cards the quote CTA while the cart is off, and the cart CTA once it is on', function () {
    Product::factory()->featured()->create(['sale_mode' => SaleMode::Both, 'price' => 125_000_000]);
    Product::factory()->featured()->quoteOnly()->create();
    Product::factory()->featured()->priceOnRequest()->create();

    $html = $this->get('/en')->assertOk()->getContent();

    expect(substr_count($html, 'data-cta="quote"'))->toBe(3)
        ->and($html)->not->toContain('data-cta="cart"')
        ->and($html)->toContain(__('site.card.price_on_request', [], 'en'))
        ->and($html)->toContain('1'.Money::NBSP.'250'.Money::NBSP.'000');

    config(['site.cart_enabled' => true]);

    preg_match_all('#<article[^>]*data-mode="(cart|quote|both)"[^>]*>(.*?)</article>#s', $this->get('/en')->getContent(), $cards, PREG_SET_ORDER);

    expect($cards)->toHaveCount(3);

    foreach ($cards as [, $saleMode, $card]) {
        expect($card)->toContain($saleMode === 'quote' ? 'data-cta="quote"' : 'data-cta="cart"');
    }
});

it('never renders old prices or sale tags on product cards', function (string $locale) {
    Product::factory()->featured()->create();
    Product::factory()->featured()->create(['badge' => ProductBadge::New]);

    $html = $this->get("/{$locale}")->assertOk()->getContent();

    expect($html)->not->toMatch('#<(s|del|strike)[\s>]#i')
        ->not->toContain('line-through')
        ->not->toContain('old_price')
        ->not->toContain('compare_at')
        ->not->toMatch('/[−-]\d+\s?%/u')
        ->and(preg_match_all('/data-badge="([^"]+)"/', $html, $badges))->toBeGreaterThan(0)
        ->and(array_diff($badges[1], ['hit', 'new']))->toBeEmpty();

    expectNoBannedWording($html);
})->with(['uz', 'ru', 'en']);

it('lists the brands with visible products, linking to their brand pages', function () {
    $category = Category::factory()->create();
    $zebra = Brand::factory()->create(['name' => 'Zebra', 'slug' => 'zebra', 'sort' => 1]);
    Product::factory()->inCategory($category)->for($zebra)->create();
    $hidden = Brand::factory()->inactive()->create(['name' => 'Hiddenbrand']);
    Product::factory()->inCategory($category)->for($hidden)->create();
    Brand::factory()->create(['name' => 'Nostockbrand']);

    $html = $this->get('/uz')->assertOk()->getContent();

    expect($html)->toContain('id="brands-title"')
        ->toContain('Zebra')
        ->toContain('href="'.url('/uz/brands/zebra').'"')
        ->not->toContain('Hiddenbrand')
        ->not->toContain('Nostockbrand');
});

it('hides the empty catalog sections', function () {
    $this->get('/uz')
        ->assertOk()
        ->assertDontSee('data-product-card', false)
        ->assertDontSeeText(__('site.home.popular.title', [], 'uz'))
        ->assertDontSeeText(__('site.home.categories.title', [], 'uz'))
        ->assertDontSee('id="brands-title"', false)
        ->assertSee('id="services"', false)
        ->assertSee('id="lead"', false);
});

it('renders the home page without lazy loading or per-product queries', function () {
    // A warm-up request first: settings and the category tree are cached after it.
    Product::factory()->count(2)->featured()->create();
    $this->get('/ru')->assertOk();
    $few = countQueries(fn () => $this->get('/ru')->assertOk());

    Product::factory()->count(6)->featured()->create();
    $this->get('/ru')->assertOk();
    $many = countQueries(fn () => $this->get('/ru')->assertOk());

    expect($many)->toBe($few);
});

it('adds valid Organization JSON-LD to the head', function () {
    $html = $this->get('/ru')->assertOk()->getContent();
    $company = app(CompanySettings::class);

    $organization = collect(jsonLdBlocks($html))->firstWhere('@type', 'Organization');

    expect($organization)->not->toBeNull()
        ->and($organization['@context'])->toBe('https://schema.org')
        ->and($organization['name'])->toBe('Poson')
        ->and($organization['url'])->toBe(url('/ru'))
        ->and($organization['logo'])->toBe(asset('assets/site/logo.svg'))
        ->and($organization['telephone'])->toBe($company->phone())
        ->and($organization['email'])->toBe($company->email())
        ->and($organization['address']['@type'])->toBe('PostalAddress')
        ->and($organization['address']['addressCountry'])->toBe('UZ')
        ->and($organization['address']['addressLocality'])->toBe('Коканд')
        ->and(strpos($html, 'application/ld+json'))->toBeLessThan(strpos($html, '</head>'));
});

it('escapes settings values inside the JSON-LD script', function () {
    Setting::factory()->setting('address', ['uz' => '</script><script>alert(1)</script>'])->create();

    $html = $this->get('/uz')->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)')
        ->and(collect(jsonLdBlocks($html))->firstWhere('@type', 'Organization')['address']['streetAddress'])
        ->toBe('</script><script>alert(1)</script>');
});

it('renders the hero scanner illustration with a translated accessible title', function (string $locale) {
    $this->get("/{$locale}")->assertOk()
        ->assertSee('role="img"', false)
        ->assertSee('<title>'.e(__('site.home.hero.scanner_alt', [], $locale)).'</title>', false)
        ->assertSee('id="hs-head"', false);
})->with(['uz', 'ru', 'en']);
