<?php

use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Brand;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductDocument;
use App\Support\Money;
use App\Support\Telegram\MiniApp\TmaCart;
use Database\Seeders\CatalogSeeder;
use Illuminate\Database\Eloquent\Model;

require_once __DIR__.'/../../../Helpers/MiniApp.php';

/**
 * Plan 20 M2: home, category with filters, search and the product page (§6.3 CTA matrix).
 */
beforeEach(function () {
    $this->withoutVite();
    tmaEnabled();
    $this->tgUser = tmaUser();
    tmaSignIn($this->tgUser);
    config(['site.cart_enabled' => true]);
});

it('lists the visible top-level categories on the home screen with the full logo', function () {
    $visible = Category::factory()->create(['name' => ['uz' => 'Skanerlar', 'ru' => 'Сканеры', 'en' => 'Scanners']]);
    $hidden = Category::factory()->inactive()->create(['name' => ['uz' => 'Yashirin', 'ru' => 'Скрыто', 'en' => 'Hidden']]);

    $this->get('/tma/uz')->assertOk()
        ->assertSee('data-logo="full"', false)
        ->assertSee(route('tma.category', ['locale' => 'uz', 'category' => $visible->slug]), false)
        ->assertDontSee('Yashirin')
        ->assertSee('data-cart-count="0"', false);
});

it('shows the same products as the site listing and 404s a hidden category or a page past the end', function () {
    $category = Category::factory()->create();
    $products = Product::factory()->count(3)->inCategory($category)->create();
    Product::factory()->inactive()->inCategory($category)->create(['slug' => 'draft-product']);

    $html = $this->get('/tma/uz/c/'.$category->slug)->assertOk()->getContent();

    foreach ($products as $product) {
        expect($html)->toContain('data-product-card="'.$product->slug.'"');
    }

    expect($html)->not->toContain('draft-product')
        ->not->toContain('/uz/product/')
        ->and(substr_count($html, 'data-product-card'))->toBe(3);

    $this->get('/tma/uz/c/'.$category->slug.'?page=5')->assertNotFound();
    $this->get('/tma/uz/c/'.Category::factory()->inactive()->create()->slug)->assertNotFound();
});

it('filters by brand through the query string', function () {
    $category = Category::factory()->create();
    $zebra = Brand::factory()->create(['slug' => 'zebra']);
    $kept = Product::factory()->inCategory($category)->create(['brand_id' => $zebra->id]);
    $dropped = Product::factory()->inCategory($category)->create();

    $this->get('/tma/uz/c/'.$category->slug.'?brand[]=zebra')->assertOk()
        ->assertSee('data-product-card="'.$kept->slug.'"', false)
        ->assertDontSee('data-product-card="'.$dropped->slug.'"', false);
});

it('draws colour swatches with SVG fill only and the All / Any switch only where allowed', function () {
    $this->seed(CatalogSeeder::class);
    $ribbons = Category::query()->where('slug', 'ribbons')->firstOrFail();
    $colour = Attribute::query()->where('code', 'ribbon_colour')->firstOrFail();
    $green = $colour->options()->get()->first(fn (AttributeOption $option) => $option->getTranslation('value', 'uz', false) === 'Yashil');
    $product = Product::factory()->consumable()->inCategory($ribbons)->create();
    ProductAttributeValue::factory()->for($product)->option($green)->create();

    $html = $this->get('/tma/en/c/ribbons/filters')->assertOk()->getContent();
    preg_match('#data-attribute-filter="ribbon_colour".*?</fieldset>#s', $html, $fieldset);

    expect($fieldset[0] ?? '')->toContain('fill="#00843D"')->toContain('data-swatch')
        ->and($html)->not->toContain('style=');

    $colour->forceFill(['type' => 'multiselect', 'filter_match_switch' => false])->save();
    expect($this->get('/tma/en/c/ribbons/filters')->getContent())->not->toContain('data-match-switch');
});

it('searches like the site', function () {
    $product = Product::factory()->create(['sku' => 'ZD421-T', 'search_text' => 'zd421-t zebra printer']);

    $this->get('/tma/uz/search?q=zd421')->assertOk()->assertSee('data-product-card="'.$product->slug.'"', false);
    $this->get('/tma/uz/search?q=nothing-like-this')->assertOk()->assertSee('data-no-results', false);
});

it('404s a product that is not on the site', function () {
    $this->get('/tma/uz/p/'.Product::factory()->inactive()->create()->slug)->assertNotFound();
    $this->get('/tma/uz/p/'.Product::factory()->inCategory(Category::factory()->inactive()->create())->create()->slug)->assertNotFound();
});

it('shows the Retail price only, with the cart form for cart products', function () {
    $product = Product::factory()->create(['price' => 1_250_000_00, 'price_dealer' => 1_111_111_00, 'price_wholesale' => 1_099_999_00]);

    $html = $this->get('/tma/uz/p/'.$product->slug)->assertOk()->getContent();

    expect($html)->toContain(Money::format(1_250_000_00))
        ->not->toContain(Money::format(1_111_111_00))
        ->not->toContain(Money::format(1_099_999_00))
        ->toContain('data-cart-form')
        ->toContain('data-request-form="quote"')
        ->not->toContain('/uz/product/');
    expectNoBannedWording($html);
});

it('maps the CTA kinds (§6.3)', function (Closure $make, array $see, array $dontSee) {
    $product = $make();
    $html = $this->get('/tma/uz/p/'.$product->slug)->assertOk()->getContent();

    foreach ($see as $needle) {
        expect($html)->toContain($needle);
    }

    foreach ($dontSee as $needle) {
        expect($html)->not->toContain($needle);
    }
})->with([
    'quote only' => [fn () => Product::factory()->quoteOnly()->create(), ['data-request-form="quote"'], ['data-cart-form']],
    'price on request' => [fn () => Product::factory()->priceOnRequest()->create(), ['data-request-form="quote"'], ['data-cart-form']],
    'service product' => [fn () => Product::factory()->service()->create(), ['data-request-form="service_request"', 'data-cta="service_request"'], ['data-cta="device_service"']],
    'software' => [fn () => Product::factory()->software()->quoteOnly()->create(), ['data-request-form="contact"'], ['data-cart-form']],
    'partner software' => [fn () => Product::factory()->software()->withPartnerLink()->create(), ['data-cta="try"', 'data-open-link="https://poson.example.uz/uz/go/'], ['data-cart-form']],
    'label product' => [fn () => Product::factory()->perLabel()->withRollTypes()->create(), ['data-roll-picker', 'name="roll_type_id"', 'data-roll-price'], []],
]);

it('offers the device service form for serviceable hardware', function () {
    $scanners = Category::factory()->create(['slug' => 'scanners']);
    $product = Product::factory()->inCategory($scanners)->create();

    $this->get('/tma/uz/p/'.$product->slug)->assertOk()->assertSee(route('tma.service', ['locale' => 'uz', 'product' => $product->slug]), false);
    $this->get('/tma/uz/p/'.$product->slug.'/service')->assertOk()->assertSee('data-service-form', false);
    $this->get('/tma/uz/p/'.Product::factory()->create()->slug.'/service')->assertNotFound();
});

it('draws the generated diagram without images', function () {
    $product = Product::factory()->perLabel()->withRollTypes()->create();

    expect($this->get('/tma/uz/p/'.$product->slug)->getContent())->not->toContain('style=');
});

it('opens documents and description links outside Telegram, https only (MA-22)', function () {
    $product = Product::factory()->create(['description' => [
        'uz' => '<p><a href="https://vendor.example/manual">Qoʻllanma</a> <a href="/uz/page/delivery">Yetkazish</a> <a href="mailto:a@b.uz">Pochta</a></p>',
        'ru' => '', 'en' => '',
    ]]);
    ProductDocument::factory()->for($product)->create(['path' => null, 'url' => 'https://vendor.example/datasheet.pdf']);
    ProductDocument::factory()->for($product)->create(['path' => null, 'url' => 'http://insecure.example/x.pdf']);

    $html = $this->get('/tma/uz/p/'.$product->slug)->assertOk()->getContent();

    expect($html)->toContain('data-open-link="https://vendor.example/manual"')
        ->toContain('data-open-link="https://poson.example.uz/uz/page/delivery"')
        ->toContain('data-open-link="https://vendor.example/datasheet.pdf"')
        ->not->toContain('insecure.example')
        ->not->toContain('mailto:');
});

it('stays within the §8 query budget without lazy loading: listing and product ≤ 15, cart ≤ 8', function () {
    $category = Category::factory()->create();
    $products = Product::factory()->count(20)->inCategory($category)->create();
    $product = $products->first();

    foreach ($products as $listed) {
        ProductAttributeValue::factory()->for($listed)->key()->create();
    }

    $cart = TmaCart::for($this->tgUser, 'uz');
    foreach ($products->take(5) as $line) {
        CartItem::factory()->for($cart)->create(['product_id' => $line->id, 'qty' => 1]);
    }

    Model::preventLazyLoading();

    expect(countQueries(fn () => $this->get('/tma/uz/c/'.$category->slug)->assertOk()))->toBeLessThanOrEqual(15)
        ->and(countQueries(fn () => $this->get('/tma/uz/p/'.$product->slug)->assertOk()))->toBeLessThanOrEqual(15)
        ->and(countQueries(fn () => $this->get('/tma/uz/search?q='.urlencode($product->sku))->assertOk()))->toBeLessThanOrEqual(15)
        ->and(countQueries(fn () => $this->get('/tma/uz/cart')->assertOk()))->toBeLessThanOrEqual(8);
});

it('keeps the rendered home, category and product pages within 30 KB gzipped (§8)', function () {
    $category = Category::factory()->create();
    $products = Product::factory()->count(20)->inCategory($category)->create(['description' => ['uz' => '<p>'.str_repeat('Mahsulot tavsifi. ', 200).'</p>', 'ru' => '', 'en' => '']]);
    Category::factory()->count(12)->create();

    foreach (['/tma/uz', '/tma/uz/c/'.$category->slug, '/tma/uz/p/'.$products->first()->slug] as $url) {
        expect(strlen(gzencode($this->get($url)->assertOk()->getContent(), 9)))->toBeLessThanOrEqual(30 * 1024, $url);
    }
});

it('drops the cart form and the counter while the cart is off', function () {
    config(['site.cart_enabled' => false]);
    $product = Product::factory()->create();

    $html = $this->get('/tma/uz/p/'.$product->slug)->assertOk()->getContent();

    expect($html)->not->toContain('data-cart-form')->not->toContain('data-cart-count')->toContain('data-request-form="quote"');
    $this->get('/tma/uz/cart')->assertNotFound();
    $this->get('/tma/uz/checkout')->assertNotFound();
});
