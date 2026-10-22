<?php

use App\Actions\Analytics\AggregateTraffic;
use App\Enums\Role;
use App\Livewire\Admin\Analytics\Traffic;
use App\Livewire\Admin\Brands\Form as BrandForm;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\SiteVisit;
use App\Models\TrafficDaily;
use App\Models\TrafficDailyPage;
use App\Support\Analytics\TrafficRecorder;
use App\Support\Catalog\SoftwareSpecs;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DemoProductsSeeder;
use Livewire\Livewire;

/**
 * uz option values of one attribute stored for a product.
 *
 * @return list<string>
 */
function softwareValues(Product $product, string $code): array
{
    return $product->attributeValues()
        ->whereHas('attribute', fn ($query) => $query->where('code', $code))
        ->with('option')
        ->get()
        ->map(fn ($value) => $value->option?->getTranslation('value', 'uz') ?? ($value->value_bool === null ? null : ($value->value_bool ? 'yes' : 'no')))
        ->filter()
        ->values()
        ->all();
}

function softwareOption(string $code, string $uz): int
{
    return (int) AttributeOption::query()
        ->whereHas('attribute', fn ($query) => $query->where('code', $code))
        ->get()
        ->first(fn (AttributeOption $option) => $option->getTranslation('value', 'uz') === $uz)
        ->id;
}

it('seeds the software attributes on the software category with the agreed filters', function () {
    $this->seed(CatalogSeeder::class);

    $software = Category::query()->where('slug', 'software')->sole();
    $pivots = $software->attributes()->get()->keyBy('code');

    $expected = [
        'platforms' => ['multiselect', true],
        'licence_model' => ['multiselect', false],
        'segment' => ['multiselect', true],
        'modules' => ['multiselect', true],
        'integrations' => ['multiselect', false],
        'online_cash_register' => ['boolean', true],
        'interface_languages' => ['multiselect', false],
    ];

    foreach ($expected as $code => [$type, $filterable]) {
        expect($pivots->get($code))->not->toBeNull()
            ->and($pivots[$code]->type->value)->toBe($type)
            ->and((bool) $pivots[$code]->pivot->is_filterable)->toBe($filterable)
            ->and((bool) $pivots[$code]->pivot->is_required)->toBeFalse();
    }

    // license_type is superseded by licence_model; the vendor site is the brand website.
    expect(Attribute::query()->whereIn('code', ['license_type', 'vendor_site'])->exists())->toBeFalse()
        ->and(Attribute::query()->where('code', 'platforms')->sole()->options->map(fn ($option) => $option->getTranslation('value', 'ru'))->all())
        ->toBe(['Windows', 'Android', 'iOS', 'Веб / облако'])
        ->and(Attribute::query()->where('code', 'modules')->sole()->options)->toHaveCount(8)
        ->and(Attribute::query()->where('code', 'licence_model')->sole()->options->map(fn ($option) => $option->getTranslation('value', 'en'))->all())
        ->toBe(['Monthly subscription', 'Yearly subscription', 'Subscription', 'Perpetual licence', 'Individual terms'])
        ->and(Attribute::query()->where('code', 'integrations')->sole()->getTranslations('name'))
        ->toBe(['uz' => 'Dastur integratsiyalari', 'ru' => 'Интеграции программы', 'en' => 'Program integrations'])
        ->and(Brand::query()->where('slug', 'alipos')->value('website'))->toBe('https://alipos.uz');
});

it('seeds the software specs from the verified facts only', function () {
    $this->seed([CatalogSeeder::class, DemoProductsSeeder::class]);
    $sku = fn (string $sku) => Product::query()->where('sku', $sku)->sole();

    expect(softwareValues($sku('SW-ALIPOS'), 'modules'))->toBe(['Kassa (POS)', 'Ofitsiant ilovasi', 'Oshxona ekrani va printeri', 'Ombor hisobi', 'Yetkazib berish'])
        ->and(softwareValues($sku('SW-ALIPOS'), 'segment'))->toBe(['HoReCa'])
        ->and(softwareValues($sku('SW-ALIPOS'), 'platforms'))->toBe([])
        ->and(softwareValues($sku('SW-RAHMAT'), 'platforms'))->toBe(['Windows'])
        ->and(softwareValues($sku('SW-RAHMAT'), 'online_cash_register'))->toBe(['yes'])
        ->and(softwareValues($sku('SW-MOYSKLAD'), 'licence_model'))->toBe(['Oylik obuna', 'Yillik obuna'])
        ->and(softwareValues($sku('SW-MOYSKLAD'), 'integrations'))->toContain('1C', 'Didox')
        ->and(softwareValues($sku('SW-OX'), 'segment'))->toBe(['Chakana savdo'])
        // "Subscription" per the user where the billing period is unknown.
        ->and(softwareValues($sku('SW-OX'), 'licence_model'))->toBe(['Obuna'])
        ->and(softwareValues($sku('SW-ALIPOS'), 'licence_model'))->toBe(['Obuna'])
        ->and(softwareValues($sku('SW-HIPPO'), 'licence_model'))->toBe(['Obuna'])
        // Unknown facts stay empty.
        ->and(softwareValues($sku('SW-ALIPOS'), 'integrations'))->toBe([])
        ->and(Product::query()->where('type', 'software')->whereHas('attributeValues', fn ($query) => $query->whereHas('attribute', fn ($attribute) => $attribute->where('code', 'interface_languages')))->exists())->toBeFalse();
});

it('keeps software copy generic: no prices, no platform or licence claims, no banned wording', function () {
    $rows = collect(require database_path('seeders/data/products.php'))->where('type', 'software');

    expect($rows)->toHaveCount(7);

    foreach ($rows as $row) {
        expect($row['price'])->toBeNull()
            ->and($row['sale_mode'])->toBe('quote');

        $copy = collect($row)->only(['name', 'short_description', 'description', 'meta_title', 'meta_description'])->flatten()->implode(' ');
        expectNoBannedWording($copy);

        expect(mb_strtolower($copy))->not->toMatch('/windows|android|ios\b|obuna|подписк|subscription|litsenziya shartlari/u')
            ->and($row['description']['uz'])->toContain('alohida xizmat')
            ->and($row['description']['ru'])->toContain('отдельная услуга')
            ->and($row['description']['en'])->toContain('separate service')
            ->and($row['description']['uz'])->not->toMatch("/[‘’']/u");
    }
});

it('shows the software details on the product page with Try and no prices', function (string $locale) {
    $this->seed([CatalogSeeder::class, DemoProductsSeeder::class]);
    $product = Product::query()->where('sku', 'SW-RAHMAT')->sole();

    $html = $this->get("/{$locale}/product/{$product->slug}")->assertOk()->getContent();

    expect($html)->toContain('data-software-details')
        ->toContain('data-software="platforms"')
        ->toContain('Windows')
        ->toContain('data-software="modules"')
        ->toContain('data-software="online_cash_register"')
        ->toContain('data-cta="try"')
        ->toContain('href="'.url("/{$locale}/go/{$product->slug}").'"')
        ->toContain(e(__('site.cta.try', [], $locale)))
        ->toContain(e(__('site.card.price_on_request', [], $locale)))
        ->not->toContain('rhmt.uz')
        ->not->toContain('data-cta="cart"');

    expectNoBannedWording($html);
})->with(['uz', 'ru', 'en']);

it('shows no software details block for other products', function () {
    $product = Product::factory()->create();

    expect($this->get('/en/product/'.$product->slug)->assertOk()->getContent())->not->toContain('data-software-details');
});

it('filters the software listing by platform, segment, modules and online cash register', function (string $code, string $value, array $expected) {
    $this->seed([CatalogSeeder::class, DemoProductsSeeder::class]);

    $filter = $code === SoftwareSpecs::ONLINE_CASH_REGISTER ? '1' : (string) softwareOption($code, $value);
    $html = $this->get('/en/catalog/software?'.http_build_query(['f' => [$code => [$filter]]]))->assertOk()->getContent();

    preg_match_all('#<article[^>]*data-product-card.*?</article>#s', $html, $cards);
    $names = collect($expected)->map(fn (string $sku) => e(Product::query()->where('sku', $sku)->sole()->getTranslation('name', 'en')));

    expect($cards[0])->toHaveCount(count($expected));

    foreach ($names as $name) {
        expect($html)->toContain($name);
    }
})->with([
    'platform Windows' => ['platforms', 'Windows', ['SW-MOYSKLAD', 'SW-RAHMAT']],
    'segment HoReCa' => ['segment', 'HoReCa', ['SW-ALIPOS', 'SW-RAHMAT']],
    'module waiter app' => ['modules', 'Ofitsiant ilovasi', ['SW-ALIPOS']],
    'online cash register' => ['online_cash_register', '', ['SW-MOYSKLAD', 'SW-POSPOINT', 'SW-RAHMAT']],
]);

it('offers the software filters on the software category', function () {
    $this->seed([CatalogSeeder::class, DemoProductsSeeder::class]);

    $html = $this->get('/en/catalog/pos-software')->assertOk()->getContent();

    foreach (['platforms', 'segment', 'modules', 'online_cash_register'] as $code) {
        expect($html)->toContain('data-filter="'.$code.'"');
    }

    expect($html)->not->toContain('data-filter="integrations"')
        ->not->toContain('data-filter="licence_model"')
        ->not->toContain('data-filter="interface_languages"');
});

it('explains in the brand form that a software brand website is the Try link, and keeps a referral link as entered', function () {
    $link = 'https://partner.example.uz/r/poson?ref=poson&utm_source=poson&utm_campaign='.str_repeat('x', 300);

    Livewire::actingAs(staffUser(Role::ContentManager))
        ->test(BrandForm::class)
        ->assertSee(__('admin.brands.website_hint'))
        ->set('form.name', 'Billz')
        ->set('form.website', $link)
        ->call('save')
        ->assertHasNoErrors();

    expect(Brand::query()->sole()->website)->toBe($link)
        ->and(__('admin.brands.website_hint', [], 'uz'))->toContain('Sinab koʻrish')
        ->and(__('admin.brands.website_hint', [], 'ru'))->toContain('Попробовать')
        ->and(__('admin.brands.website_hint', [], 'en'))->toContain('Try');
});

it('rolls partner clicks up per day and per product', function () {
    $product = Product::factory()->withPartnerLink()->create();
    $at = now();

    SiteVisit::factory()->count(2)->partnerClick($product->id, $product->slug)->create(['occurred_at' => $at]);
    SiteVisit::factory()->create(['occurred_at' => $at]);

    app(AggregateTraffic::class)->handle(TrafficRecorder::today());

    $total = TrafficDaily::query()->where('kind', TrafficDaily::KIND_VISITS)->where('dimension', TrafficDaily::DIM_TOTAL)->sole();
    $page = TrafficDailyPage::query()->where('kind', TrafficDaily::KIND_PARTNER)->sole();

    expect($total->partner_clicks)->toBe(2)
        ->and($total->page_views)->toBe(1)
        ->and($total->leads)->toBe(0)
        ->and($page->product_id)->toBe($product->id)
        ->and($page->hits)->toBe(2)
        ->and(TrafficDailyPage::query()->where('kind', TrafficDaily::KIND_VISITS)->count())->toBe(1);
});

it('shows partner clicks per product on the Analytics dashboard', function () {
    config(['analytics.report_cache_seconds' => 0]);
    $product = Product::factory()->withPartnerLink()->create(['sku' => 'SW-BILLZ']);
    $date = TrafficRecorder::today();

    TrafficDaily::factory()->create(['date' => $date, 'partner_clicks' => 7]);
    TrafficDailyPage::factory()->create(['date' => $date, 'kind' => TrafficDaily::KIND_PARTNER, 'path' => '/uz/go/'.$product->slug, 'status' => 302, 'product_id' => $product->id, 'hits' => 7]);

    Livewire::actingAs(staffUser(Role::Admin))
        ->test(Traffic::class)
        ->assertSeeHtml('data-partner-clicks')
        ->assertSee(__('analytics.traffic.partner_clicks'))
        ->assertSee(__('analytics.traffic.partner_clicks_hint', ['count' => 30, 'total' => 7]))
        ->assertSeeHtml('data-product="'.$product->id.'"')
        ->assertSee('SW-BILLZ');
});

it('shows no stock status for software on cards, the product page and JSON-LD', function () {
    $software = Product::factory()->software()->create(['slug' => 'billz-pos', 'price' => 50_000_000, 'is_featured' => true]);
    $hardware = Product::factory()->create(['slug' => 'zebra-scanner', 'is_featured' => true]);

    $page = $this->get('/en/product/billz-pos')->assertOk()->getContent();
    $offer = collect(jsonLdBlocks($page))->firstWhere('@type', 'Product')['offers'];

    expect($page)->not->toContain('data-stock=')
        ->and($offer)->not->toHaveKey('availability')
        ->and($offer['price'])->not->toBeNull();

    $hardwarePage = $this->get('/en/product/zebra-scanner')->assertOk()->getContent();

    expect($hardwarePage)->toContain('data-stock="in_stock"')
        ->and(collect(jsonLdBlocks($hardwarePage))->firstWhere('@type', 'Product')['offers'])->toHaveKey('availability');

    preg_match_all('#<article[^>]*data-product-card[^>]*>(.*?)</article>#s', $this->get('/en')->assertOk()->getContent(), $cards);
    $cards = collect($cards[1]);

    expect($cards->first(fn (string $card) => str_contains($card, '/product/billz-pos')))->not->toContain('data-stock=')
        ->and($cards->first(fn (string $card) => str_contains($card, '/product/zebra-scanner')))->toContain('data-stock=');
});

it('shows no availability filter on the software category', function () {
    $this->seed([CatalogSeeder::class, DemoProductsSeeder::class]);

    expect($this->get('/en/catalog/software')->assertOk()->getContent())->not->toContain('wire:key="filter-stock"')
        ->and($this->get('/en/catalog/scanners')->assertOk()->getContent())->toContain('wire:key="filter-stock"');
});

it('never filters software out by availability on mixed listings', function () {
    $software = Product::factory()->software()->create(['stock_status' => 'on_order']);
    $inStock = Product::factory()->create(['stock_status' => 'in_stock']);
    $outOfStock = Product::factory()->create(['stock_status' => 'out_of_stock']);

    $html = $this->get('/en/catalog?stock[]=out_of_stock')->assertOk()->getContent();

    expect($html)->toContain(e($software->getTranslation('name', 'en')))
        ->toContain(e($outOfStock->getTranslation('name', 'en')))
        ->not->toContain(e($inStock->getTranslation('name', 'en')));

    // The facet counts the non-software products only.
    preg_match('#wire:key="filter-stock-on_order"#', $html, $onOrder);
    expect($onOrder)->toBe([]);
});

it('aggregates partner clicks idempotently', function () {
    $product = Product::factory()->withPartnerLink()->create();

    SiteVisit::factory()->count(3)->partnerClick($product->id, $product->slug)->create(['occurred_at' => now()]);

    app(AggregateTraffic::class)->handle(TrafficRecorder::today());
    $first = [TrafficDaily::query()->get(['kind', 'dimension', 'value', 'partner_clicks'])->toArray(), TrafficDailyPage::query()->get(['kind', 'path', 'product_id', 'hits'])->toArray()];

    app(AggregateTraffic::class)->handle(TrafficRecorder::today());
    $second = [TrafficDaily::query()->get(['kind', 'dimension', 'value', 'partner_clicks'])->toArray(), TrafficDailyPage::query()->get(['kind', 'path', 'product_id', 'hits'])->toArray()];

    expect($second)->toBe($first)
        ->and(TrafficDailyPage::query()->where('kind', TrafficDaily::KIND_PARTNER)->sole()->hits)->toBe(3)
        ->and(TrafficDaily::query()->where('dimension', TrafficDaily::DIM_TOTAL)->sole()->partner_clicks)->toBe(3);
});

it('keeps software details out of the spec table and shows only the primary CTA in the sticky bar', function () {
    $this->seed([CatalogSeeder::class, DemoProductsSeeder::class]);
    $product = Product::query()->where('sku', 'SW-RAHMAT')->sole();

    $html = $this->get('/en/product/'.$product->slug)->assertOk()->getContent();
    preg_match('#<table[^>]*data-specs.*?</table>#s', $html, $table);
    preg_match('#data-sticky-bar.*?</div>#s', $html, $bar);

    expect($table[0])->not->toContain('Windows')
        ->not->toContain(e(__('site.product.yes', [], 'en')))
        ->and($bar[0])->toContain(e(__('site.card.price_on_request', [], 'en')))
        ->toContain('data-cta="try"')
        ->not->toContain('data-cta="contact"');
});
