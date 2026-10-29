<?php

use App\Livewire\Site\Checkout\Page;
use App\Livewire\Site\LeadForm;
use App\Models\Order;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    config(['site.cart_enabled' => true]);
    app()->setLocale('en');
    URL::defaults(['locale' => 'en']);
});

function filledCheckout(array $fields = []): Testable
{
    $component = Livewire::test(Page::class);

    foreach (array_merge(['name' => 'Aziz Karimov', 'phone' => '90 123 45 67'], $fields) as $field => $value) {
        $component->set($field, $value);
    }

    test()->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();

    return $component;
}

it('is a 404 while the cart is off, and noindex otherwise', function () {
    config(['site.cart_enabled' => false]);
    $this->get('/en/checkout')->assertNotFound();

    config(['site.cart_enabled' => true]);
    orderCart();
    $this->get('/en/checkout')->assertOk()->assertSee('noindex,nofollow', false)->assertSee(__('checkout.place_order'));
});

it('shows the cart total, Kokand delivery as 0 soʻm and no discount wording', function () {
    orderCart(Product::factory()->withIkpu()->create(['price' => 1_250_000_00]));

    $html = Livewire::test(Page::class)
        ->set('deliveryType', 'courier_kokand')
        ->assertSee(Money::format(1_250_000_00))
        ->assertSee(__('checkout.delivery.kokand_line'))
        ->html();

    expectNoBannedWording($html);
});

it('offers only the methods allowed for the delivery and buyer', function () {
    orderCart();

    Livewire::test(Page::class)
        ->assertSeeHtml('data-method="on_receipt"')
        ->assertDontSeeHtml('data-method="payme"')
        ->assertDontSeeHtml('data-method="bank_transfer"')
        ->set('deliveryType', 'courier_region')
        ->assertDontSeeHtml('data-method="on_receipt"')
        ->assertSeeHtml('data-no-methods');
});

it('places the order and redirects to the order link', function () {
    orderCart();

    $component = filledCheckout()->call('placeOrder');

    $order = Order::query()->sole();
    $component->assertHasNoErrors()->assertRedirect(route('orders.public', ['ulid' => $order->ulid, 'lang' => 'en']));
    expect($order->locale)->toBe('en');
});

it('keeps prices, totals and the cart out of public state and locks the fingerprint', function () {
    $cart = orderCart(Product::factory()->withIkpu()->create(['price' => 4_321_000_00]));

    $component = Livewire::test(Page::class);
    $snapshot = json_encode($component->snapshot['data']);

    expect($snapshot)->not->toContain('432100000')->not->toContain($cart->token)
        ->and(fn () => $component->set('fingerprint', 'x'))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('shows the neutral notice when prices changed after the page was rendered', function () {
    $product = Product::factory()->withIkpu()->create(['price' => 100_000_00]);
    orderCart($product);

    $component = filledCheckout();
    $product->update(['price' => 110_000_00]);

    $component->call('placeOrder')->assertHasErrors('fingerprint')->assertSee(__('checkout.errors.changed'));
    expect(Order::query()->count())->toBe(0);

    $component->call('placeOrder')->assertHasNoErrors();
    expect(Order::query()->value('total'))->toBe(110_000_00);
});

it('rejects bots silently: honeypot and too-fast submits', function () {
    orderCart();

    filledCheckout(['fax_ext' => 'spam'])->call('placeOrder')->assertHasErrors('form');

    Livewire::test(Page::class)->set('name', 'Bot')->set('phone', '901234567')->call('placeOrder')->assertHasErrors('form');

    expect(Order::query()->count())->toBe(0);
});

it('limits checkout attempts per IP', function () {
    config(['checkout.limits.per_ip' => 1]);
    orderCart();
    filledCheckout()->call('placeOrder')->assertHasNoErrors();

    app()->forgetScopedInstances();
    orderCart();
    filledCheckout()->call('placeOrder')->assertHasErrors('form');

    expect(Order::query()->count())->toBe(1);
});

it('links the cart page to checkout only while every line can be bought', function () {
    $product = Product::factory()->withIkpu()->create();
    orderCart($product);

    Livewire::test(App\Livewire\Site\Cart\Page::class)->assertSeeHtml('data-checkout-link');

    $product->update(['is_active' => false]);
    Livewire::test(App\Livewire\Site\Cart\Page::class)->assertSeeHtml('data-checkout-disabled');
});
