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

it('keeps the query string on the home page', function () {
    $this->get('/ru?utm_source=telegram')
        ->assertOk()
        ->assertSee(url('/en').'?utm_source=telegram', false);
});

it('falls back to the localized home page outside localized routes', function () {
    Route::get('/plain-page', fn () => LocaleUrl::switch('ru'));

    $this->get('/plain-page')->assertSeeText(url('/ru'));
});
