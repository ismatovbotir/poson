<?php

use App\Actions\Orders\PlaceOrder;
use App\Enums\DeliveryPricing;
use App\Enums\OrderSource;
use App\Enums\PaymentMethod;
use App\Enums\PriceLevel;
use App\Enums\ProductUnit;
use App\Enums\StockStatus;
use App\Events\Orders\OrderPlaced;
use App\Jobs\SendTelegramMessage;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\RollType;
use App\Support\Vat;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

function placeOrder(Cart $cart, array $overrides = []): Order
{
    return app(PlaceOrder::class)->handle($cart->token, checkoutData($cart, $overrides));
}

it('places a Retail order from the cart with snapshots, VAT and a public link', function () {
    $product = Product::factory()->withIkpu()->create(['price' => 1_200_000_00, 'price_dealer' => 900_000_00, 'stock_status' => StockStatus::OnOrder]);
    $cart = orderCart($product, qty: 2);
    Event::fake([OrderPlaced::class]);

    $order = placeOrder($cart);

    expect($order->number)->toMatch('/^O-\d{4}-0001$/')
        ->and($order->source)->toBe(OrderSource::Site)
        ->and($order->price_level)->toBe(PriceLevel::Retail)
        ->and($order->total)->toBe(2_400_000_00)
        ->and($order->vat_amount)->toBe(Vat::of(2_400_000_00))
        ->and($order->payment_method)->toBe(PaymentMethod::OnReceipt)
        ->and($order->isPublicLinkValid())->toBeTrue()
        ->and($order->items)->toHaveCount(1)
        ->and($order->items[0]->price)->toBe(1_200_000_00)
        ->and($order->items[0]->ikpu_code)->toBe('08471001001000000')
        ->and($order->items[0]->was_on_order)->toBeTrue()
        ->and($cart->fresh()->converted_order_id)->toBe($order->id);

    Event::assertDispatched(OrderPlaced::class);
});

it('is idempotent: a second submit returns the same order', function () {
    $cart = orderCart();
    $data = checkoutData($cart);

    $first = app(PlaceOrder::class)->handle($cart->token, $data);
    $second = app(PlaceOrder::class)->handle($cart->token, $data);

    expect($second->ulid)->toBe($first->ulid)->and(Order::query()->count())->toBe(1);
});

it('refuses when prices changed since the page was shown', function () {
    $product = Product::factory()->withIkpu()->create(['price' => 100_000_00]);
    $cart = orderCart($product);
    $data = checkoutData($cart);
    $product->update(['price' => 120_000_00]);

    expect(fn () => app(PlaceOrder::class)->handle($cart->token, $data))->toThrow(ValidationException::class);
    expect(Order::query()->count())->toBe(0);

    try {
        app(PlaceOrder::class)->handle($cart->token, $data);
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('fingerprint');
    }
});

it('refuses carts with unavailable lines and totals above the cap', function () {
    $product = Product::factory()->withIkpu()->create(['price' => 600_000_000_00]);
    $cart = orderCart($product, qty: 2);
    checkoutSettings(['max_total' => 1_000_000_000]);

    expect(fn () => placeOrder($cart))->toThrow(ValidationException::class, __('checkout.errors.total_too_high'));

    $product->update(['stock_status' => StockStatus::OutOfStock]);
    expect(fn () => placeOrder($cart))->toThrow(ValidationException::class, __('checkout.errors.invalid_lines'));
});

it('enforces the payment matrix in the action', function (array $input) {
    expect(fn () => placeOrder(orderCart(), $input))->toThrow(ValidationException::class);
})->with([
    'on receipt to a region' => [['deliveryType' => 'courier_region', 'region' => 'samarkand', 'address' => 'Samarqand', 'paymentMethod' => 'on_receipt']],
    'bank transfer for a person' => [['paymentMethod' => 'bank_transfer']],
    'payme without webhooks' => [['paymentMethod' => 'payme']],
    'reserved dynamic UzQR' => [['paymentMethod' => 'uzqr_dynamic']],
    'unknown method' => [['paymentMethod' => 'bitcoin']],
    'static UzQR without a code' => [['paymentMethod' => 'uzqr_static']],
]);

it('validates the buyer: phone, STIR / PINFL, company name, Kokand address', function (array $input, string $field) {
    try {
        placeOrder(orderCart(), $input);
        $this->fail('Expected a validation error');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([
    'bad phone' => [['phone' => '12345'], 'phone'],
    'company without STIR' => [['buyerType' => 'company', 'companyName' => 'Market'], 'tin'],
    'company with YaTT STIR' => [['buyerType' => 'company', 'companyName' => 'Market', 'tin' => '401234567'], 'tin'],
    'YaTT bad PINFL' => [['buyerType' => 'sole_proprietor', 'companyName' => 'Aliyev', 'tin' => '71234567890123'], 'tin'],
    'company without name' => [['buyerType' => 'company', 'tin' => '301234567'], 'companyName'],
    'Kokand unconfirmed' => [['deliveryType' => 'courier_kokand', 'address' => 'Qoʻqon, Turkiston 5'], 'kokandConfirmed'],
    'unknown buyer type' => [['buyerType' => 'alien'], 'buyerType'],
]);

it('accepts a sole proprietor with a PINFL paying by bank transfer', function () {
    sellerRequisites();

    $order = placeOrder(orderCart(), ['buyerType' => 'sole_proprietor', 'companyName' => 'YaTT Aliyev', 'tin' => '31234567890123', 'paymentMethod' => 'bank_transfer']);

    expect($order->tin)->toBe('31234567890123')->and($order->payment_method)->toBe(PaymentMethod::BankTransfer);
});

it('links a customer only when STIR and phone match the same customer, prices stay Retail', function () {
    $customer = Customer::factory()->create(['inn' => '301234567', 'phone' => '+998901112233', 'price_level' => PriceLevel::Dealer]);
    $other = Customer::factory()->create(['inn' => '302222222', 'phone' => '+998905556677']);
    Contact::factory()->for($other)->create(['phone' => '+998907778899']);
    $company = ['buyerType' => 'company', 'companyName' => 'Mega', 'paymentMethod' => 'on_receipt'];

    $matched = placeOrder(orderCart(), [...$company, 'tin' => '301234567', 'phone' => '90 111 22 33']);
    $viaContact = placeOrder(orderCart(), [...$company, 'tin' => '302222222', 'phone' => '90 777 88 99']);
    $phoneOnly = placeOrder(orderCart(), [...$company, 'tin' => '303333333', 'phone' => '90 111 22 33']);
    $stirOnly = placeOrder(orderCart(), [...$company, 'tin' => '301234567', 'phone' => '90 999 99 99']);

    expect($matched->customer_id)->toBe($customer->id)
        ->and($matched->price_level)->toBe(PriceLevel::Retail)
        ->and($viaContact->customer_id)->toBe($other->id)
        ->and($phoneOnly->customer_id)->toBeNull()
        ->and($stirOnly->customer_id)->toBeNull();
});

it('adds a region fee to the total, or bills delivery separately (goods only)', function () {
    uzqrUploaded();
    checkoutSettings(['region_fees' => ['samarkand' => 150_000]]);
    $product = Product::factory()->withIkpu()->create(['price' => 1_000_000_00]);

    $fixed = placeOrder(orderCart($product), ['deliveryType' => 'courier_region', 'region' => 'samarkand', 'address' => 'Registon 1', 'paymentMethod' => 'uzqr_static']);
    $separate = placeOrder(orderCart($product), ['deliveryType' => 'courier_region', 'region' => 'khorezm', 'address' => 'Urganch', 'paymentMethod' => 'uzqr_static']);

    expect($fixed->delivery_pricing)->toBe(DeliveryPricing::Fixed)
        ->and($fixed->total)->toBe(1_150_000_00)
        ->and($separate->delivery_pricing)->toBe(DeliveryPricing::Separate)
        ->and($separate->delivery_amount)->toBe(0)
        ->and($separate->total)->toBe(1_000_000_00);
});

it('snapshots a label roll line at Retail per roll', function () {
    $rollType = RollType::factory()->create(['labels_per_roll' => 500, 'core_diameter_mm' => 40]);
    $product = Product::factory()->consumable()->perLabel(12)->withRollTypes([$rollType])->withIkpu()->create();
    $cart = orderCart([]);
    $cart->items()->delete();
    CartItem::factory()->for($cart)->roll($rollType)->create(['product_id' => $product->id, 'qty' => 3]);

    $order = placeOrder($cart);
    $line = $order->items->first();

    expect($line->unit)->toBe(ProductUnit::Roll)
        ->and($line->labels_per_roll)->toBe(500)
        ->and($line->price_per_label)->toBe(12_00)
        ->and($line->price)->toBe(6_000_00)
        ->and($line->qty)->toBe(3)
        ->and($order->total)->toBe(18_000_00);
});

it('alerts the sales chat without tax id, address or comment', function () {
    config(['app.url' => 'https://poson.uz']);
    freshSecrets(['services.telegram.bot_token' => '123456789:'.str_repeat('A', 35), 'services.telegram.chats.sales' => '-1001']);
    Queue::fake([SendTelegramMessage::class]);

    placeOrder(orderCart(), ['buyerType' => 'company', 'companyName' => 'Mega Savdo', 'tin' => '301234567', 'deliveryType' => 'courier_kokand', 'address' => 'Turkiston 77', 'kokandConfirmed' => true, 'comment' => 'secret comment']);

    Queue::assertPushed(SendTelegramMessage::class, function (SendTelegramMessage $job): bool {
        return $job->chat === 'sales'
            && str_contains($job->html, 'Mega Savdo')
            && ! str_contains($job->html, '301234567')
            && ! str_contains($job->html, 'Turkiston')
            && ! str_contains($job->html, 'secret comment')
            && ! str_contains($job->html, 'TEST')
            && $job->buttons[0]['url'] === 'https://poson.uz/admin/orders/'.Order::query()->value('id');
    });
});
