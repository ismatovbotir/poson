<?php

use App\Data\Site\ProductCta;
use App\Enums\ProductCtaKind;
use App\Enums\ProductType;
use App\Enums\SaleMode;
use App\Enums\StockStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Models\RollType;
use App\Support\Catalog\CategoryTree;
use App\Support\Catalog\ProductCtas;
use App\Support\Site\HomeCache;
use App\Support\Site\LlmsTxt;
use App\Support\Site\Sitemap;
use Illuminate\Support\Facades\Cache;

/**
 * @param  list<ProductCta>  $ctas
 * @return list<array{0: string, 1: string, 2: bool}> kind, action, primary
 */
function ctaShape(array $ctas): array
{
    return array_map(fn (ProductCta $cta): array => [$cta->kind->value, $cta->action, $cta->primary], $ctas);
}

it('offers Try (brand website) and Contact us for software whose brand has an https website, whatever the sale mode and cart', function (bool $cartEnabled, SaleMode $saleMode) {
    config(['site.cart_enabled' => $cartEnabled]);
    $product = Product::factory()->withPartnerLink()->create(['slug' => 'billz', 'sale_mode' => $saleMode]);

    $ctas = ProductCtas::for($product, locale: 'ru');

    expect(ctaShape($ctas))->toBe([
        ['try', ProductCta::ACTION_EXTERNAL, true],
        ['contact', ProductCta::ACTION_LEAD_FORM, false],
    ])
        ->and($ctas[0]->url)->toBe(url('/ru/go/billz'))
        ->and($ctas[0]->labelKey)->toBe('site.cta.try')
        ->and($ctas[1]->url)->toBe(url('/ru/product/billz').'#quote')
        ->and($ctas[1]->pageHref())->toBe('#quote')
        ->and(ctaShape(ProductCtas::for($product, compact: true)))->toBe(ctaShape($ctas));
})->with([
    'cart off, quote' => [false, SaleMode::Quote],
    'cart on, both' => [true, SaleMode::Both],
]);

it('never gives Try to other types, even when their brand has a website', function (string $state) {
    config(['site.cart_enabled' => false]);
    $product = Product::factory()->{$state}()->for(Brand::factory()->state(['website' => 'https://www.zebra.com']))->create();

    expect(array_column(ctaShape(ProductCtas::for($product)), 0))->toBe(['quote'])
        ->and($product->partnerUrl())->toBeNull();
})->with(['quoteOnly', 'consumable']);

it('falls back to Contact us for software without a usable brand website', function (?string $website, bool $brandActive) {
    $product = Product::factory()->software()->priceOnRequest()
        ->for(Brand::factory()->state(['website' => $website, 'is_active' => $brandActive]))
        ->create();

    expect(ctaShape(ProductCtas::for($product)))->toBe([['contact', ProductCta::ACTION_LEAD_FORM, true]]);
})->with([
    'no website' => [null, true],
    'http' => ['http://partner.example.uz/signup', true],
    'javascript' => ['javascript:alert(1)', true],
    'hidden brand' => ['https://partner.example.uz/signup', false],
]);

it('gives software without a brand only Contact us', function () {
    $product = Product::factory()->software()->priceOnRequest()->withoutBrand()->create();

    expect(array_column(ctaShape(ProductCtas::for($product)), 0))->toBe(['contact']);
});

it('offers Add to cart only while the cart is enabled and the product allows it', function () {
    $product = Product::factory()->create(['sale_mode' => SaleMode::Both, 'price' => 125_000_000]);

    config(['site.cart_enabled' => false]);
    expect(ctaShape(ProductCtas::for($product)))->toBe([['quote', ProductCta::ACTION_LEAD_FORM, true]]);

    config(['site.cart_enabled' => true]);
    expect(ctaShape(ProductCtas::for($product)))->toBe([
        ['cart', ProductCta::ACTION_CART, true],
        ['quote', ProductCta::ACTION_LEAD_FORM, false],
    ])
        // Cards keep one buying button.
        ->and(ctaShape(ProductCtas::for($product, compact: true)))->toBe([['cart', ProductCta::ACTION_CART, true]]);
});

it('asks for a quote for quote-only and price-on-request products even with the cart on', function (string $state) {
    config(['site.cart_enabled' => true]);
    $product = Product::factory()->{$state}()->create();

    expect(ctaShape(ProductCtas::for($product)))->toBe([['quote', ProductCta::ACTION_LEAD_FORM, true]]);
})->with(['quoteOnly', 'priceOnRequest']);

it('lets label products into the cart only with an active roll type, linking the card to the picker', function (Closure $rollTypes, bool $expected) {
    config(['site.cart_enabled' => true]);
    $product = Product::factory()->perLabel()->create(['sale_mode' => SaleMode::Both]);

    foreach ($rollTypes() as $index => $rollType) {
        $product->rollTypes()->attach($rollType->id, ['sort' => $index + 1]);
    }

    $ctas = ProductCtas::for($product->fresh(), locale: 'en');

    expect(ProductCtas::canAddToCart($product->fresh()))->toBe($expected)
        ->and(array_column(ctaShape($ctas), 0))->toBe($expected ? ['cart', 'quote'] : ['quote']);

    if ($expected) {
        // The card's cart CTA leads to the product page, where the roll is picked.
        expect($ctas[0]->url)->toBe(url('/en/product/'.$product->slug))
            ->and($ctas[0]->pageHref())->toBe('#'.ProductCta::BUY_BOX_ANCHOR);
    }
})->with([
    'no roll type' => [fn () => [], false],
    'only an inactive roll type' => [fn () => [RollType::factory()->inactive()->create()], false],
    'an active roll type' => [fn () => [RollType::factory()->inactive()->create(), RollType::factory()->create()], true],
]);

it('allows the cart by sale mode, Retail price and stock status', function (array $attributes, bool $expected) {
    config(['site.cart_enabled' => true]);
    $product = Product::factory()->create($attributes);

    expect(ProductCtas::canAddToCart($product))->toBe($expected)
        ->and(ctaShape(ProductCtas::for($product))[0][0])->toBe($expected ? 'cart' : 'quote');
})->with([
    'in stock, both' => [['stock_status' => StockStatus::InStock, 'sale_mode' => SaleMode::Both], true],
    'in stock, cart' => [['stock_status' => StockStatus::InStock, 'sale_mode' => SaleMode::Cart], true],
    'on order' => [['stock_status' => StockStatus::OnOrder], true],
    'out of stock' => [['stock_status' => StockStatus::OutOfStock], false],
    'quote only' => [['sale_mode' => SaleMode::Quote], false],
    'no Retail price' => [['price' => null, 'sale_mode' => SaleMode::Both], false],
    'zero Retail price' => [['price' => 0], false],
]);

it('never reads the Dealer or Wholesale price to decide the cart', function () {
    $product = Product::factory()->create(['price' => null, 'price_dealer' => 100_000, 'price_wholesale' => 90_000, 'sale_mode' => SaleMode::Both]);

    expect(ProductCtas::canAddToCart($product))->toBeFalse();
});

it('uses Contact us instead of Request a quote for software', function () {
    config(['site.cart_enabled' => true]);
    $product = Product::factory()->software()->create(['sale_mode' => SaleMode::Both, 'price' => 50_000_000]);

    expect(array_column(ctaShape(ProductCtas::for($product)), 0))->toBe(['cart', 'contact']);
});

it('labels Try and Contact us in every locale', function () {
    expect([ProductCtaKind::Try->label('uz'), ProductCtaKind::Try->label('ru'), ProductCtaKind::Try->label('en')])->toBe(['Sinab koʻrish', 'Попробовать', 'Try'])
        ->and([ProductCtaKind::Contact->label('uz'), ProductCtaKind::Contact->label('ru'), ProductCtaKind::Contact->label('en')])->toBe(['Bogʻlanish', 'Связаться', 'Contact us']);
});

it('exposes the CTAs as plain arrays for other clients', function () {
    $product = Product::factory()->withPartnerLink()->create(['slug' => 'ox']);

    expect(ProductCtas::for($product, locale: 'uz')[0]->toArray())->toBe([
        'kind' => 'try',
        'label_key' => 'site.cta.try',
        'action' => 'external',
        'url' => url('/uz/go/ox'),
        'product_id' => $product->id,
        'primary' => true,
    ]);
});

it('renders Try and Contact us on the product page and the card, and Try in the sticky bar', function () {
    $product = Product::factory()->withPartnerLink()->priceOnRequest()->featured()->create(['slug' => 'pospoint']);

    $html = $this->get('/uz/product/pospoint')->assertOk()->getContent();
    $tryLink = '#<a\s+href="'.preg_quote(url('/uz/go/pospoint'), '#').'"\s+target="_blank" rel="nofollow sponsored noopener"#';

    expect(preg_match_all($tryLink, $html))->toBe(2)
        ->and(substr_count($html, 'data-cta="try"'))->toBe(2)
        // Buy box: Try + Contact us; sticky bar: the primary (Try) only.
        ->and(substr_count($html, 'data-cta="contact"'))->toBe(1)
        ->and($html)->toContain('Sinab koʻrish')
        ->and($html)->toContain('Bogʻlanish')
        ->and($html)->not->toContain('data-cta="quote"')
        // The referral link itself never reaches the page (only the tracked redirect).
        ->and($html)->not->toContain('partner.example.uz');

    $card = $this->get('/ru')->assertOk()->getContent();
    preg_match('#<article[^>]*data-product-card[^>]*>(.*?)</article>#s', $card, $match);

    expect($match)->not->toBeEmpty();
    expect($match[1])->toContain('href="'.url('/ru/go/pospoint').'"')
        ->toContain('rel="nofollow sponsored noopener"')
        ->toContain('data-cta="contact"')
        ->toContain('href="'.url('/ru/product/pospoint').'#quote"');
});

it('keeps one lead-form button for software without a partner URL', function () {
    Product::factory()->software()->priceOnRequest()->create(['slug' => 'hippo']);

    $html = $this->get('/en/product/hippo')->assertOk()->getContent();

    expect(substr_count($html, 'data-cta="contact"'))->toBe(2)
        ->and($html)->not->toContain('data-cta="try"')
        ->and($html)->not->toContain('/go/hippo')
        ->and($html)->toContain('href="#quote"');
});

/**
 * A product of the given type in a category with the given slug.
 */
function ctaProductIn(string $categorySlug, ProductType $type = ProductType::Hardware, array $attributes = []): Product
{
    $category = Category::factory()->create(['slug' => $categorySlug]);
    CategoryTree::flush();

    return Product::factory()->create(['category_id' => $category->id, 'type' => $type, 'sale_mode' => SaleMode::Both, 'price' => 125_000_000, ...$attributes]);
}

describe('service requests (plan 36)', function () {
    beforeEach(fn () => CategoryTree::flush());

    it('asks a service product for a service request, keeping Add to cart when it is on', function (bool $cartEnabled, array $expected) {
        config(['site.cart_enabled' => $cartEnabled]);
        $service = ctaProductIn('services', ProductType::Service);

        expect(ctaShape(ProductCtas::for($service)))->toBe($expected);

        $ctas = ProductCtas::for($service);
        $inquiry = collect($ctas)->first(fn (ProductCta $cta): bool => $cta->kind === ProductCtaKind::ServiceRequest);
        expect($inquiry->labelKey)->toBe('site.cta.request_service')
            ->and($inquiry->pageHref())->toBe('#quote');
    })->with([
        'cart off' => [false, [['service_request', ProductCta::ACTION_LEAD_FORM, true]]],
        'cart on' => [true, [['cart', ProductCta::ACTION_CART, true], ['service_request', ProductCta::ACTION_LEAD_FORM, false]]],
    ]);

    it('adds Service for this device to serviceable hardware on the full call only', function (string $category) {
        config(['site.cart_enabled' => false]);
        $product = ctaProductIn($category, attributes: ['slug' => 'zd421']);

        $ctas = ProductCtas::for($product, locale: 'ru');

        expect(ctaShape($ctas))->toBe([
            ['quote', ProductCta::ACTION_LEAD_FORM, true],
            ['device_service', ProductCta::ACTION_LINK, false],
        ])
            ->and($ctas[1]->url)->toBe(url('/ru/services/request?product=zd421'))
            ->and($ctas[1]->pageHref())->toBe($ctas[1]->url)
            ->and($ctas[1]->labelKey)->toBe('site.cta.device_service')
            ->and(ctaShape(ProductCtas::for($product, compact: true)))->toBe([['quote', ProductCta::ACTION_LEAD_FORM, true]]);
    })->with(['scanners', 'label-printers', 'mobile-computers', 'pos']);

    it('keeps Service for this device after Add to cart and Request a quote', function () {
        config(['site.cart_enabled' => true]);
        $product = ctaProductIn('scanners');

        expect(array_column(ctaShape(ProductCtas::for($product)), 0))->toBe(['cart', 'quote', 'device_service'])
            ->and(array_column(ctaShape(ProductCtas::for($product, compact: true)), 0))->toBe(['cart']);
    });

    it('gives no device service to consumables, software, excluded categories or services', function (string $category, ProductType $type) {
        config(['site.cart_enabled' => false]);

        expect(array_column(ctaShape(ProductCtas::for(ctaProductIn($category, $type))), 0))->not->toContain('device_service');
    })->with([
        'consumable' => ['labels', ProductType::Consumable],
        'software' => ['software', ProductType::Software],
        'service' => ['services', ProductType::Service],
        'excluded category' => ['rfid-tags', ProductType::Hardware],
        'unmapped category' => ['monitors', ProductType::Hardware],
    ]);

    it('renders the device service link with rel nofollow and the service request button on the product page', function () {
        config(['site.cart_enabled' => false]);
        $scanner = ctaProductIn('scanners', attributes: ['slug' => 'ds2208']);
        $service = ctaProductIn('services', ProductType::Service, ['slug' => 'setup']);

        $html = $this->get('/en/product/ds2208')->assertOk()->getContent();
        expect($html)->toContain('rel="nofollow"')
            ->and($html)->toContain('data-cta="device_service"')
            ->and($html)->toContain('/en/services/request?product=ds2208')
            ->and($html)->toContain(__('site.cta.device_service', [], 'en'));

        $this->get('/en/product/setup')->assertOk()
            ->assertSee(__('site.cta.request_service', [], 'en'))
            ->assertSee(__('site.product.service_request.heading', [], 'en'))
            ->assertDontSee('data-cta="device_service"', false);
    });

    it('translates both labels in every locale', function (string $locale) {
        expect(ProductCtaKind::ServiceRequest->label($locale))->not->toBe('site.cta.request_service')
            ->and(ProductCtaKind::DeviceService->label($locale))->not->toBe('site.cta.device_service');
    })->with(['uz', 'ru', 'en']);

    it('shows Request service on compact cards of service products and round-trips the new kinds and action', function () {
        config(['site.cart_enabled' => false]);
        $card = ProductCtas::for(ctaProductIn('services', ProductType::Service), compact: true);

        expect(ctaShape($card))->toBe([['service_request', ProductCta::ACTION_LEAD_FORM, true]]);

        $link = ProductCtas::for(ctaProductIn('scanners'), locale: 'en')[1];

        foreach ([$card[0], $link] as $cta) {
            $copy = ProductCta::fromArray($cta->toArray());
            expect($copy)->toEqual($cta);
        }
        expect($link->action)->toBe(ProductCta::ACTION_LINK)
            ->and($link->kind)->toBe(ProductCtaKind::DeviceService)
            ->and(HomeCache::SCHEMA)->toBeGreaterThanOrEqual(2);
    });

    it('adds no queries to a serviceable hardware product page', function () {
        config(['site.cart_enabled' => false]);
        $scanner = ctaProductIn('scanners', attributes: ['slug' => 'warm-scanner']);
        $plain = ctaProductIn('monitors', attributes: ['slug' => 'warm-monitor']);
        $this->get('/en/product/warm-scanner')->assertOk();

        $first = countQueries(fn () => $this->get('/en/product/warm-scanner')->assertOk());
        $second = countQueries(fn () => $this->get('/en/product/warm-scanner')->assertOk());
        $unmapped = countQueries(fn () => $this->get('/en/product/warm-monitor')->assertOk());

        expect($second)->toBe($first)->and($first)->toBe($unmapped);
    });

    it('leaves the compact card payload shape unchanged', function () {
        $card = ProductCtas::for(ctaProductIn('scanners'), compact: true)[0]->toArray();

        expect(array_keys($card))->toBe(['kind', 'label_key', 'action', 'url', 'product_id', 'primary']);
    });

    it('shows the service block on the warranty page only', function () {
        Page::factory()->create(['slug' => 'warranty-service', 'title' => ['uz' => 'Kafolat'], 'body' => ['uz' => '<p>Body text</p>']]);
        Page::factory()->create(['slug' => 'about-us', 'title' => ['uz' => 'About'], 'body' => ['uz' => '<p>Other</p>']]);

        $this->get('/en/page/warranty-service')->assertOk()
            ->assertSee('data-warranty-service-block', false)
            ->assertSee('Body text')
            ->assertSee(url('/en/services/request'), false);
        $this->get('/en/page/about-us')->assertOk()->assertDontSee('data-warranty-service-block', false);
    });

    it('lists the request page in the sitemap and llms.txt', function () {
        openSearchIndexing();
        Cache::flush();

        $xml = app(Sitemap::class)->generate()['xml'];
        foreach (['uz', 'ru', 'en'] as $locale) {
            expect($xml)->toContain('<loc>'.url("/{$locale}/services/request").'</loc>');
        }
        expect($xml)->not->toContain('services/request?');

        expect(app(LlmsTxt::class)->build())->toContain(url('/en/services/request'));
    });
});
