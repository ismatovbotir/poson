<?php

use App\Actions\Catalog\ReorderCategory;
use App\Actions\Settings\UpdateCompanySettings;
use App\Enums\SettingsArea;
use App\Models\Category;
use App\Models\Product;
use App\Support\CompanySettings;
use App\Support\Site\LlmsTxt;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

beforeEach(function () {
    // Trusted hosts are static on the Symfony request and may leak from other tests.
    SymfonyRequest::setTrustedHosts([]);
    config(['app.url' => 'https://poson.uz']);
});

function llmsCategory(string $slug, string $en, array $attributes = []): Category
{
    return Category::factory()->create([
        'slug' => $slug,
        'name' => ['uz' => $en.' UZ', 'ru' => $en.' RU', 'en' => $en],
        ...$attributes,
    ]);
}

it('serves the llmstxt.org structure as noindex plain text', function () {
    $response = $this->get('/llms.txt')->assertOk();
    $text = $response->getContent();

    expect($response->headers->get('Content-Type'))->toBe('text/plain; charset=utf-8')
        ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex')
        ->and($text)->toStartWith("# Poson\n\n> ".config('llms.summary.en')."\n")
        ->toContain("\n## About\n\n- ".config('llms.about_points.en.0'))
        ->toContain("\n## Services\n\n- ".config('llms.services_points.en.0'))
        ->toContain("\n## Contacts\n\n- Website: https://poson.uz/en\n")
        ->toContain('Payment methods: bank transfer against an invoice, cash, Payme, Click, card terminal and UzQR.')
        ->toContain('Support: remote setup and support, on-site engineer visits')
        ->toContain("\n## Русский\n\n> ".config('llms.summary.ru')."\n\n- [Сайт на русском языке](https://poson.uz/ru)\n- [Каталог](https://poson.uz/ru/catalog)")
        ->toContain("\n## Oʻzbekcha\n\n> ".config('llms.summary.uz')."\n\n- [Sayt oʻzbek tilida](https://poson.uz/uz)\n- [Katalog](https://poson.uz/uz/catalog)")
        ->and(route('llms', absolute: false))->toBe('/llms.txt');
});

it('lists visible top-level categories with English URLs, not hidden or nested ones', function () {
    $scanners = llmsCategory('scanners', 'Barcode scanners', ['sort' => 1]);
    llmsCategory('handheld-scanners', 'Handheld scanners', ['parent_id' => $scanners->id]);
    llmsCategory('services', 'Services', ['sort' => 3]);
    llmsCategory('printers', 'Label printers', ['sort' => 2]);
    llmsCategory('hidden', 'Hidden category', ['is_active' => false]);

    $text = $this->get('/llms.txt')->getContent();

    expect($text)->toContain(implode("\n", [
        '## Catalog',
        '',
        '- [Barcode scanners](https://poson.uz/en/catalog/scanners)',
        '- [Label printers](https://poson.uz/en/catalog/printers)',
        '- [Services](https://poson.uz/en/catalog/services)',
    ]))
        ->not->toContain('Hidden category')
        ->not->toContain('handheld-scanners');
});

it('adds the services category when it is not top-level', function () {
    $parent = llmsCategory('solutions', 'Solutions');
    llmsCategory('services', 'Services', ['parent_id' => $parent->id]);

    expect($this->get('/llms.txt')->getContent())
        ->toContain("- [Solutions](https://poson.uz/en/catalog/solutions)\n- [Services](https://poson.uz/en/catalog/services)");
});

it('never prints prices, products or banned wording', function () {
    $category = llmsCategory('scanners', 'Barcode scanners');
    Product::factory()->inCategory($category)->create(['price' => 123_456_700, 'slug' => 'zebra-ds2208', 'sku' => 'ZB-DS2208']);

    $text = $this->get('/llms.txt')->getContent();

    expect($text)->not->toContain('1 234 567')
        ->not->toContain('1234567')
        ->not->toContain('zebra-ds2208')
        ->not->toContain('ZB-DS2208')
        ->not->toMatch('/\b(UZS|soʻm|сум|sum)\b/u');

    expectNoBannedWording($text);
});

it('prints email, phone and address only when entered in admin, valid and not the placeholder', function () {
    $text = $this->get('/llms.txt')->getContent();

    expect($text)->not->toContain('Email:')
        ->not->toContain('Phone:')
        ->not->toContain('Address:')
        ->not->toContain('TBD');

    // Saving the config placeholders themselves still counts as not entered.
    app(UpdateCompanySettings::class)->handle(SettingsArea::Contacts, [
        'email' => config('company.email'),
        'phones' => config('company.phones'),
        'address' => config('company.address'),
    ], null, null);

    $text = $this->get('/llms.txt')->getContent();
    expect($text)->not->toContain('Email:')->not->toContain('Phone:')->not->toContain('Address:');

    app(UpdateCompanySettings::class)->handle(SettingsArea::Contacts, [
        'email' => 'not-an-email',
        'phones' => [['label' => 'orders', 'number' => '+998901234567', 'primary' => true, 'site' => true]],
        'address' => ['uz' => 'Qoʻqon sh., Turkiston koʻchasi 1', 'ru' => 'г. Коканд, ул. Туркистон, 1', 'en' => '1 Turkiston Street'],
    ], null, null);

    $text = $this->get('/llms.txt')->getContent();

    expect($text)->not->toContain('Email:')
        ->not->toContain('not-an-email')
        ->toContain('- Phone: +998 (90) 123-45-67')
        ->toContain('- Address: 1 Turkiston Street, Kokand');

    app(UpdateCompanySettings::class)->handle(SettingsArea::Contacts, ['email' => 'sales-desk@poson.uz'], null, null);

    expect($this->get('/llms.txt')->getContent())->toContain('- Email: sales-desk@poson.uz');
});

it('pins every URL to APP_URL whatever the Host header', function () {
    llmsCategory('scanners', 'Barcode scanners');

    $text = $this->get('http://evil.example/llms.txt')->assertOk()->getContent();

    expect($text)->toContain('https://poson.uz/en/catalog/scanners')
        ->not->toContain('evil.example')
        ->and(url('/ru'))->not->toStartWith('https://poson.uz');
});

it('caches the text and flushes it on catalog and settings changes', function () {
    $category = llmsCategory('scanners', 'Barcode scanners');
    $this->get('/llms.txt');

    expect(Cache::has(LlmsTxt::CACHE_KEY))->toBeTrue();

    // Served from the cache: a change that skips the model events is not seen.
    Category::query()->whereKey($category->id)->update(['slug' => 'silent-change']);
    expect($this->get('/llms.txt')->getContent())->toContain('/en/catalog/scanners');

    $category->refresh()->update(['slug' => 'barcode-scanners']);
    expect(Cache::has(LlmsTxt::CACHE_KEY))->toBeFalse()
        ->and($this->get('/llms.txt')->getContent())->toContain('/en/catalog/barcode-scanners');

    Product::factory()->inCategory($category)->create();
    expect(Cache::has(LlmsTxt::CACHE_KEY))->toBeFalse();

    $this->get('/llms.txt');
    app(UpdateCompanySettings::class)->handle(SettingsArea::Contacts, ['email' => 'hello@poson.uz'], null, null);
    expect(Cache::has(LlmsTxt::CACHE_KEY))->toBeFalse()
        ->and($this->get('/llms.txt')->getContent())->toContain('- Email: hello@poson.uz');

    $second = llmsCategory('printers', 'Label printers');
    $this->get('/llms.txt');
    app(ReorderCategory::class)->handle($second, 0);
    expect(Cache::has(LlmsTxt::CACHE_KEY))->toBeFalse();

    $this->get('/llms.txt');
    CompanySettings::flush();
    expect(Cache::has(LlmsTxt::CACHE_KEY))->toBeFalse();
});

it('builds with a fixed number of queries', function () {
    foreach (range(1, 8) as $index) {
        llmsCategory("category-{$index}", "Category {$index}");
    }

    Cache::flush();
    $queries = countQueries(fn () => app(LlmsTxt::class)->text());

    expect($queries)->toBeLessThanOrEqual(3);
});
