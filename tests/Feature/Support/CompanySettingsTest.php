<?php

use App\Models\Setting;
use App\Support\CompanySettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

function settings(): CompanySettings
{
    return app(CompanySettings::class);
}

it('is shared within a request', function () {
    expect(app(CompanySettings::class))->toBe(app(CompanySettings::class));
});

it('returns config defaults with an empty settings table', function () {
    expect(settings()->phone())->toBe('+998712000000')
        ->and(settings()->phoneDisplay())->toBe('+998 (71) 200-00-00')
        ->and(settings()->email())->toBe('info@poson.uz')
        ->and(settings()->telegramUrl())->toBe('https://t.me/poson_uz')
        ->and(settings()->social())->toBe([])
        ->and(settings()->get('missing', 'fallback'))->toBe('fallback');
});

it('resolves translatable values to the current locale', function (string $locale, string $city, string $hours) {
    app()->setLocale($locale);

    expect(settings()->city())->toBe($city)
        ->and(settings()->workingHours())->toBe($hours)
        ->and(settings()->address())->not->toBeEmpty();
})->with([
    ['uz', 'Toshkent', 'Du–Sh 9:00–18:00'],
    ['ru', 'Ташкент', 'Пн–Сб 9:00–18:00'],
    ['en', 'Tashkent', 'Mon–Sat 9:00–18:00'],
]);

it('resolves hero stats to the current locale', function () {
    app()->setLocale('ru');

    $stats = settings()->heroStats();

    expect($stats)->toHaveCount(3)
        ->and($stats[0])->toBe(['value' => '12 мес.', 'label' => 'гарантия']);
});

it('falls back to uz when a locale value is missing or empty', function () {
    Setting::factory()->setting('city', ['uz' => 'Samarqand', 'ru' => '', 'en' => null])->create();

    app()->setLocale('ru');
    expect(settings()->city())->toBe('Samarqand');

    app()->setLocale('en');
    expect(settings()->city())->toBe('Samarqand');
});

it('lets database rows override the defaults', function () {
    Setting::factory()->setting('phone', '90 123 45 67')->create();
    Setting::factory()->setting('email', 'sales@poson.uz')->create();

    expect(settings()->phone())->toBe('+998901234567')
        ->and(settings()->phoneDisplay())->toBe('+998 (90) 123-45-67')
        ->and(settings()->email())->toBe('sales@poson.uz')
        ->and(settings()->telegramUrl())->toBe('https://t.me/poson_uz');
});

it('caches overrides and clears the cache when a setting is saved or deleted', function () {
    expect(settings()->email())->toBe('info@poson.uz')
        ->and(Cache::has(CompanySettings::CACHE_KEY))->toBeTrue();

    $setting = Setting::factory()->setting('email', 'sales@poson.uz')->create();
    expect(Cache::has(CompanySettings::CACHE_KEY))->toBeFalse()
        ->and(settings()->email())->toBe('sales@poson.uz');

    $setting->update(['value' => 'service@poson.uz']);
    expect(settings()->email())->toBe('service@poson.uz');

    $setting->delete();
    expect(settings()->email())->toBe('info@poson.uz');
});

it('accepts only https URLs for Telegram and social links', function (string $url) {
    Setting::factory()->setting('telegram_url', $url)->create();
    Setting::factory()->setting('social', [
        ['network' => 'instagram', 'url' => $url],
        ['network' => 'youtube', 'url' => 'https://youtube.com/@poson'],
    ])->create();

    expect(settings()->telegramUrl())->toBe('')
        ->and(settings()->social())->toBe([['network' => 'youtube', 'url' => 'https://youtube.com/@poson']]);
})->with([
    'javascript' => 'javascript:alert(1)',
    'javascript url-like' => 'javascript://t.me/%0Aalert(1)',
    'data' => 'data:text/html,<script>alert(1)</script>',
    'http' => 'http://t.me/poson_uz',
    'not a url' => 'poson_uz',
]);

it('hides the Telegram link when the URL is not safe', function () {
    Setting::factory()->setting('telegram_url', 'javascript:alert(1)')->create();

    $html = $this->get('/ru')
        ->assertOk()
        ->assertDontSee('javascript:alert', false)
        ->assertDontSee('t.me/poson_uz', false)
        ->getContent();

    // Page copy may mention Telegram; only the link labels (topbar, drawer) must be gone.
    expect($html)->not->toMatch('/>\s*Telegram\s*<\/a>/');
});

it('expires cached overrides after an hour', function () {
    settings()->email();

    $this->travel(CompanySettings::CACHE_TTL + 1)->seconds();

    expect(Cache::has(CompanySettings::CACHE_KEY))->toBeFalse();
});

it('reads the cache store at most once per request', function () {
    settings()->email();

    Cache::forget(CompanySettings::CACHE_KEY);
    Cache::put(CompanySettings::CACHE_KEY, ['email' => 'other@poson.uz']);

    expect(settings()->email())->toBe('info@poson.uz');

    app()->forgetScopedInstances();

    expect(settings()->email())->toBe('other@poson.uz');
});

it('flushes the cache only after the transaction commits', function () {
    settings()->email();

    DB::transaction(function () {
        Setting::factory()->setting('email', 'sales@poson.uz')->create();

        expect(Cache::has(CompanySettings::CACHE_KEY))->toBeTrue();
    });

    expect(Cache::has(CompanySettings::CACHE_KEY))->toBeFalse()
        ->and(settings()->email())->toBe('sales@poson.uz');
});

it('keeps the cache when the transaction rolls back', function () {
    settings()->email();

    try {
        DB::transaction(function () {
            Setting::factory()->setting('email', 'sales@poson.uz')->create();

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
        //
    }

    expect(Cache::has(CompanySettings::CACHE_KEY))->toBeTrue()
        ->and(settings()->email())->toBe('info@poson.uz');
});

it('serves cached values without querying the database again', function () {
    settings()->email();

    Setting::query()->insert(['key' => 'email', 'value' => json_encode('raw@poson.uz')]);

    expect(settings()->email())->toBe('info@poson.uz');
});
