<?php

use App\Actions\Customers\DeleteCustomer;
use App\Actions\Orders\ChangeOrderPaymentMethod;
use App\Actions\Orders\ChangeOrderStatus;
use App\Actions\Orders\CreateManualOrder;
use App\Actions\Orders\DeleteTestOrder;
use App\Actions\Orders\ReleaseStalePaymentHolds;
use App\Actions\Orders\UpdateOrderLines;
use App\Data\Orders\ManualOrderData;
use App\Data\Orders\OrderDeliveryData;
use App\Data\Orders\OrderDetailsData;
use App\Data\Orders\OrderLineData;
use App\Enums\DeliveryType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PriceLevel;
use App\Enums\Role;
use App\Models\Activity;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\User;
use App\Policies\OrderPolicy;
use App\Support\Orders\Contracts\PaymentHold;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->sales = staffUser(Role::SalesManager);
    $this->admin = User::factory()->withRole(Role::Admin)->create();
});

function manualOrder(User $actor, array $lines, array $overrides = []): Order
{
    return app(CreateManualOrder::class)->handle(new ManualOrderData(
        details: new OrderDetailsData('Aziz', '+998901234567', 'individual'),
        delivery: new OrderDeliveryData('pickup'),
        paymentMethod: $overrides['method'] ?? 'on_receipt',
        lines: $lines,
        customerId: $overrides['customer'] ?? null,
        isTest: $overrides['test'] ?? false,
    ), $actor);
}

it('follows the status machine and its payment guards', function () {
    $order = Order::factory()->uzqr()->totalling(100_00)->create();
    $change = app(ChangeOrderStatus::class);

    expect(fn () => $change->handle($order, OrderStatus::Shipped, $this->sales))->toThrow(ValidationException::class);

    $change->handle($order, OrderStatus::Confirmed, $this->sales);
    expect(fn () => $change->handle($order, OrderStatus::Shipped, $this->sales))->toThrow(ValidationException::class, __('orders.errors.ship_unpaid'));

    $order->forceFill(['payment_status' => PaymentStatus::Paid])->save();
    expect(fn () => $change->handle($order, OrderStatus::Cancelled, $this->sales))->toThrow(ValidationException::class, __('orders.errors.cancel_paid'));

    $change->handle($order, OrderStatus::Shipped, $this->sales);
    $change->handle($order, OrderStatus::Completed, $this->sales);

    expect($order->fresh()->status)->toBe(OrderStatus::Completed)
        ->and($order->fresh()->completed_at)->not->toBeNull()
        ->and($order->activities()->where('meta->action', 'status')->count())->toBe(3);
});

it('ships an unpaid on-receipt order, but a Kokand courier only after the address check', function () {
    $order = Order::factory()->status(OrderStatus::Confirmed)->create(['delivery_type' => DeliveryType::CourierKokand, 'address' => 'Turkiston 5']);

    expect(fn () => app(ChangeOrderStatus::class)->handle($order, OrderStatus::Shipped, $this->sales))->toThrow(ValidationException::class, __('orders.errors.kokand_unconfirmed'));

    $order->forceFill(['kokand_address_confirmed' => true])->save();
    app(ChangeOrderStatus::class)->handle($order, OrderStatus::Shipped, $this->sales);
    expect($order->fresh()->status)->toBe(OrderStatus::Shipped);
});

it('does not complete with a pending delivery charge', function () {
    $order = Order::factory()->separateDelivery()->paid()->status(OrderStatus::Shipped)->create();
    OrderPayment::factory()->deliveryCharge()->for($order)->create();

    expect(fn () => app(ChangeOrderStatus::class)->handle($order, OrderStatus::Completed, $this->sales))->toThrow(ValidationException::class, __('orders.errors.complete_pending_charge'));
});

it('locks lines and the payment method once paid', function () {
    $order = Order::factory()->paid()->create();
    $item = OrderItem::factory()->for($order)->create();

    expect(fn () => app(UpdateOrderLines::class)->handle($order, [new OrderLineData(itemId: $item->id, qty: 3)], $this->sales))->toThrow(ValidationException::class, __('orders.errors.lines_locked'))
        ->and(fn () => app(ChangeOrderPaymentMethod::class)->handle($order, 'uzqr_static', $this->sales))->toThrow(ValidationException::class);
});

it('prices manual orders at the customer level and keeps staff prices above the floor', function () {
    $product = Product::factory()->withIkpu()->create(['price' => 1_000_000_00, 'price_dealer' => 900_000_00]);
    $dealer = Customer::factory()->create(['price_level' => PriceLevel::Dealer]);

    $order = manualOrder($this->sales, [new OrderLineData(productId: $product->id, qty: 2)], ['customer' => $dealer->id]);
    expect($order->source)->toBe(OrderSource::Manual)->and($order->total)->toBe(1_800_000_00)->and($order->manager_id)->toBe($this->sales->id);

    $line = $order->items->first();

    // Sales may not go below the level price (floor 0%), and may not add custom lines.
    expect(fn () => app(UpdateOrderLines::class)->handle($order, [new OrderLineData(itemId: $line->id, qty: 2, price: 850_000_00)], $this->sales))
        ->toThrow(ValidationException::class, __('orders.errors.price_below_floor'))
        ->and(fn () => app(UpdateOrderLines::class)->handle($order, [new OrderLineData(name: 'Setup', qty: 1, price: 100_00)], $this->sales))
        ->toThrow(ValidationException::class, __('orders.errors.free_line_admin'));

    // Above the reference is fine and logged.
    app(UpdateOrderLines::class)->handle($order, [new OrderLineData(itemId: $line->id, qty: 2, price: 950_000_00)], $this->sales);
    expect($line->fresh()->price_edited)->toBeTrue()->and($line->fresh()->reference_price)->toBe(900_000_00);

    // The Admin may go below; old / new / reference are logged and the invoice reissued.
    app(UpdateOrderLines::class)->handle($order->fresh(), [new OrderLineData(itemId: $line->id, qty: 2, price: 800_000_00)], $this->admin);

    $edit = Activity::query()->where('meta->action', 'price_edited')->latest('id')->first();
    expect($edit->meta)->toMatchArray(['old' => 950_000_00, 'new' => 800_000_00, 'reference' => 900_000_00])
        ->and($line->fresh()->isBelowReference())->toBeTrue()
        ->and($order->fresh()->invoice_version)->toBe(3)
        ->and($order->fresh()->amount_updated_at)->not->toBeNull();
});

it('lets a sales manager go down to the configured floor', function () {
    checkoutSettings(['max_manual_discount_percent' => 10]);
    $product = Product::factory()->withIkpu()->create(['price' => 1_000_000_00]);

    $order = manualOrder($this->sales, [new OrderLineData(productId: $product->id, qty: 1, price: 900_000_00)]);
    expect($order->total)->toBe(900_000_00);

    expect(fn () => manualOrder($this->sales, [new OrderLineData(productId: $product->id, qty: 1, price: 899_999_00)]))->toThrow(ValidationException::class);
});

it('re-prices site order lines from the catalog and ignores typed prices', function () {
    $product = Product::factory()->withIkpu()->create(['price' => 500_000_00]);
    $other = Product::factory()->withIkpu()->create(['price' => 70_000_00]);
    $order = Order::factory()->totalling(500_000_00)->create();
    $line = OrderItem::factory()->for($order)->forProduct($product)->create();

    app(UpdateOrderLines::class)->handle($order, [
        new OrderLineData(itemId: $line->id, qty: 2, price: 1_00),
        new OrderLineData(productId: $other->id, qty: 1, price: 1_00),
    ], $this->admin);

    expect($order->fresh()->total)->toBe(1_070_000_00)
        ->and($order->items()->where('product_id', $other->id)->value('price'))->toBe(70_000_00);
});

it('changes the payment method only to one the matrix allows', function () {
    $order = Order::factory()->totalling(100_00)->create();

    expect(fn () => app(ChangeOrderPaymentMethod::class)->handle($order, 'bank_transfer', $this->sales))->toThrow(ValidationException::class);

    uzqrUploaded();
    app(ChangeOrderPaymentMethod::class)->handle($order, 'uzqr_static', $this->sales);

    expect($order->fresh()->payment_method)->toBe(PaymentMethod::UzqrStatic);
});

it('creates TEST orders for Admins only, numbered T-, without a public link', function () {
    $product = Product::factory()->withIkpu()->create();

    expect(fn () => manualOrder($this->sales, [new OrderLineData(productId: $product->id)], ['test' => true]))->toThrow(ValidationException::class);

    $test = manualOrder($this->admin, [new OrderLineData(productId: $product->id)], ['test' => true]);

    expect($test->number)->toStartWith('T-')->and($test->is_test)->toBeTrue()->and($test->isPublicLinkValid())->toBeFalse()
        ->and(Order::query()->real()->count())->toBe(0)
        ->and(Order::query()->visibleTo($this->sales)->count())->toBe(0)
        ->and(Order::query()->visibleTo($this->admin)->count())->toBe(1);
});

it('deletes TEST orders with their test payments, never real orders', function () {
    $test = Order::factory()->test()->paid()->create();
    OrderPayment::factory()->for($test)->create(['is_test' => true]);
    OrderItem::factory()->for($test)->create();
    $real = Order::factory()->create();

    app(DeleteTestOrder::class)->handle($test);

    expect(Order::query()->whereKey($test->id)->exists())->toBeFalse()
        ->and(OrderPayment::query()->count())->toBe(0)
        ->and(fn () => app(DeleteTestOrder::class)->handle($real))->toThrow(ValidationException::class, __('orders.errors.delete_real'))
        ->and((new OrderPolicy)->delete($this->admin, $real))->toBeFalse();

    $mixed = Order::factory()->test()->create();
    OrderPayment::factory()->for($mixed)->create(['is_test' => false]);
    expect(fn () => app(DeleteTestOrder::class)->handle($mixed))->toThrow(ValidationException::class, __('orders.errors.delete_real_payment'));
});

it('releases orders stuck in pending without an active hold', function () {
    $pending = Order::factory()->create(['payment_status' => PaymentStatus::Pending, 'payment_pending_at' => now()->subDay()]);

    expect(app(ReleaseStalePaymentHolds::class)->handle())->toBe(1)
        ->and($pending->fresh()->payment_status)->toBe(PaymentStatus::Unpaid);

    $this->artisan('orders:release-holds')->assertSuccessful();
});

it('blocks deleting a customer that has orders', function () {
    $customer = Customer::factory()->create();
    Order::factory()->create(['customer_id' => $customer->id]);

    expect(fn () => app(DeleteCustomer::class)->handle($customer))->toThrow(ValidationException::class);
});

it('treats an order held by a registered payment hold as locked', function () {
    $hold = new class implements PaymentHold
    {
        public function holds(Order $order): bool
        {
            return true;
        }
    };
    app()->instance('test.hold', $hold);
    config(['checkout.payment_holds' => ['payme' => 'test.hold']]);

    $order = Order::factory()->create(['payment_status' => PaymentStatus::Pending]);

    expect($order->hasActiveTransaction())->toBeTrue()
        ->and($order->isLinesEditable())->toBeFalse()
        ->and(app(ReleaseStalePaymentHolds::class)->handle())->toBe(0)
        ->and(fn () => app(ChangeOrderStatus::class)->handle($order, OrderStatus::Cancelled, $this->sales))->toThrow(ValidationException::class);
});
