<?php

use App\Data\Site\ProductCta;
use App\Enums\ProductCtaKind;
use App\Enums\SaleMode;
use App\Models\Brand;
use App\Models\Product;
use App\Support\Catalog\ProductCtas;

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
})->with(['quoteOnly', 'consumable', 'service']);

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

it('keeps label products (priced per label, sold in rolls) quote-only until the cart supports rolls', function () {
    config(['site.cart_enabled' => true]);
    $product = Product::factory()->perLabel()->create(['sale_mode' => SaleMode::Both]);

    expect(ProductCtas::canAddToCart($product))->toBeFalse()
        ->and(ctaShape(ProductCtas::for($product)))->toBe([['quote', ProductCta::ACTION_LEAD_FORM, true]])
        ->and(ctaShape(ProductCtas::for($product, compact: true)))->toBe([['quote', ProductCta::ACTION_LEAD_FORM, true]]);
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
    $related = Product::factory()->create();
    $related->relations()->create(['related_id' => $product->id, 'type' => 'alternative']);

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

    $card = $this->get('/ru/product/'.$related->slug)->assertOk()->getContent();
    preg_match('#<article[^>]*data-product-card[^>]*>(.*?)</article>#s', $card, $match);

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
