<?php

use App\Models\Setting;
use App\Support\CompanySettings;
use Illuminate\Support\Facades\Cache;

/**
 * Brand logo assets: safe SVGs, icon fallbacks, head links, manifest.
 */
it('ships only clean static SVG logos', function (string $path) {
    $svg = file_get_contents($path);

    foreach (['<script', '<foreignObject', '<style', '<!ENTITY', '<!DOCTYPE', '<metadata', 'i:pgf', 'Illustrator'] as $needle) {
        expect(stripos($svg, $needle))->toBeFalse("{$path} contains {$needle}");
    }

    expect(preg_match('/\son\w+\s*=/i', $svg))->toBe(0)
        ->and(preg_match('/\bhref\s*=\s*["\']?(?!#)/i', $svg))->toBe(0)
        ->and($svg)->toContain('viewBox=');
})->with(fn (): array => collect(glob(dirname(__DIR__, 3).'/public/assets/site/*.svg'))
    ->push(dirname(__DIR__, 3).'/public/favicon.svg')
    ->mapWithKeys(fn (string $path): array => [basename($path) => [$path]])
    ->all());

it('ships the icon files with the expected formats', function () {
    [$width, $height, $type] = getimagesize(public_path('og-image.png'));

    expect($type)->toBe(IMAGETYPE_PNG)
        ->and($width)->toBeGreaterThanOrEqual(1200)
        ->and($width / $height)->toBeGreaterThan(1.85)->toBeLessThan(1.95)
        ->and(getimagesize(public_path('icon-512.png'))[0])->toBe(512)
        ->and(getimagesize(public_path('icon-192.png'))[0])->toBe(192)
        ->and(getimagesize(public_path('apple-touch-icon.png'))[0])->toBe(180)
        ->and(getimagesize(public_path('favicon-32.png'))[0])->toBe(32)
        ->and(bin2hex(substr(file_get_contents(public_path('favicon.ico')), 0, 4)))->toBe('00000100');
});

it('has a valid web manifest', function () {
    $manifest = json_decode(file_get_contents(public_path('site.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['name'])->toBe('Poson')
        ->and($manifest['scope'])->toBe('/')
        ->and($manifest['start_url'])->toBe('/uz')
        ->and($manifest['display'])->toBe('browser')
        ->and($manifest)->not->toHaveKeys(['id', 'related_applications'])
        ->and(collect($manifest['icons'])->pluck('src')->all())->toBe(['/icon-192.png', '/icon-512.png']);
});

it('falls back to the brand icon and OG image in structured data and meta', function () {
    $html = $this->get('/ru')->assertOk()->getContent();
    $organization = collect(jsonLdBlocks($html))->firstWhere('@type', 'Organization');

    expect($organization['logo'])->toBe(asset('icon-512.png'))
        ->and($html)->toContain('<meta property="og:image" content="'.asset('og-image.png').'">');
});

it('prefers the logo and OG image uploaded in settings', function () {
    Cache::put(CompanySettings::MEDIA_CACHE_KEY, [
        Setting::LOGO => 'https://cdn.example.test/logo.webp',
        Setting::OG_IMAGE => 'https://cdn.example.test/og.jpg',
    ], 60);

    $html = $this->get('/ru')->assertOk()->getContent();
    $organization = collect(jsonLdBlocks($html))->firstWhere('@type', 'Organization');

    expect($organization['logo'])->toBe('https://cdn.example.test/logo.webp')
        ->and($html)->toContain('content="https://cdn.example.test/og.jpg"')
        ->and($html)->not->toContain(asset('og-image.png'));
});

it('links favicons, manifest and theme color on the public site', function () {
    $this->get('/uz')->assertOk()
        ->assertSee(asset('favicon.svg'), false)
        ->assertSee(asset('favicon-32.png'), false)
        ->assertSee(asset('favicon.ico'), false)
        ->assertSee(asset('apple-touch-icon.png'), false)
        ->assertSee(asset('site.webmanifest'), false)
        ->assertSee('<meta name="theme-color" content="#06343b">', false);
});

it('links favicons but no manifest in the admin login', function () {
    $this->get('/admin/login')->assertOk()
        ->assertSee(asset('favicon.svg'), false)
        ->assertSee(asset('apple-touch-icon.png'), false)
        ->assertDontSee('site.webmanifest', false);
});

it('renders the light logo variant in the footer', function () {
    $this->get('/uz')->assertOk()->assertSee(asset('assets/site/logo-light.svg'), false);
});
