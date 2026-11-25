<?php

use App\Actions\Orders\SaveOrderItems;
use App\Data\Orders\OrderLineData;
use App\Enums\OrderSource;
use App\Enums\PriceLevel;
use App\Livewire\Admin\Orders\Form;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

require_once __DIR__.'/../../../Helpers/MiniApp.php';

/**
 * Plan 20 security review M2: a Mini App order is a customer checkout in admin,
 * like a site order: no free lines, posted prices ignored, new lines at Retail.
 */
it('treats site and Telegram orders as customer checkouts', function () {
    expect(OrderSource::Site->isCustomerCheckout())->toBeTrue()
        ->and(OrderSource::Telegram->isCustomerCheckout())->toBeTrue()
        ->and(OrderSource::Manual->isCustomerCheckout())->toBeFalse()
        ->and(OrderSource::Quote->isCustomerCheckout())->toBeFalse();
});

it('gives a Telegram order no free line, ignores posted prices and adds new lines at Retail', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = staffUser();
    $order = Order::factory()->telegram(tmaUser())->totalling(500_000_00)->create(['price_level' => PriceLevel::Dealer]);
    $item = OrderItem::factory()->for($order)->create(['price' => 500_000_00, 'qty' => 1, 'total' => 500_000_00]);
    $product = Product::factory()->create(['price' => 300_000_00, 'price_dealer' => 200_000_00]);

    $this->actingAs($admin);
    Livewire::test(Form::class, ['order' => $order->fresh()])
        ->assertViewHas('canFreeLine', false)
        ->call('addLine')
        ->assertCount('form.items', 1);

    app(SaveOrderItems::class)->handle($order->fresh(), [
        new OrderLineData(qty: 1, price: 1_00, itemId: $item->id),
        new OrderLineData(productId: $product->id, qty: 1, price: 1_00),
    ], $admin);

    expect($item->fresh()->price)->toBe(500_000_00)
        ->and(OrderItem::query()->where('order_id', $order->id)->where('product_id', $product->id)->sole()->price)->toBe(300_000_00);
});
