<?php

use App\Enums\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Site\Analytics;

/**
 * Settings → Company on the public site (plan 04a T3).
 */
function setCompany(string $key, mixed $value): void
{
    Setting::factory()->setting($key, $value)->create();
}

function enableTracking(): void
{
    setCompany('tracking', ['enabled' => true, 'ga4' => 'G-AB12CD34EF', 'metrika' => '98765432']);
}

/**
 * <script> tags without a src that are not JSON-LD.
 *
 * @return list<string>
 */
function inlineScripts(string $html): array
{
    preg_match_all('#<script\b([^>]*)>#i', $html, $matches);

    return array_values(array_filter($matches[1], fn (string $attributes): bool => ! str_contains($attributes, 'src=') && ! str_contains($attributes, 'application/ld+json')));
}

it('renders site phones with labels, primary first, and hides unmarked ones', function () {
    setCompany('phones', [
        ['label' => 'service', 'number' => '+998935554433', 'primary' => false, 'site' => true],
        ['label' => 'orders', 'number' => '+998901234567', 'primary' => true, 'site' => true],
        ['label' => 'accounting', 'number' => '+998712223344', 'primary' => false, 'site' => false],
    ]);

    $html = $this->get('/ru')->assertOk()->getContent();

    expect($html)->toContain('href="tel:+998901234567"')
        ->toContain('href="tel:+998935554433"')
        ->toContain('Сервисный центр')
        ->not->toContain('+998712223344')
        ->and(strpos($html, 'data-site-phone="orders"'))->toBeLessThan(strpos($html, 'data-site-phone="service"'));
});

it('renders social icons and the map link, never unsafe links', function () {
    setCompany('social', [
        ['network' => 'instagram', 'url' => 'https://www.instagram.com/poson.uz'],
        ['network' => 'facebook', 'url' => 'javascript:alert(1)'],
        ['network' => 'youtube', 'url' => 'https://evil.example/poson'],
    ]);
    setCompany('geo', ['lat' => 41.311081, 'lng' => 69.240562]);

    $html = $this->get('/uz')->assertOk()->getContent();

    expect($html)->toContain('href="https://www.instagram.com/poson.uz"')
        ->toContain('data-social-links')
        ->toContain('href="'.e('https://yandex.uz/maps/?pt=69.240562,41.311081&z=17&l=map').'"')
        ->not->toContain('javascript:alert')
        ->not->toContain('evil.example');
});

it('uses the company name as site name and the default OG image', function () {
    setCompany('name', ['uz' => 'Poson Servis', 'ru' => 'Посон Сервис']);

    $html = $this->get('/ru')->assertOk()->getContent();

    expect($html)->toContain('<meta property="og:site_name" content="Посон Сервис">');
});

it('prints verification metas and drops invalid or injected codes', function () {
    setCompany('verification', [
        'google' => 'abcDEF123_-abcDEF123_-xyz',
        'yandex' => '"><script>alert(1)</script>',
        'bing' => '0123456789ABCDEF0123456789ABCDEF',
    ]);

    $html = $this->get('/en')->assertOk()->getContent();

    expect($html)->toContain('<meta name="google-site-verification" content="abcDEF123_-abcDEF123_-xyz">')
        ->toContain('<meta name="msvalidate.01" content="0123456789ABCDEF0123456789ABCDEF">')
        ->not->toContain('yandex-verification')
        ->not->toContain('<script>alert(1)');
});

describe('tracking', function () {
    it('is off outside production', function () {
        enableTracking();

        $this->get('/ru')->assertOk()->assertDontSee('poson-analytics', false);
    });

    it('renders the config tag in production when enabled, for guests and customers', function () {
        enableTracking();
        app()->detectEnvironment(fn (): string => 'production');

        $html = $this->get('/ru')->assertOk()->getContent();

        preg_match('#<meta name="poson-analytics" content=\'([^\']*)\'>#', $html, $matches);

        expect($matches)->not->toBeEmpty()
            ->and(json_decode(html_entity_decode($matches[1]), true))->toBe(['ga4' => 'G-AB12CD34EF', 'metrika' => '98765432']);

        $this->actingAs(User::factory()->create())->get('/ru')->assertSee('poson-analytics', false);
    });

    it('renders with ANALYTICS_FORCE outside production', function () {
        enableTracking();
        config(['site.analytics_force' => true]);

        $this->get('/uz')->assertSee('poson-analytics', false);
    });

    it('stays off when disabled or without valid IDs', function (array $tracking) {
        setCompany('tracking', $tracking);
        config(['site.analytics_force' => true]);

        $this->get('/ru')->assertDontSee('poson-analytics', false);
    })->with([
        'disabled' => [['enabled' => false, 'ga4' => 'G-AB12CD34EF', 'metrika' => '98765432']],
        'invalid ids' => [['enabled' => true, 'ga4' => 'UA-1-1', 'metrika' => 'x<script>']],
        'enabled as string' => [['enabled' => 'yes', 'ga4' => 'G-AB12CD34EF', 'metrika' => '']],
    ]);

    it('never tracks signed-in staff', function (Role $role) {
        enableTracking();
        config(['site.analytics_force' => true]);

        $this->actingAs(staffUser($role))->get('/ru')->assertOk()->assertDontSee('poson-analytics', false);
    })->with([[Role::Admin], [Role::ContentManager], [Role::SalesManager], [Role::ServiceEngineer]]);

    it('never renders in the admin panel', function () {
        enableTracking();
        config(['site.analytics_force' => true]);

        $user = staffUser();

        $this->actingAs($user)->get(route('admin.dashboard'))->assertOk()->assertDontSee('poson-analytics', false);
        $this->actingAs($user)->get(route('admin.settings.company'))->assertOk()->assertDontSee('poson-analytics', false)->assertDontSee('googletagmanager', false);
    });

    it('adds no inline script', function () {
        $before = inlineScripts($this->get('/ru')->getContent());

        enableTracking();
        config(['site.analytics_force' => true]);

        $html = $this->get('/ru')->assertSee('poson-analytics', false)->getContent();

        expect(inlineScripts($html))->toBe($before)
            ->and($html)->not->toContain('googletagmanager.com')
            ->not->toContain('mc.yandex.ru')
            ->and(app(Analytics::class)->json())->not->toContain('<');
    });
});

describe('structured data', function () {
    it('adds Organization with taxID, legalName, contact points and sameAs', function () {
        setCompany('legal', ['legal_name' => ['uz' => 'POSON MChJ', 'ru' => 'ООО «POSON»'], 'inn' => '301234567']);
        setCompany('phones', [
            ['label' => 'orders', 'number' => '+998901234567', 'primary' => true, 'site' => true],
            ['label' => 'service', 'number' => '+998935554433', 'primary' => false, 'site' => true],
            ['label' => 'accounting', 'number' => '+998712223344', 'primary' => false, 'site' => false],
        ]);
        setCompany('social', [['network' => 'telegram', 'url' => 'https://t.me/poson_uz'], ['network' => 'instagram', 'url' => 'https://instagram.com/poson']]);

        $organization = collect(jsonLdBlocks($this->get('/ru')->assertOk()->getContent()))->firstWhere('@type', 'Organization');

        expect($organization['taxID'])->toBe('301234567')
            ->and($organization['legalName'])->toBe('ООО «POSON»')
            ->and($organization['telephone'])->toBe('+998901234567')
            ->and($organization['sameAs'])->toBe(['https://t.me/poson_uz', 'https://instagram.com/poson'])
            ->and(collect($organization['contactPoint'])->pluck('contactType')->all())->toBe(['customer service', 'technical support'])
            ->and($organization)->not->toHaveKey('vatID');
    });

    it('adds LocalBusiness with address, geo and opening hours on the home page', function () {
        setCompany('geo', ['lat' => 41.311081, 'lng' => 69.240562]);
        setCompany('opening_hours', [
            ['days' => ['mo', 'tu', 'we', 'th', 'fr'], 'opens' => '09:00', 'closes' => '18:00'],
            ['days' => ['sa'], 'opens' => '10:00', 'closes' => '15:00'],
            ['days' => ['su'], 'opens' => '18:00', 'closes' => '09:00'],
        ]);

        $html = $this->get('/en')->assertOk()->getContent();
        $business = collect(jsonLdBlocks($html))->firstWhere('@type', 'LocalBusiness');

        expect($business)->not->toBeNull()
            ->and($business['address']['addressLocality'])->toBe('Kokand')
            ->and($business['geo'])->toBe(['@type' => 'GeoCoordinates', 'latitude' => 41.311081, 'longitude' => 69.240562])
            ->and($business['openingHoursSpecification'])->toHaveCount(2)
            ->and($business['openingHoursSpecification'][0]['dayOfWeek'][0])->toBe('https://schema.org/Monday')
            ->and($business['parentOrganization'])->toBe(['@id' => url('/en').'#organization'])
            ->and($business)->not->toHaveKey('priceRange');
    });

    it('re-validates stored values and keeps the brand rule', function () {
        setCompany('hero_stats', [
            ['value' => ['uz' => '−20%'], 'label' => ['uz' => 'chegirma']],
            ['value' => ['uz' => '12 oy'], 'label' => ['uz' => 'kafolat']],
        ]);
        setCompany('email', 'not-an-email');
        setCompany('legal', ['inn' => '12345']);

        $html = $this->get('/uz')->assertOk()->getContent();
        $organization = collect(jsonLdBlocks($html))->firstWhere('@type', 'Organization');

        expectNoBannedWording($html);
        expect($html)->toContain('12 oy')
            ->and($organization)->not->toHaveKey('taxID')
            ->not->toHaveKey('email');
    });
});
