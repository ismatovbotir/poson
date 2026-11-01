<?php

use App\Enums\LeadType;
use App\Enums\Role;
use App\Enums\SaleMode;
use App\Enums\StockStatus;
use App\Enums\VisitType;
use App\Livewire\Site\Cart\AddButton;
use App\Livewire\Site\Cart\Counter;
use App\Livewire\Site\Cart\Page;
use App\Livewire\Site\Catalog\RollPicker;
use App\Livewire\Site\LeadForm;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Lead;
use App\Models\LeadItem;
use App\Models\Product;
use App\Models\RollType;
use App\Models\Setting;
use App\Models\SiteVisit;
use App\Support\Analytics\TrafficRecorder;
use App\Support\Cart\CartToken;
use App\Support\CompanySettings;
use App\Support\Money;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    config(['site.cart_enabled' => true]);
    app()->setLocale('en');
    URL::defaults(['locale' => 'en']);
});

/**
 * A cart of the current visitor (its token remembered for this test's requests).
 */
function visitorCart(): Cart
{
    $cart = Cart::factory()->create();
    app(CartToken::class)->issue($cart->token);

    return $cart;
}

/**
 * The cart page with a filled-in quote form, opened long enough ago to pass the fill-time check.
 */
function filledCartQuote(array $overrides = []): Testable
{
    $component = Livewire::test(Page::class);

    foreach (array_merge(['name' => 'Aziz', 'phone' => '90 123 45 67', 'company' => 'OOO Market'], $overrides) as $field => $value) {
        $component->set($field, $value);
    }

    test()->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();

    return $component;
}

describe('route', function () {
    it('is a 404 while the cart is off', function () {
        config(['site.cart_enabled' => false]);

        $this->get('/en/cart')->assertNotFound();
        $this->get('/uz/cart')->assertNotFound();
    });

    it('shows an empty cart without creating a cookie or a row, never indexed', function () {
        $response = $this->get('/en/cart')->assertOk()
            ->assertSee('<meta name="robots" content="noindex,nofollow">', false)
            ->assertSee(__('checkout.cart.empty_title'));

        expect($response->headers->getCookies())->not->toContain(fn ($cookie) => $cookie->getName() === CartToken::COOKIE)
            ->and(collect($response->headers->getCookies())->map->getName()->all())->not->toContain(CartToken::COOKIE)
            ->and(Cart::query()->count())->toBe(0);
    });

    it('is disallowed in robots.txt and left out of the sitemap', function () {
        openSearchIndexing();

        $this->get('/robots.txt')->assertSee('Disallow: /*/cart$');
        $this->get('/sitemap.xml')->assertDontSee('/cart');
    });

    it('renders the lines from the encrypted cookie at Retail prices, flagging unavailable ones', function () {
        $cart = Cart::factory()->create();
        $scanner = Product::factory()->create(['name' => ['uz' => 'Skaner', 'ru' => 'Сканер', 'en' => 'Scanner Z'], 'price' => 125_000_000, 'price_dealer' => 111_100_000, 'stock_status' => StockStatus::OnOrder]);
        $gone = Product::factory()->create(['name' => ['uz' => 'Eski', 'ru' => 'Старый', 'en' => 'Old printer'], 'price' => 99_900_000]);
        CartItem::factory()->for($cart)->for($scanner)->create(['qty' => 2]);
        CartItem::factory()->for($cart)->for($gone)->create(['qty' => 1]);
        $gone->update(['stock_status' => StockStatus::OutOfStock]);

        $html = $this->withCookie(CartToken::COOKIE, $cart->token)->get('/en/cart')->assertOk()->getContent();

        expect($html)->toContain('Scanner Z')
            ->toContain(Money::format(250_000_000))
            ->toContain(__('checkout.cart.on_order_note'))
            ->toContain('Old printer')
            ->toContain(__('checkout.cart.unavailable'))
            ->toContain(__('checkout.cart.remove_unavailable'))
            ->not->toContain(Money::format(111_100_000))
            ->not->toContain(Money::format(99_900_000))
            ->not->toContain($cart->token)
            ->and(substr_count($html, 'data-valid="false"'))->toBe(1);

        expectNoBannedWording($html);
    });

    it('shows the header counter only while the cart is on', function () {
        $cart = Cart::factory()->create();
        CartItem::factory()->count(3)->for($cart)->create();

        $this->withCookie(CartToken::COOKIE, $cart->token)->get('/en')->assertOk()
            ->assertSee('data-cart-counter', false)
            ->assertSee('data-cart-count>3<', false);

        config(['site.cart_enabled' => false]);
        $this->get('/en')->assertOk()->assertDontSee('data-cart-counter', false);
    });
});

describe('page component', function () {
    it('keeps no cart id, token, prices, totals or flags in public properties', function () {
        $cart = visitorCart();
        $product = Product::factory()->create(['price' => 777_700]);
        CartItem::factory()->for($cart)->for($product)->create(['qty' => 3]);

        $component = Livewire::test(Page::class)->assertSee('p'.$product->id, false);
        $state = json_encode($component->instance()->all());

        expect(array_keys($component->instance()->all()))->toEqualCanonicalizing(['name', 'phone', 'company', 'message', 'fax_ext', 'sent', 'locale', 'pageUrl', 'attribution', 'renderedAt'])
            ->and($state)->not->toContain($cart->token)
            ->not->toContain('777700')
            ->not->toContain('2333100')
            ->not->toContain('"cart');
    });

    it('changes quantities and removes lines by key, ignoring tampered keys', function (mixed $badKey) {
        $cart = visitorCart();
        $product = Product::factory()->create(['min_qty' => 2]);
        $other = Product::factory()->create();
        CartItem::factory()->for($cart)->for($product)->create(['qty' => 2]);
        CartItem::factory()->for($cart)->for($other)->create(['qty' => 1]);
        $key = 'p'.$product->id;

        Livewire::test(Page::class)
            ->call('increment', $key)
            ->assertDispatched('cart-updated')
            ->call('updateQty', $badKey, 9)
            ->call('remove', $badKey)
            ->call('increment', $badKey);

        expect($cart->items()->pluck('qty', 'line_key')->all())->toBe([$key => 3, 'p'.$other->id => 1]);

        Livewire::test(Page::class)
            ->call('updateQty', $key, '12')
            ->call('decrement', $key);
        expect($cart->items()->where('line_key', $key)->value('qty'))->toBe(11);

        Livewire::test(Page::class)->call('remove', $key)->assertDispatched('cart-updated');
        expect($cart->items()->pluck('line_key')->all())->toBe(['p'.$other->id]);
    })->with([
        'sql' => ["p1' or '1'='1"],
        'array' => [['p1']],
        'number' => [12],
        'other cart line id' => ['12'],
        'null' => [null],
    ]);

    it('does not touch another visitor\'s cart', function () {
        $mine = visitorCart();
        $theirs = Cart::factory()->create();
        $product = Product::factory()->create();
        CartItem::factory()->for($theirs)->for($product)->create(['qty' => 4]);

        Livewire::test(Page::class)->call('remove', 'p'.$product->id)->call('increment', 'p'.$product->id);

        expect($theirs->items()->sole()->qty)->toBe(4)
            ->and($mine->items()->count())->toBe(0);
    });

    it('rejects a quantity below min_qty with a message', function () {
        $cart = visitorCart();
        $product = Product::factory()->create(['min_qty' => 5]);
        CartItem::factory()->for($cart)->for($product)->create(['qty' => 5]);

        Livewire::test(Page::class)
            ->call('updateQty', 'p'.$product->id, 2)
            ->assertHasErrors('cart');

        expect($cart->items()->sole()->qty)->toBe(5);
    });

    it('404s on component requests once the cart is switched off', function () {
        visitorCart();
        $component = Livewire::test(Page::class);
        config(['site.cart_enabled' => false]);

        $component->call('increment', 'p1')->assertStatus(404);
    });
});

describe('more page behaviour', function () {
    it('shows only the SKU and a neutral label for a product that left the site', function () {
        $cart = Cart::factory()->create();
        $product = Product::factory()->create(['sku' => 'OLD-77', 'name' => ['uz' => 'Maxfiy', 'ru' => 'Секрет', 'en' => 'Secret prototype']]);
        CartItem::factory()->for($cart)->for($product)->create();
        $product->update(['is_active' => false]);

        $this->withCookie(CartToken::COOKIE, $cart->token)->get('/en/cart')->assertOk()
            ->assertSee('OLD-77')
            ->assertSee(__('checkout.cart.unavailable_product'))
            ->assertDontSee('Secret prototype');
    });

    it('keeps a line at 10 000 when more is asked for', function () {
        $cart = visitorCart();
        $product = Product::factory()->create();
        CartItem::factory()->for($cart)->for($product)->create(['qty' => Cart::MAX_QTY]);

        Livewire::test(Page::class)
            ->call('increment', 'p'.$product->id)
            ->assertHasErrors('cart')
            ->call('updateQty', 'p'.$product->id, '25000');

        expect($cart->items()->sole()->qty)->toBe(Cart::MAX_QTY);
    });

    it('sends the cookie again with a fresh expiry after a change or removal', function () {
        $cart = visitorCart();
        $product = Product::factory()->create();
        CartItem::factory()->for($cart)->for($product)->create(['qty' => 1]);
        Cookie::unqueue(CartToken::COOKIE);

        Livewire::test(Page::class)->call('increment', 'p'.$product->id);
        expect(Cookie::queued(CartToken::COOKIE)?->getValue())->toBe($cart->token)
            ->and(Cookie::queued(CartToken::COOKIE)?->getExpiresTime())->toBeGreaterThanOrEqual(now()->addDays(30)->getTimestamp() - 5);

        Cookie::unqueue(CartToken::COOKIE);
        Livewire::test(Page::class)->call('remove', 'p'.$product->id);
        expect(Cookie::queued(CartToken::COOKIE)?->getValue())->toBe($cart->token);

        // A rejected change sends nothing.
        Cookie::unqueue(CartToken::COOKIE);
        Livewire::test(Page::class)->call('increment', 'p'.$product->id);
        expect(Cookie::queued(CartToken::COOKIE))->toBeNull();
    });
});

describe('quote for the cart', function () {
    it('leaves out a line whose roll type was detached but keeps a quote-only line', function () {
        $cart = visitorCart();
        $rollType = RollType::factory()->create();
        $labels = Product::factory()->perLabel()->withRollTypes([$rollType])->create(['sku' => 'LBL-D', 'min_qty' => 2]);
        $quoteOnly = Product::factory()->create(['sku' => 'QUO-1']);
        CartItem::factory()->for($cart)->for($labels)->roll($rollType)->create(['qty' => 2]);
        CartItem::factory()->for($cart)->for($quoteOnly)->create(['qty' => 1]);
        $labels->rollTypes()->detach($rollType->id);
        $quoteOnly->update(['sale_mode' => SaleMode::Quote]);

        filledCartQuote()->call('requestQuote')->assertSet('sent', true);

        expect(LeadItem::query()->pluck('sku')->all())->toBe(['QUO-1']);
    });

    it('creates a checkout lead with the cart lines, label products with their roll snapshot', function () {
        $cart = visitorCart();
        $rollType = RollType::factory()->size(1000, '40.0')->create();
        $labels = Product::factory()->perLabel()->withRollTypes([$rollType])->create(['sku' => 'LBL-1', 'min_qty' => 2]);
        $scanner = Product::factory()->create(['sku' => 'SCN-1']);
        $hidden = Product::factory()->create(['sku' => 'GONE-1']);
        CartItem::factory()->for($cart)->for($scanner)->create(['qty' => 2]);
        CartItem::factory()->for($cart)->for($labels)->roll($rollType)->create(['qty' => 4]);
        CartItem::factory()->for($cart)->for($hidden)->create(['qty' => 1]);
        $hidden->update(['is_active' => false]);

        filledCartQuote(['message' => 'Delivery to Fergana'])
            ->call('requestQuote')
            ->assertHasNoErrors()
            ->assertSet('sent', true);

        $lead = Lead::query()->sole();
        $items = LeadItem::query()->orderBy('sort')->get();

        expect($lead->type)->toBe(LeadType::Checkout)
            ->and($lead->phone)->toBe('+998901234567')
            ->and($lead->message)->toBe('Delivery to Fergana')
            ->and($items->pluck('sku')->all())->toBe(['SCN-1', 'LBL-1'])
            ->and($items[0]->qty)->toBe(2)
            ->and($items[0]->roll_type_id)->toBeNull()
            ->and($items[1]->qty)->toBe(4)
            ->and($items[1]->roll_type_id)->toBe($rollType->id)
            ->and($items[1]->labels_per_roll)->toBe(1000)
            ->and((string) $items[1]->core_diameter_mm)->toBe('40.0')
            // The cart is kept.
            ->and($cart->items()->count())->toBe(3);
    });

    it('applies the lead form checks: honeypot, fill time, Uzbek phone', function (Closure $submit, bool $sent) {
        $cart = visitorCart();
        CartItem::factory()->for($cart)->create();

        $component = $submit();

        expect(Lead::query()->count())->toBe(0);
        $component->assertSet('sent', $sent);
    })->with([
        'honeypot' => [fn () => filledCartQuote(['fax_ext' => 'spam'])->call('requestQuote'), true],
        'too fast' => [fn () => Livewire::test(Page::class)->set('name', 'Aziz')->set('phone', '90 123 45 67')->call('requestQuote'), true],
        'foreign phone' => [fn () => filledCartQuote(['phone' => '+7 912 345 67 89'])->call('requestQuote')->assertHasErrors('phone'), false],
        'no name' => [fn () => filledCartQuote(['name' => ''])->call('requestQuote')->assertHasErrors('name'), false],
    ]);

    it('shares the lead form rate limits', function () {
        $cart = visitorCart();
        CartItem::factory()->for($cart)->create();

        foreach (range(1, LeadForm::PHONE_LIMIT) as $attempt) {
            filledCartQuote()->call('requestQuote')->assertSet('sent', true);
        }

        filledCartQuote()->call('requestQuote')->assertHasErrors('form')->assertSet('sent', false);

        expect(Lead::query()->count())->toBe(LeadForm::PHONE_LIMIT);
    });

    it('says so when nothing in the cart can be quoted', function () {
        $cart = visitorCart();
        $product = Product::factory()->create();
        CartItem::factory()->for($cart)->for($product)->create();
        $product->delete();

        filledCartQuote()->call('requestQuote')->assertHasErrors('form')->assertSet('sent', false);

        expect(Lead::query()->count())->toBe(0);
    });
});

describe('adding', function () {
    it('adds from the product page button, issues the cookie once and refreshes the counter', function () {
        $product = Product::factory()->create(['min_qty' => 2]);

        $component = Livewire::test(AddButton::class, ['productId' => $product->id])
            ->assertSet('qty', 2)
            ->call('increment')
            ->call('add')
            ->assertHasNoErrors()
            ->assertSet('added', true)
            ->assertDispatched('cart-updated');

        $cart = Cart::query()->sole();

        expect(Cookie::queued(CartToken::COOKIE)?->getValue())->toBe($cart->token)
            ->and($cart->items()->sole()->qty)->toBe(3);

        $component->call('add');
        expect(Cart::query()->count())->toBe(1)
            ->and($cart->items()->sole()->qty)->toBe(6);

        Livewire::test(Counter::class)->assertSee('data-cart-count>1<', false);
    });

    it('normalizes a tampered quantity instead of failing', function (mixed $qty, int $expected) {
        $product = Product::factory()->create(['min_qty' => 2]);

        Livewire::test(AddButton::class, ['productId' => $product->id])
            ->set('qty', $qty)
            ->assertSet('qty', $expected)
            ->call('add');

        expect(CartItem::query()->sole()->qty)->toBe($expected);
    })->with([
        'text' => ['abc', 2],
        'array' => [[5], 2],
        'below minimum' => ['1', 2],
        'negative' => [-50, 2],
        'too many' => ['999999', Cart::MAX_QTY],
        'numeric string' => ['7', 7],
        'decimal' => ['3.7', 3],
    ]);

    it('locks the product id', function () {
        $product = Product::factory()->create();

        Livewire::test(AddButton::class, ['productId' => $product->id])->set('productId', 99);
    })->throws(CannotUpdateLockedPropertyException::class);

    it('creates no cookie or cart when the add is rejected', function () {
        $product = Product::factory()->create(['stock_status' => StockStatus::OutOfStock]);

        Livewire::test(AddButton::class, ['productId' => $product->id])->call('add')->assertHasErrors('cart');

        expect(Cart::query()->count())->toBe(0)
            ->and(Cookie::queued(CartToken::COOKIE))->toBeNull();
    });

    it('limits new carts to 10 per hour per IP for cookie-less clients, without limiting adds to an existing cart', function () {
        $product = Product::factory()->create();

        foreach (range(1, AddButton::CART_NEW_LIMIT + 1) as $attempt) {
            // A client that drops the cookie: no token remembered between requests.
            app()->forgetInstance(CartToken::class);
            $component = Livewire::test(AddButton::class, ['productId' => $product->id])->call('add');
        }

        $component->assertHasErrors('cart');
        expect(Cart::query()->count())->toBe(AddButton::CART_NEW_LIMIT);

        // The visitor of an existing cart keeps adding.
        app()->forgetInstance(CartToken::class);
        app(CartToken::class)->issue(Cart::query()->first()->token);
        Livewire::test(AddButton::class, ['productId' => $product->id])->call('add')->assertHasNoErrors();
        expect(Cart::query()->count())->toBe(AddButton::CART_NEW_LIMIT);
    });

    it('limits adds to 60 per minute per IP', function () {
        $product = Product::factory()->create();
        $component = Livewire::test(AddButton::class, ['productId' => $product->id]);

        foreach (range(1, AddButton::CART_ADD_LIMIT) as $attempt) {
            $component->call('add')->assertHasNoErrors();
        }

        $component->call('add')->assertHasErrors('cart');
        expect(CartItem::query()->sole()->qty)->toBe(AddButton::CART_ADD_LIMIT);

        $this->travel(61)->seconds();
        $component->call('add')->assertHasNoErrors();
    });

    it('adds a card product at its minimum through the header counter, never a label product', function () {
        $product = Product::factory()->create(['min_qty' => 3]);
        $labels = Product::factory()->perLabel()->withRollTypes()->create();

        Livewire::test(Counter::class)
            ->dispatch('cart-add', productId: $product->id)
            ->assertDispatched('cart-notice', message: __('checkout.cart.added'), error: false)
            ->dispatch('cart-add', productId: $labels->id)
            ->assertDispatched('cart-notice', error: true)
            ->dispatch('cart-add', productId: 'abc');

        expect(CartItem::query()->sole()->only(['product_id', 'qty']))->toBe(['product_id' => $product->id, 'qty' => 3]);
    });

    it('adds the picked roll type and rolls from the roll picker', function () {
        [$small, $large] = [RollType::factory()->size(500, '40.0')->create(), RollType::factory()->size(1000, '40.0')->create()];
        $product = Product::factory()->perLabel()->withRollTypes([$small, $large])->create(['min_qty' => 2]);

        Livewire::test(RollPicker::class, ['productId' => $product->id])
            ->assertSee(__('site.card.add_to_cart'))
            ->set('rollTypeId', $large->id)
            ->set('rolls', 5)
            ->call('addToCart')
            ->assertSet('added', true)
            ->assertDispatched('cart-updated');

        expect(CartItem::query()->sole()->only(['line_key', 'roll_type_id', 'qty']))->toBe(['line_key' => 'p'.$product->id.'-r'.$large->id, 'roll_type_id' => $large->id, 'qty' => 5]);
    });

    it('hides the roll picker button while the cart is off', function () {
        config(['site.cart_enabled' => false]);
        $product = Product::factory()->perLabel()->withRollTypes()->create();

        Livewire::test(RollPicker::class, ['productId' => $product->id])->assertDontSee('data-roll-add', false);
    });

    it('renders the add button on the product page and an in-place button on cards', function () {
        $product = Product::factory()->featured()->create(['slug' => 'scanner-z']);

        $this->get('/en/product/scanner-z')->assertOk()->assertSee('data-add-button', false)->assertSee('href="#buy"', false);
        $this->get('/en')->assertOk()->assertSee("Livewire.dispatch('cart-add', { productId: {$product->id} })", false);
    });
});

describe('analytics', function () {
    beforeEach(function () {
        config(['analytics.enabled' => true]);
    });

    it('records add_to_cart with the product only', function () {
        $product = Product::factory()->create();

        Livewire::test(AddButton::class, ['productId' => $product->id])->call('add');

        $visit = SiteVisit::query()->where('type', VisitType::AddToCart)->sole();

        expect($visit->product_id)->toBe($product->id)
            ->and($visit->status)->toBe(200);
    });

    it('skips staff and rejected adds', function () {
        $product = Product::factory()->create();
        $this->actingAs(staffUser(Role::SalesManager));

        Livewire::test(AddButton::class, ['productId' => $product->id])->call('add');
        auth()->logout();
        Livewire::test(AddButton::class, ['productId' => Product::factory()->quoteOnly()->create()->id])->call('add');

        expect(SiteVisit::query()->where('type', VisitType::AddToCart)->count())->toBe(0);
    });

    it('sends the dataLayer event only while the site tracks visitors', function () {
        $product = Product::factory()->create(['sku' => 'SCN-9']);

        Livewire::test(AddButton::class, ['productId' => $product->id])->call('add')->assertNotDispatched('poson-add-to-cart');

        Setting::factory()->setting('tracking', ['enabled' => true, 'ga4' => 'G-AB12CD34EF', 'metrika' => ''])->create();
        config(['site.analytics_force' => true]);
        app()->forgetInstance(CompanySettings::class);

        Livewire::test(AddButton::class, ['productId' => $product->id])->call('add')->assertDispatched('poson-add-to-cart', sku: 'SCN-9', quantity: 1);
    });

    it('never fails the add when analytics breaks', function () {
        $this->mock(TrafficRecorder::class)->shouldReceive('recordAddToCart')->andThrow(new RuntimeException('boom'));
        $product = Product::factory()->create();

        Livewire::test(AddButton::class, ['productId' => $product->id])->call('add')->assertHasNoErrors()->assertSet('added', true);
    });
});
