<?php

use App\Http\Middleware\SetLocale;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;

it('redirects the root to the default locale without Accept-Language', function () {
    $this->withHeader('Accept-Language', '')
        ->get('/')
        ->assertStatus(302)
        ->assertRedirect(route('site.home', ['locale' => 'uz']))
        ->assertHeader('Vary', 'Accept-Language, Cookie');
});

it('redirects the root by Accept-Language', function (string $header, string $locale) {
    $this->withHeader('Accept-Language', $header)
        ->get('/')
        ->assertRedirect(route('site.home', ['locale' => $locale]));
})->with([
    'ru' => ['ru-RU,ru;q=0.9,en;q=0.8', 'ru'],
    'en' => ['en-US,en;q=0.9', 'en'],
    'uz' => ['uz-UZ,uz;q=0.9', 'uz'],
    'unsupported falls back to uz' => ['de-DE,de;q=0.9', 'uz'],
]);

it('redirects to the configured locale when the browser language is unsupported', function () {
    config(['app.default_locale' => 'ru']);

    $this->withHeader('Accept-Language', 'de-DE,de;q=0.9')
        ->get('/')
        ->assertRedirect(route('site.home', ['locale' => 'ru']));
});

it('redirects to the configured locale without Accept-Language', function () {
    config(['app.default_locale' => 'en']);

    $this->withHeader('Accept-Language', '')
        ->get('/')
        ->assertRedirect(route('site.home', ['locale' => 'en']));
});

it('prefers the locale cookie over Accept-Language', function () {
    $this->withCookie('locale', 'en')
        ->withHeader('Accept-Language', 'ru-RU,ru;q=0.9')
        ->get('/')
        ->assertRedirect(route('site.home', ['locale' => 'en']));
});

it('ignores an unsupported locale cookie', function () {
    $this->withCookie('locale', 'de')
        ->withHeader('Accept-Language', 'ru')
        ->get('/')
        ->assertRedirect(route('site.home', ['locale' => 'ru']));
});

it('serves the home page in each supported locale', function (string $locale) {
    $this->get("/{$locale}")
        ->assertOk()
        ->assertSee('lang="'.$locale.'"', false)
        ->assertCookie('locale', $locale);

    expect(app()->getLocale())->toBe($locale);
})->with(['uz', 'ru', 'en']);

it('returns 404 for an unsupported locale', function (string $path) {
    $this->get($path)->assertNotFound();
})->with(['/de', '/uz_Latn', '/RU']);

it('uses the current locale as the default route parameter', function () {
    $this->get('/ru')->assertOk();

    expect(route('site.home'))->toBe(url('/ru'));
});

it('re-applies the locale on Livewire update requests', function () {
    expect(app(PersistentMiddleware::class)->getPersistentMiddleware())
        ->toContain(SetLocale::class);
});
