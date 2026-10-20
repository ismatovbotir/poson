<?php

use App\Support\LocaleUrl;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['web', 'locale'])
        ->prefix('{locale}')
        ->whereIn('locale', config('app.supported_locales'))
        ->group(function () {
            Route::get('/test-page/{slug}', fn () => response()->json([
                'switch' => LocaleUrl::switch('en'),
                'helper' => localized_url('uz'),
                'alternates' => LocaleUrl::alternates(),
                'canonical' => LocaleUrl::canonical(),
            ]))->name('site.test-page');
        });

    app('router')->getRoutes()->refreshNameLookups();
});

it('swaps the locale and keeps the path and query string', function () {
    $this->get('/ru/test-page/zebra-zd421?brand=zebra&page=2')
        ->assertOk()
        ->assertJson([
            'switch' => url('/en/test-page/zebra-zd421').'?brand=zebra&page=2',
            'helper' => url('/uz/test-page/zebra-zd421').'?brand=zebra&page=2',
        ]);
});

it('builds alternates for every supported locale', function () {
    $alternates = $this->get('/uz/test-page/ribbon')->json('alternates');

    expect($alternates)->toBe([
        'uz' => url('/uz/test-page/ribbon'),
        'ru' => url('/ru/test-page/ribbon'),
        'en' => url('/en/test-page/ribbon'),
    ]);
});

it('drops the query string except page from clean alternates', function () {
    $response = $this->get('/ru/test-page/ribbon?utm_source=telegram&brand=zebra&page=3&sort=price');

    expect($response->json('alternates'))->toBe([
        'uz' => url('/uz/test-page/ribbon').'?page=3',
        'ru' => url('/ru/test-page/ribbon').'?page=3',
        'en' => url('/en/test-page/ribbon').'?page=3',
    ])
        ->and($response->json('canonical'))->toBe(url('/ru/test-page/ribbon').'?page=3')
        ->and($response->json('switch'))->toBe(url('/en/test-page/ribbon').'?utm_source=telegram&brand=zebra&page=3&sort=price');
});

it('drops invalid or first page numbers from clean URLs', function (string $query) {
    $this->get('/uz/test-page/ribbon?'.$query)
        ->assertJsonPath('canonical', url('/uz/test-page/ribbon'));
})->with([
    'page 1' => 'page=1',
    'page 0' => 'page=0',
    'not a number' => 'page=abc',
    'array' => 'page[]=2',
]);

it('resolves the default locale from the configured app locale', function (string $configured, string $expected) {
    config(['app.default_locale' => $configured]);

    expect(LocaleUrl::defaultLocale())->toBe($expected);
})->with([
    'supported' => ['ru', 'ru'],
    'unsupported falls back to the first supported' => ['de', 'uz'],
]);

it('keeps the query string on the home page', function () {
    $this->get('/ru?utm_source=telegram')
        ->assertOk()
        ->assertSee(url('/en').'?utm_source=telegram', false);
});

it('falls back to the localized home page outside localized routes', function () {
    Route::get('/plain-page', fn () => LocaleUrl::switch('ru'));

    $this->get('/plain-page')->assertSeeText(url('/ru'));
});
