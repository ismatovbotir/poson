<?php

use App\Models\Setting;
use App\Support\CompanySettings;
use App\Support\Money;

/**
 * JSON-LD blocks of a page, decoded.
 *
 * @return list<array<string, mixed>>
 */
function jsonLdBlocks(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

    return array_map(fn (string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR), $matches[1]);
}

it('renders the localized home page with its sections', function (string $locale) {
    $response = $this->get("/{$locale}")->assertOk();

    $response
        ->assertSeeText(__('site.home.hero.title_highlight', [], $locale))
        ->assertSeeText(__('site.home.b2b.title', [], $locale))
        ->assertSeeText(__('site.home.why.title', [], $locale))
        ->assertSeeText(__('site.home.services.title', [], $locale))
        ->assertSeeText(__('site.home.lead.title', [], $locale))
        ->assertSee('id="categories"', false)
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

it('shows four demo product cards when demo content is on', function () {
    config(['site.demo_content' => true]);

    $html = $this->get('/ru')->assertOk()->getContent();

    expect(substr_count($html, 'data-product-card'))->toBe(4)
        ->and($html)->toContain(__('site.home.popular.title', [], 'ru'))
        ->and($html)->toContain(__('site.home.categories.title', [], 'ru'))
        ->and($html)->toContain('Zebra')
        ->and($html)->toContain('1'.Money::NBSP.'250'.Money::NBSP.'000');
});

it('gives a quote-mode card the quote CTA and cart cards the cart CTA', function () {
    config(['site.demo_content' => true]);

    $html = $this->get('/en')->assertOk()->getContent();

    preg_match_all('#<article[^>]*data-mode="(cart|quote|both)"[^>]*>(.*?)</article>#s', $html, $cards, PREG_SET_ORDER);

    expect($cards)->toHaveCount(4);

    foreach ($cards as [, $saleMode, $card]) {
        if ($saleMode === 'quote') {
            expect($card)->toContain('data-cta="quote"')
                ->toContain(__('site.card.request_quote', [], 'en'))
                ->toContain(__('site.card.price_on_request', [], 'en'))
                ->toContain('#lead')
                ->not->toContain('data-cta="cart"');
        } else {
            expect($card)->toContain('data-cta="cart"')
                ->toContain(__('site.card.add_to_cart', [], 'en'))
                ->not->toContain('data-cta="quote"');
        }
    }
});

it('never renders old prices or sale tags on product cards', function (string $locale) {
    config(['site.demo_content' => true]);

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

it('hides the empty catalog sections when demo content is off', function () {
    config(['site.demo_content' => false]);

    $this->get('/uz')
        ->assertOk()
        ->assertDontSee('data-product-card', false)
        ->assertDontSeeText(__('site.home.popular.title', [], 'uz'))
        ->assertDontSeeText(__('site.home.categories.title', [], 'uz'))
        ->assertDontSee('id="brands-title"', false)
        // The #categories anchor stays for the header and hero links.
        ->assertSee('id="categories"', false)
        ->assertSee('id="services"', false)
        ->assertSee('id="lead"', false);
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
        ->and($organization['address']['addressLocality'])->toBe('Ташкент')
        ->and(strpos($html, 'application/ld+json'))->toBeLessThan(strpos($html, '</head>'));
});

it('escapes settings values inside the JSON-LD script', function () {
    Setting::factory()->setting('address', ['uz' => '</script><script>alert(1)</script>'])->create();

    $html = $this->get('/uz')->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)')
        ->and(collect(jsonLdBlocks($html))->firstWhere('@type', 'Organization')['address']['streetAddress'])
        ->toBe('</script><script>alert(1)</script>');
});

it('contains no banned wording with demo content on', function (string $locale) {
    config(['site.demo_content' => true]);

    expectNoBannedWording($this->get("/{$locale}")->assertOk()->getContent());
})->with(['uz', 'ru', 'en']);
