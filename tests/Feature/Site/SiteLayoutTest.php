<?php

use App\Livewire\Site\Home;
use App\Models\Setting;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['web', 'locale'])
        ->prefix('{locale}')
        ->whereIn('locale', config('app.supported_locales'))
        ->group(function () {
            Route::livewire('/layout-test/{slug}', Home::class)->name('site.layout-test');
        });

    app('router')->getRoutes()->refreshNameLookups();
});

/**
 * @return list<array{0: string, 1: string}> [hreflang, href]
 */
function hreflangLinks(string $html): array
{
    preg_match_all('/<link rel="alternate" hreflang="([^"]+)" href="([^"]+)">/', $html, $matches, PREG_SET_ORDER);

    return array_map(fn (array $match): array => [$match[1], html_entity_decode($match[2])], $matches);
}

it('renders the site layout with localized navigation', function (string $locale, array $texts) {
    $this->get("/{$locale}")
        ->assertOk()
        ->assertSee('<html lang="'.$locale.'">', false)
        ->assertSee('id="site-drawer"', false)
        ->assertSee('id="main"', false)
        ->assertSeeText($texts);
})->with([
    'uz' => ['uz', ['Skanerlar', 'Yorliq printerlari', 'Xizmatlar', 'Katalog', 'Asosiy mazmunga oʻtish']],
    'ru' => ['ru', ['Сканеры', 'Принтеры этикеток', 'Услуги', 'Каталог', 'Перейти к содержимому']],
    'en' => ['en', ['Scanners', 'Label printers', 'Services', 'Catalog', 'Skip to content']],
]);

it('shows company contacts from the settings', function () {
    Setting::factory()->setting('phones', [['label' => 'orders', 'number' => '90 123 45 67', 'primary' => true, 'site' => true]])->create();
    Setting::factory()->setting('email', 'sales@poson.uz')->create();

    $this->get('/ru')
        ->assertOk()
        ->assertSee('href="tel:+998901234567"', false)
        ->assertSeeText('+998 (90) 123-45-67')
        ->assertSee('mailto:sales@poson.uz', false)
        ->assertSee('https://t.me/poson_uz', false)
        ->assertSeeText('Пн–Сб 9:00–18:00');
});

it('renders the title, description and canonical for the page', function () {
    $this->get('/en?utm_source=telegram')
        ->assertOk()
        ->assertSee('<title>'.e(__('site.seo.home.title', [], 'en')).'</title>', false)
        ->assertSee('<meta name="description" content="'.e(__('site.seo.home.description', [], 'en')).'">', false)
        ->assertSee('<link rel="canonical" href="'.url('/en').'">', false)
        ->assertSee('<meta property="og:locale" content="en_US">', false)
        ->assertSee('<link rel="icon" type="image/svg+xml" href="'.asset('assets/site/logo.svg').'">', false);
});

it('points x-default to the default locale on every locale', function (string $locale) {
    $html = $this->get("/{$locale}")->assertOk()->getContent();

    expect(hreflangLinks($html))->toContain(['x-default', url('/uz')]);
})->with(['uz', 'ru', 'en']);

it('lists hreflang alternates for every locale plus x-default without tracking params', function () {
    $html = $this->get('/ru/layout-test/zebra-zd421?utm_source=telegram&utm_medium=post&page=2')
        ->assertOk()
        ->getContent();

    expect(hreflangLinks($html))->toBe([
        ['uz', url('/uz/layout-test/zebra-zd421').'?page=2'],
        ['ru', url('/ru/layout-test/zebra-zd421').'?page=2'],
        ['en', url('/en/layout-test/zebra-zd421').'?page=2'],
        ['x-default', url('/uz/layout-test/zebra-zd421').'?page=2'],
    ])
        ->and($html)->toContain('<link rel="canonical" href="'.url('/ru/layout-test/zebra-zd421').'?page=2">');
});

it('keeps the path and query in the language switcher links', function () {
    $html = $this->get('/ru/layout-test/zebra-zd421?brand=zebra&page=2')
        ->assertOk()
        ->getContent();

    preg_match_all('/<a\s+href="([^"]+)"\s+hreflang="(uz|ru|en)"/', $html, $matches, PREG_SET_ORDER);

    $links = collect($matches)->map(fn (array $match): array => [$match[2], html_entity_decode($match[1])]);

    // Topbar and mobile drawer each render one switcher.
    expect($links)->toHaveCount(6)
        ->and($links->unique()->values()->all())->toBe([
            ['uz', url('/uz/layout-test/zebra-zd421').'?brand=zebra&page=2'],
            ['ru', url('/ru/layout-test/zebra-zd421').'?brand=zebra&page=2'],
            ['en', url('/en/layout-test/zebra-zd421').'?brand=zebra&page=2'],
        ])
        ->and(preg_match_all('/data-locale-link="ru"\s+aria-current="true"/', $html))->toBe(2)
        ->and($html)->not->toMatch('/data-locale-link="(uz|en)"\s+aria-current/');
});

it('contains no discount or low-price wording', function (string $locale) {
    expectNoBannedWording($this->get("/{$locale}")->assertOk()->getContent());
})->with(['uz', 'ru', 'en']);

it('marks up the mobile drawer as an accessible modal dialog', function () {
    $html = $this->get('/uz')->assertOk()->getContent();

    expect($html)
        ->toContain('id="site-drawer"')
        ->toContain('role="dialog"')
        ->toContain('aria-modal="true"')
        ->toContain('aria-labelledby="site-drawer-title"')
        ->toContain('x-trap.inert.noscroll="menuOpen"')
        ->toContain('aria-controls="site-drawer"')
        ->toContain('x-bind:aria-expanded="menuOpen.toString()"')
        ->toContain('x-on:keydown.escape.window="menuOpen = false"')
        ->toContain('<span class="sr-only">'.__('site.header.menu_close', [], 'uz').'</span>');
});

it('switches nav links from home anchors to real routes once they exist', function () {
    expect($this->get('/ru')->getContent())->toContain('href="'.url('/ru').'#services"');

    Route::middleware(['web', 'locale'])
        ->prefix('{locale}')
        ->whereIn('locale', config('app.supported_locales'))
        ->get('/services', fn () => 'services')
        ->name('site.services.index');
    app('router')->getRoutes()->refreshNameLookups();

    $html = $this->get('/ru')->assertOk()->getContent();

    expect($html)->toContain('href="'.url('/ru/services').'"')
        ->not->toContain('href="'.url('/ru').'#services"');
});

it('renders the header search form pointing at the search page', function () {
    $this->get('/ru')
        ->assertOk()
        ->assertSee('role="search"', false)
        ->assertSee('action="'.url('/ru/search').'"', false)
        ->assertSee('name="q"', false);
});

it('leaves out favorites, login and the cart', function () {
    $this->get('/ru')
        ->assertOk()
        ->assertDontSee('#i-heart', false)
        ->assertDontSee('#i-user', false)
        ->assertDontSee('#i-cart', false)
        ->assertDontSeeText('Избранное')
        ->assertDontSeeText('Войти')
        ->assertDontSeeText('Корзина');
});
