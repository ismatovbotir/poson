<?php

use App\Actions\Orders\CreateManualOrder;
use App\Data\Orders\ManualOrderData;
use App\Data\Orders\OrderDeliveryData;
use App\Data\Orders\OrderDetailsData;
use App\Data\Orders\OrderLineData;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Jobs\SendTelegramMessage;
use App\Livewire\Admin\Orders\Form;
use App\Livewire\Admin\Orders\Index;
use App\Livewire\Admin\Orders\Show;
use App\Livewire\Admin\Payments\Index as PaymentsIndex;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('opens the order and payment screens per role', function (Role $role, int $orders, int $create, int $payments, int $settings) {
    $user = staffUser($role);
    $order = Order::factory()->create();

    $this->actingAs($user)->get(route('admin.orders.index'))->assertStatus($orders);
    $this->actingAs($user)->get(route('admin.orders.show', $order))->assertStatus($orders);
    $this->actingAs($user)->get(route('admin.orders.create'))->assertStatus($create);
    $this->actingAs($user)->get(route('admin.orders.edit', $order))->assertStatus($create);
    $this->actingAs($user)->get(route('admin.payments.index'))->assertStatus($payments);
    $this->actingAs($user)->get(route('admin.settings.checkout'))->assertStatus($settings);
})->with([
    'admin' => [Role::Admin, 200, 200, 200, 200],
    'sales manager' => [Role::SalesManager, 200, 200, 200, 403],
    'service engineer' => [Role::ServiceEngineer, 200, 403, 403, 403],
    'content manager' => [Role::ContentManager, 403, 403, 403, 403],
]);

it('shows each role exactly its payment UI', function () {
    $order = Order::factory()->bankTransfer()->paid()->status(OrderStatus::Confirmed)->totalling(100_00)->create();
    OrderPayment::factory()->for($order)->create(['amount' => 100_00, 'reference' => 'BANKREF-55']);
    OrderItem::factory()->for($order)->create();

    $service = $this->actingAs(staffUser(Role::ServiceEngineer))->get(route('admin.orders.show', $order))->assertOk();
    $service->assertDontSeeHtml('data-payments-panel')->assertDontSee('BANKREF-55')->assertDontSeeHtml('data-action="invoice"')->assertDontSeeHtml('data-public-url');

    $sales = $this->actingAs(User::factory()->withRole(Role::SalesManager)->create())->get(route('admin.orders.show', $order))->assertOk();
    $sales->assertSeeHtml('data-payments-panel')->assertSee('BANKREF-55')->assertDontSeeHtml('data-action="void"')->assertDontSeeHtml('data-action="refund"');

    $admin = $this->actingAs(User::factory()->withRole(Role::Admin)->create())->get(route('admin.orders.show', $order))->assertOk();
    $admin->assertSeeHtml('data-action="void"')->assertSeeHtml('data-action="refund"');
});

it('lists real orders only; TEST orders behind the Admin switch', function () {
    Order::factory()->create(['number' => 'O-2026-0101']);
    Order::factory()->test()->create(['number' => 'T-2026-0102']);

    Livewire::actingAs(staffUser(Role::SalesManager))->test(Index::class)->assertSee('O-2026-0101')->assertDontSee('T-2026-0102')
        ->set('test', true)->assertDontSee('T-2026-0102');

    Livewire::actingAs(User::factory()->withRole(Role::Admin)->create())->test(Index::class)->set('test', true)->assertSee('T-2026-0102')->assertDontSee('O-2026-0101');
});

it('searches orders by number and phone', function () {
    Order::factory()->create(['number' => 'O-2026-0201', 'phone' => '+998901110000']);
    Order::factory()->create(['number' => 'O-2026-0202', 'phone' => '+998902220000']);

    Livewire::actingAs(staffUser(Role::SalesManager))->test(Index::class)
        ->set('search', '222')->assertSee('O-2026-0202')->assertDontSee('O-2026-0201');
});

it('marks paid from the order page and validates the amount', function () {
    $sales = staffUser(Role::SalesManager);
    $order = Order::factory()->bankTransfer()->totalling(300_000_00)->create();

    Livewire::actingAs($sales)->test(Show::class, ['order' => $order])
        ->call('startPayment')
        ->assertSet('payAmount', '300 000')
        ->set('payAmount', '299 000')->set('payReference', 'PP-1')
        ->call('markPaid')->assertHasErrors('pay.amount')
        ->set('payAmount', '300 000')
        ->call('markPaid')->assertHasNoErrors();

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Paid);
});

it('forbids voiding for a sales manager and refunding for anyone but the Admin', function () {
    $order = Order::factory()->bankTransfer()->paid()->create();
    $payment = OrderPayment::factory()->for($order)->create();

    Livewire::actingAs(staffUser(Role::SalesManager))->test(Show::class, ['order' => $order])
        ->set('reason', 'test')
        ->call('voidPayment', $payment->id)->assertForbidden();

    Livewire::actingAs(User::factory()->withRole(Role::SalesManager)->create())->test(Show::class, ['order' => $order])
        ->set('reason', 'test')
        ->call('refundPayment', $payment->id)->assertForbidden();
});

it('downloads the signed invoice only after confirmation, never for the service engineer', function () {
    sellerRequisites();
    $new = Order::factory()->bankTransfer()->totalling(100_00)->create();
    $confirmed = Order::factory()->bankTransfer()->status(OrderStatus::Confirmed)->totalling(100_00)->create();
    $admin = staffUser();

    $this->actingAs($admin)->get(route('admin.orders.invoice', [$new, 'signed' => 1]))->assertOk();
    $this->actingAs($admin)->get(route('admin.orders.invoice', [$confirmed, 'signed' => 1]))->assertOk();
    $this->actingAs(User::factory()->withRole(Role::ServiceEngineer)->create())->get(route('admin.orders.invoice', $confirmed))->assertForbidden();
});

it('creates a manual order from the form at the customer level', function () {
    $sales = staffUser(Role::SalesManager);
    $product = Product::factory()->withIkpu()->create(['price' => 500_000_00]);

    Livewire::actingAs($sales)->test(Form::class)
        ->set('form.name', 'Aziz')
        ->set('form.phone', '+998 90 123 45 67')
        ->call('addProduct', $product->id)
        ->set('form.items.0.qty', 2)
        ->call('save')
        ->assertHasNoErrors();

    $order = Order::query()->sole();
    expect($order->total)->toBe(1_000_000_00)->and($order->created_by_id)->toBe($sales->id)->and($order->is_test)->toBeFalse();
});

it('does not let a sales manager create a TEST order', function () {
    $product = Product::factory()->withIkpu()->create();

    Livewire::actingAs(staffUser(Role::SalesManager))->test(Form::class)
        ->set('form.name', 'Aziz')->set('form.phone', '+998901234567')
        ->set('form.is_test', true)
        ->call('addProduct', $product->id)
        ->call('save')
        ->assertForbidden();
});

it('filters manually confirmed payments that are not reconciled yet', function () {
    $open = OrderPayment::factory()->create(['reference' => 'OPEN-1']);
    OrderPayment::factory()->create(['reference' => 'DONE-1', 'reconciled_at' => now()]);
    $admin = staffUser();

    Livewire::actingAs($admin)->test(PaymentsIndex::class)
        ->set('unreconciled', true)->assertSee('OPEN-1')->assertDontSee('DONE-1')
        ->call('setReconciled', $open->id, true);

    expect($open->fresh()->reconciled_at)->not->toBeNull();

    Livewire::actingAs(User::factory()->withRole(Role::SalesManager)->create())->test(PaymentsIndex::class)
        ->call('setReconciled', $open->id, false)->assertForbidden();
});

it('marks TEST orders in admin and in the Telegram alert, and renders the history', function () {
    freshSecrets(['services.telegram.bot_token' => '123456789:'.str_repeat('A', 35)]);
    Queue::fake([SendTelegramMessage::class]);
    $admin = staffUser();
    $product = Product::factory()->withIkpu()->create();

    $order = app(CreateManualOrder::class)->handle(new ManualOrderData(
        details: new OrderDetailsData('Aziz', '+998901234567', 'individual'),
        delivery: new OrderDeliveryData('pickup'),
        paymentMethod: 'on_receipt',
        lines: [new OrderLineData(productId: $product->id)],
        isTest: true,
    ), $admin);

    $this->actingAs($admin)->get(route('admin.orders.show', $order))
        ->assertOk()
        ->assertSeeHtml('data-test-badge')
        ->assertSee(__('orders.timeline.created', ['total' => Money::format($order->total)]));

    Queue::assertPushed(SendTelegramMessage::class, fn ($job): bool => str_contains($job->html, '🧪 <b>TEST</b>'));
});
