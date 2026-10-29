<?php

use App\Actions\Orders\GenerateOrderInvoicePdf;
use App\Actions\Orders\RenewOrderLink;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Support\Money;
use App\Support\Site\RobotsTxt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

it('shows the order with private, noindex headers and no buyer data', function () {
    $order = Order::factory()->company('301234567', 'Mega Savdo MChJ')->totalling(2_000_000_00)->create([
        'name' => 'Secret Person', 'phone' => '+998901112233', 'address' => 'Hidden street 5', 'delivery_type' => 'courier_kokand',
    ]);
    OrderItem::factory()->for($order)->create(['name' => 'Zebra ZD421', 'qty' => 2, 'price' => 1_000_000_00, 'total' => 2_000_000_00]);

    $html = $this->get(route('orders.public', $order->ulid))
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertSee($order->number)
        ->assertSee('Zebra ZD421')
        ->assertSee(Money::format(2_000_000_00))
        ->assertSee(__('checkout.delivery.kokand_line'))
        ->getContent();

    expect($this->get(route('orders.public', $order->ulid))->headers->get('Cache-Control'))->toContain('no-store');

    foreach (['Secret Person', '901112233', 'Hidden street', '301234567', 'Mega Savdo'] as $private) {
        expect($html)->not->toContain($private);
    }

    expectNoBannedWording($html);
});

it('answers unknown, TEST, expired and renewed links with the same 404', function () {
    $test = Order::factory()->test()->create(['public_until' => now()->addDays(10)]);
    $expired = Order::factory()->expiredLink()->create();
    $renewed = Order::factory()->create();
    $oldUlid = $renewed->ulid;
    app(RenewOrderLink::class)->handle($renewed, staffUser(Role::SalesManager));

    $bodies = collect([(string) Str::ulid(), $test->ulid, $expired->ulid, $oldUlid])
        ->map(fn (string $ulid) => $this->get(route('orders.public', $ulid))->assertNotFound()->assertSee(__('orders.public.missing_title'))->getContent())
        // The body without the head (the font preload is printed once per test process).
        ->map(fn (string $html): string => (string) strstr($html, '<main'));

    expect($bodies->unique())->toHaveCount(1);
    $this->get(route('orders.public', $renewed->fresh()->ulid))->assertOk();
    $this->get(route('orders.public.invoice', $expired->ulid))->assertNotFound();
    $this->post(route('orders.public.pay', $expired->ulid))->assertNotFound();
});

it('renews a link at most 10 times a day', function () {
    $order = Order::factory()->create();
    $sales = staffUser(Role::SalesManager);

    foreach (range(1, 10) as $ignored) {
        app(RenewOrderLink::class)->handle($order, $sales);
    }

    expect(fn () => app(RenewOrderLink::class)->handle($order, $sales))->toThrow(ValidationException::class);
});

it('shows static UzQR instructions: code, recipient, exact amount and purpose, no "I have paid" button', function () {
    uzqrUploaded();
    checkoutSettings(['uzqr_recipient' => 'Poson MChJ']);
    $order = Order::factory()->uzqr()->totalling(750_000_00)->create();

    $this->get(route('orders.public', $order->ulid))
        ->assertOk()
        ->assertSee('data:image/png;base64,', false)
        ->assertSee('Poson MChJ')
        ->assertSee(Money::format(750_000_00))
        ->assertSee(__('orders.public.purpose', ['number' => $order->number]))
        ->assertSee(__('orders.public.confirm_note'))
        ->assertDontSee('/storage/', false)
        ->assertDontSeeHtml('data-pay-button');
});

it('shows bank requisites, the separate delivery notice, an outstanding charge and "amount updated"', function () {
    sellerRequisites();
    $order = Order::factory()->bankTransfer()->separateDelivery()->totalling(900_000_00)->create(['amount_updated_at' => now()]);
    OrderPayment::factory()->deliveryCharge(120_000_00)->for($order)->create();

    $this->get(route('orders.public', $order->ulid))
        ->assertOk()
        ->assertSee('20208000900123456001')
        ->assertSee(__('orders.public.delivery_separate'))
        ->assertSeeHtml('data-delivery-charge')
        ->assertSee(__('orders.public.charge_purpose', ['number' => $order->number]))
        ->assertSee(Money::format(120_000_00))
        ->assertSeeHtml('data-amount-updated');
});

it('serves the unsigned invoice publicly for bank and UzQR orders only', function () {
    sellerRequisites();
    $order = Order::factory()->bankTransfer()->status(OrderStatus::Confirmed)->totalling(100_00)->create();
    $cash = Order::factory()->create();

    $this->get(route('orders.public.invoice', $order->ulid))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get(route('orders.public.invoice', $cash->ulid))->assertNotFound();

    $html = app(GenerateOrderInvoicePdf::class)->html($order);
    expect($html)->toContain('data-stamp-place')->toContain('Mega Savdo MChJ')->toContain('301234567')->not->toContain('data-stamp ');
});

it('explains that online payment is unavailable when pay is posted', function () {
    $order = Order::factory()->totalling(100_00)->create(['payment_method' => PaymentMethod::Click]);

    $this->post(route('orders.public.pay', $order->ulid))->assertRedirect(route('orders.public', ['ulid' => $order->ulid, 'lang' => 'uz']));
    $this->get(route('orders.public', $order->ulid))->assertSeeHtml('data-pay-unavailable');
});

it('keeps order links and checkout out of robots and analytics', function () {
    expect(RobotsTxt::DISALLOW)->toContain('/o/')->toContain('/*/checkout$')
        ->and(config('analytics.exclude_paths'))->toContain('o/*');
});

it('uses ?lang for the page language', function () {
    $order = Order::factory()->create(['locale' => 'uz']);

    $this->get(route('orders.public', ['ulid' => $order->ulid, 'lang' => 'ru']))->assertSee(__('orders.public.how_to_pay', [], 'ru'));
});
