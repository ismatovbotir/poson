<?php

use App\Enums\LeadType;
use App\Enums\PreferredContact;
use App\Enums\ProductType;
use App\Livewire\Site\LeadForm;
use App\Livewire\Site\Services\RequestPage;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Lead;
use App\Models\Product;
use App\Support\Catalog\CategoryTree;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    openSearchIndexing();
    CategoryTree::flush();
    URL::defaults(['locale' => 'en']);
});

function serviceDeviceProduct(string $categorySlug = 'scanners', array $attributes = [], ProductType $type = ProductType::Hardware): Product
{
    $category = Category::factory()->create(['slug' => $categorySlug]);
    CategoryTree::flush();

    return Product::factory()->for(Brand::factory()->state(['name' => 'Zebra']), 'brand')->create([
        'category_id' => $category->id,
        'type' => $type,
        'model' => 'DS2208',
        'slug' => 'ds2208-'.$categorySlug,
        ...$attributes,
    ]);
}

/**
 * @param  array<string, string>  $overrides
 */
function filledServiceRequest(array $overrides = [], ?Testable $component = null): Testable
{
    $component ??= Livewire::test(RequestPage::class);

    $values = array_merge([
        'name' => 'Aziz',
        'phone' => '90 123 45 67',
        'device_brand' => 'Zebra',
        'device_model' => 'ZD421',
        'serial_number' => 'S/N 12-34.5',
        'message' => 'The printer does not feed labels.',
        'preferred_contact' => 'telegram',
    ], $overrides);

    foreach ($values as $field => $value) {
        $component->set($field, $value);
    }

    test()->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();

    return $component;
}

it('renders in every locale with a clean canonical and no robots tag', function (string $locale) {
    $html = $this->get("/{$locale}/services/request")->assertOk()->getContent();

    expect($html)->toContain('<link rel="canonical" href="'.url("/{$locale}/services/request").'">')
        ->and($html)->toContain('hreflang="x-default"')
        ->and($html)->toContain('application/ld+json')
        ->and($html)->toContain('BreadcrumbList')
        ->and($html)->not->toContain('noindex');
})->with(['uz', 'ru', 'en']);

it('is noindex,follow with a query and the canonical stays clean', function () {
    $html = $this->get('/en/services/request?product=anything&utm_source=x')->assertOk()->getContent();

    expect($html)->toContain('<meta name="robots" content="noindex,follow">')
        ->and($html)->toContain('<link rel="canonical" href="'.url('/en/services/request').'">');
});

it('keeps meta title and description within search limits in every locale', function (string $locale) {
    expect(mb_strlen(__('site.services.request.meta_title', [], $locale)))->toBeLessThanOrEqual(60)
        ->and(mb_strlen(__('site.services.request.meta_description', [], $locale)))->toBeLessThanOrEqual(155);
})->with(['uz', 'ru', 'en']);

it('prefills the device from an eligible product', function () {
    $scanner = serviceDeviceProduct();

    Livewire::withQueryParams(['product' => $scanner->slug])->test(RequestPage::class)
        ->assertSet('productId', $scanner->id)
        ->assertSet('device_brand', 'Zebra')
        ->assertSet('device_model', 'DS2208');
});

it('ignores a product that cannot be serviced, silently and without echoing the parameter', function (string $category, array $attributes, ProductType $type) {
    $product = serviceDeviceProduct($category, ['slug' => 'ignored-product', ...$attributes], $type);

    Livewire::withQueryParams(['product' => $product->slug])->test(RequestPage::class)
        ->assertSet('productId', null)
        ->assertSet('device_brand', '')
        ->assertSet('device_model', '')
        ->assertHasNoErrors();

    // Only the language switcher's (escaped) links keep the query string; the page itself never shows the parameter.
    $html = $this->get('/en/services/request?product='.$product->slug)->assertOk()->getContent();

    expect(preg_replace('/href="[^"]*"/', '', $html))->not->toContain('ignored-product');
})->with([
    'consumable' => ['consumables', [], ProductType::Consumable],
    'software' => ['soft', [], ProductType::Software],
    'service' => ['services', [], ProductType::Service],
    'excluded category' => ['rfid-tags', [], ProductType::Hardware],
    'unmapped category' => ['other-things', [], ProductType::Hardware],
    'hidden product' => ['scanners', ['is_active' => false], ProductType::Hardware],
    'trashed product' => ['scanners', ['deleted_at' => '2026-01-01 00:00:00'], ProductType::Hardware],
]);

it('ignores a product in a hidden category and an overlong slug', function () {
    $parent = Category::factory()->create(['slug' => 'scanners', 'is_active' => false]);
    $child = Category::factory()->create(['slug' => 'barcode-scanners', 'parent_id' => $parent->id]);
    CategoryTree::flush();
    $product = Product::factory()->create(['category_id' => $child->id, 'slug' => 'in-hidden-parent']);

    foreach ([$product->slug, str_repeat('a', 300)] as $slug) {
        Livewire::withQueryParams(['product' => $slug])->test(RequestPage::class)
            ->assertSet('productId', null)
            ->assertSet('device_brand', '');

        $html = $this->get('/en/services/request?product='.$slug)->assertOk()->getContent();
        expect(preg_replace('/href="[^"]*"/', '', $html))->not->toContain($slug);
    }
});

it('caps a long product name when prefilling and still submits', function () {
    $scanner = serviceDeviceProduct('scanners', ['model' => null, 'name' => ['uz' => str_repeat('N', 150), 'ru' => str_repeat('N', 150), 'en' => str_repeat('N', 150)]]);

    $component = Livewire::withQueryParams(['product' => $scanner->slug])->test(RequestPage::class)
        ->assertSet('productId', $scanner->id);
    expect(mb_strlen($component->get('device_model')))->toBe(120);

    $component->set('name', 'Aziz')->set('phone', '90 123 45 67')->set('message', 'The scanner does not read codes.');
    $this->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();
    $component->call('submit')->assertHasNoErrors()->assertSet('sent', true);

    expect(Lead::query()->sole()->device_model)->toHaveLength(120);
});

it('rejects a serial number with a trailing newline', function () {
    filledServiceRequest(['serial_number' => "AB12\n"])->call('submit')->assertHasErrors('serial_number');
});

it('stores the device brand without bidi control characters', function () {
    filledServiceRequest(['device_brand' => "Zeb\u{202E}ra"])->call('submit')->assertHasNoErrors()->assertSet('sent', true);

    expect(Lead::query()->sole()->device_brand)->not->toContain("\u{202E}");
});

it('never reflects the raw product parameter', function () {
    $this->get('/en/services/request?product='.urlencode('"><script>alert(1)</script>'))->assertOk()
        ->assertDontSee('alert(1)', false);
});

it('validates the fields', function (string $field, string $value, string $rule) {
    filledServiceRequest([$field => $value])->call('submit')->assertHasErrors($rule === '' ? [$field] : [$field => $rule])->assertSet('sent', false);
})->with([
    'serial with a symbol' => ['serial_number', 'AB<1>', 'regex'],
    'serial too long' => ['serial_number', str_repeat('1', 65), 'regex'],
    'brand with a line break' => ['device_brand', "Zebra\nInjected", 'not_regex'],
    'model with a carriage return' => ['device_model', "ZD\r421", 'not_regex'],
    'unknown contact' => ['preferred_contact', 'fax', ''],
    'short message' => ['message', 'short', 'min'],
    'no name' => ['name', '', 'required'],
]);

it('creates a service request lead with the device, the item and the contact preference, and alerts the service chat', function () {
    freshSecrets([
        'services.telegram.bot_token' => '123456789:AAServiceChatTokenServiceChatToken42',
        'services.telegram.chats.sales' => '-1001111111111',
        'services.telegram.chats.service' => '-1002222222222',
    ]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    $scanner = serviceDeviceProduct();

    $component = Livewire::withQueryParams(['product' => $scanner->slug])->test(RequestPage::class)
        ->assertSet('productId', $scanner->id);
    filledServiceRequest(component: $component)->call('submit')->assertHasNoErrors()->assertSet('sent', true);

    $lead = Lead::query()->with('items')->sole();
    expect($lead->type)->toBe(LeadType::ServiceRequest)
        ->and($lead->device_brand)->toBe('Zebra')
        ->and($lead->device_model)->toBe('ZD421')
        ->and($lead->serial_number)->toBe('S/N 12-34.5')
        ->and($lead->preferred_contact)->toBe(PreferredContact::Telegram)
        ->and($lead->items)->toHaveCount(1)
        ->and($lead->items->first()->product_id)->toBe($scanner->id);

    Http::assertSent(fn (Request $request): bool => ($request['chat_id'] ?? null) === '-1002222222222');
    Http::assertNotSent(fn (Request $request): bool => ($request['chat_id'] ?? null) === '-1001111111111');
});

it('creates the lead without an item when the product was hidden after the page opened', function () {
    $scanner = serviceDeviceProduct();
    $component = Livewire::withQueryParams(['product' => $scanner->slug])->test(RequestPage::class)->assertSet('productId', $scanner->id);

    $scanner->update(['is_active' => false]);
    filledServiceRequest(component: $component)->call('submit')->assertHasNoErrors()->assertSet('sent', true);

    $lead = Lead::query()->with('items')->sole();
    expect($lead->type)->toBe(LeadType::ServiceRequest)->and($lead->items)->toHaveCount(0);
});

it('answers bots with the success screen and creates nothing', function (bool $honeypot) {
    Http::fake();

    $component = Livewire::test(RequestPage::class);
    $honeypot ? filledServiceRequest(['fax_ext' => 'x'], $component) : $component->tap(function (Testable $c) {
        foreach (['name' => 'Aziz', 'phone' => '90 123 45 67', 'device_brand' => 'Zebra', 'device_model' => 'ZD421', 'message' => 'The printer does not feed labels.'] as $field => $value) {
            $c->set($field, $value);
        }
    });

    $component->call('submit')->assertHasNoErrors()->assertSet('sent', true);

    expect(Lead::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'honeypot' => [true],
    'too fast' => [false],
]);

it('shares the IP budget with the lead form', function () {
    for ($i = 0; $i < LeadForm::RATE_LIMIT; $i++) {
        RateLimiter::hit('site-lead:'.sha1('127.0.0.1'), LeadForm::RATE_LIMIT_SECONDS);
    }

    filledServiceRequest()->call('submit')->assertHasErrors('form')->assertSet('sent', false);

    expect(Lead::query()->count())->toBe(0);
});

it('shares the phone budget with the lead form', function () {
    for ($i = 0; $i < LeadForm::PHONE_LIMIT; $i++) {
        RateLimiter::hit('site-lead-phone:'.sha1('+998901234567'), LeadForm::PHONE_LIMIT_SECONDS);
    }

    filledServiceRequest()->call('submit')->assertHasErrors('form')->assertSet('sent', false);

    expect(Lead::query()->count())->toBe(0);
});

it('rejects changes to locked properties', function (string $property, mixed $value) {
    Livewire::test(RequestPage::class)->set($property, $value);
})->with([
    'productId' => ['productId', 1],
    'locale' => ['locale', 'ru'],
    'pageUrl' => ['pageUrl', 'https://evil.example'],
    'renderedAt' => ['renderedAt', 1],
    'attribution' => ['attribution', ['source' => 'x']],
])->throws(CannotUpdateLockedPropertyException::class);
