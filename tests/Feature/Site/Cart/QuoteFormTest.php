<?php

use App\Enums\AnalyticsGoal;
use App\Enums\LeadType;
use App\Enums\SaleMode;
use App\Livewire\Site\Cart\QuoteForm;
use App\Livewire\Site\LeadForm;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Lead;
use App\Models\LeadItem;
use App\Models\Product;
use App\Models\RollType;
use App\Models\Setting;
use App\Support\Cart\CartToken;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

require_once __DIR__.'/CartHelpers.php';

/**
 * "Request a quote for this cart" (Site\Cart\QuoteForm). The "quote for the cart" tests moved
 * here unchanged from CartPageTest (plan 42 T2, a pure refactor); plan 42 QM-3, QM-7, QM-8 below.
 */
beforeEach(function () {
    config(['site.cart_enabled' => true]);
    app()->setLocale('en');
    URL::defaults(['locale' => 'en']);
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

    it('applies the lead form checks: honeypot, fill time, phone', function (Closure $submit, bool $sent) {
        $cart = visitorCart();
        CartItem::factory()->for($cart)->create();

        $component = $submit();

        expect(Lead::query()->count())->toBe(0);
        $component->assertSet('sent', $sent);
    })->with([
        'honeypot' => [fn () => filledCartQuote(['fax_ext' => 'spam'])->call('requestQuote'), true],
        'too fast' => [fn () => Livewire::test(QuoteForm::class)->set('name', 'Aziz')->set('phone', '90 123 45 67')->call('requestQuote'), true],
        'phone without country code' => [fn () => filledCartQuote(['phone' => '8 912 3456789'])->call('requestQuote')->assertHasErrors('phone'), false],
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

describe('quote form component (plan 42)', function () {
    it('keeps no cart id, token, prices or totals in public properties', function () {
        $cart = visitorCart();
        $product = Product::factory()->create(['price' => 777_700]);
        CartItem::factory()->for($cart)->for($product)->create(['qty' => 3]);

        $component = Livewire::test(QuoteForm::class);
        $state = json_encode($component->instance()->all());

        expect(array_keys($component->instance()->all()))->toEqualCanonicalizing(['name', 'phone', 'company', 'message', 'fax_ext', 'sent', 'locale', 'pageUrl', 'attribution', 'renderedAt', 'idPrefix', 'inModal'])
            ->and($state)->not->toContain($cart->token)
            ->not->toContain('777700')
            ->not->toContain('"cart":')
            ->not->toContain('cart_id');
    });

    it('renders on the cart page with the cart-quote ids and the modal trigger on the quote link', function () {
        $cart = Cart::factory()->create();
        CartItem::factory()->for($cart)->create();

        $html = $this->withCookie(CartToken::COOKIE, $cart->token)->get('/en/cart')->assertOk()->getContent();

        expect($html)->toContain('id="cart-quote"')
            ->toContain('id="cart-quote-name"')
            ->toContain('id="cart-quote-fax-ext"')
            ->toContain('data-cart-quote-form')
            ->toMatch('/<a href="#cart-quote" data-lead-modal="cart"[^>]*data-quote-link>/');
    });

    it('uses the id prefix and the modal success Close button', function () {
        $cart = visitorCart();
        CartItem::factory()->for($cart)->create();

        $component = Livewire::test(QuoteForm::class, ['idPrefix' => 'lead-modal-cart', 'inModal' => true]);
        expect($component->html())->toContain('id="lead-modal-cart-phone"')->not->toContain('id="cart-quote-phone"')
            ->not->toContain('shadow-card');

        foreach (['name' => 'Aziz', 'phone' => '90 123 45 67'] as $field => $value) {
            $component->set($field, $value);
        }
        $this->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();

        $html = $component->call('requestQuote')->assertSet('sent', true)->html();

        expect($html)->toContain('data-lead-modal-done')
            ->toContain("\$dispatch('lead-modal-close')")
            ->not->toMatch('/L-\d+/');
    });

    it('QM-3: a second submit after success creates no second lead, goal or limiter hit', function () {
        Setting::factory()->setting('tracking', ['enabled' => true, 'ga4' => '', 'metrika' => '98765432'])->create();
        config(['site.analytics_force' => true]);
        $cart = visitorCart();
        CartItem::factory()->for($cart)->create();

        $goals = fn (Testable $component): int => collect(data_get($component->effects, 'dispatches', []))->where('name', 'poson-goal')->count();
        $component = filledCartQuote()->call('requestQuote')->assertSet('sent', true);
        $first = $goals($component);
        $component->set('name', 'Aziz')->set('phone', '90 123 45 67')->call('requestQuote');

        expect(Lead::query()->count())->toBe(1)
            ->and(RateLimiter::attempts('site-lead:'.sha1('127.0.0.1')))->toBe(1)
            ->and($first)->toBe(1)
            ->and($goals($component))->toBe(0);
    });

    it('QM-7: quotes only the cart of this visitor\'s cookie, never another one', function () {
        $theirs = Cart::factory()->create();
        CartItem::factory()->for($theirs)->create();

        // No cart cookie at all.
        filledCartQuote()->call('requestQuote')->assertHasErrors('form')->assertSet('sent', false)
            ->assertSee(__('checkout.cart.errors.nothing_to_quote'));

        // A cookie for an empty cart of their own.
        visitorCart();
        filledCartQuote()->call('requestQuote')->assertHasErrors('form')->assertSet('sent', false);

        expect(Lead::query()->count())->toBe(0);
    });

    it('QM-7: takes no cart id or token as input', function (string $property) {
        Livewire::test(QuoteForm::class)->set($property, 'x');
    })->with(['cartId', 'token', 'cart'])->throws(Exception::class);

    it('rejects tampering with locked properties', function (string $property, mixed $value) {
        Livewire::test(QuoteForm::class)->set($property, $value);
    })->with([
        'locale' => ['locale', 'de'],
        'pageUrl' => ['pageUrl', 'https://evil.example'],
        'attribution' => ['attribution', ['channel' => 'ai']],
        'renderedAt' => ['renderedAt', 0],
        'idPrefix' => ['idPrefix', 'x'],
        'inModal' => ['inModal', true],
    ])->throws(CannotUpdateLockedPropertyException::class);

    it('QM-8: is a 404 while the cart is off, on mount and on submit', function () {
        $cart = visitorCart();
        CartItem::factory()->for($cart)->create();
        $component = filledCartQuote();
        config(['site.cart_enabled' => false]);

        $component->call('requestQuote')->assertStatus(404);
        expect(Lead::query()->count())->toBe(0);

        Livewire::test(QuoteForm::class)->assertStatus(404);
    });

    it('sends lead_cart_quote once on success', function () {
        Setting::factory()->setting('tracking', ['enabled' => true, 'ga4' => '', 'metrika' => '98765432'])->create();
        config(['site.analytics_force' => true]);
        $cart = visitorCart();
        CartItem::factory()->for($cart)->create();

        $component = filledCartQuote()->call('requestQuote');

        expect(collect(data_get($component->effects, 'dispatches', []))->where('name', 'poson-goal')->pluck('params')->all())
            ->toBe([['goal' => AnalyticsGoal::LeadCartQuote->value]]);
    });
});
