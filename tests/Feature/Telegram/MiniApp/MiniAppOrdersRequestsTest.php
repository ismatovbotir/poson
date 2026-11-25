<?php

use App\Enums\LeadSource;
use App\Enums\LeadType;
use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Lead;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Support\Leads\LeadLimits;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/../../../Helpers/MiniApp.php';

/**
 * Plan 20 M4: My orders (MA-10, MA-17, MA-22) and one-tap quote / service requests (MA-9, MA-12, MA-19).
 */
beforeEach(function () {
    $this->withoutVite();
    tmaEnabled();
    $this->tgUser = tmaUser(['name' => 'Aziz Karimov', 'declared_company' => 'Mega Savdo MChJ']);
    tmaSignIn($this->tgUser);
});

it('lists only the user own real Mini App orders', function () {
    $own = Order::factory()->telegram($this->tgUser)->create();
    $other = Order::factory()->telegram(tmaUser(['phone' => '+998907654321']))->create();
    $site = Order::factory()->forPhone($this->tgUser->phone)->create();
    $test = Order::factory()->test()->create(['telegram_user_id' => $this->tgUser->id]);

    $html = $this->get('/tma/uz/orders')->assertOk()->getContent();

    expect($html)->toContain('data-order="'.$own->id.'"')
        ->not->toContain('data-order="'.$other->id.'"')
        ->not->toContain('data-order="'.$site->id.'"')
        ->not->toContain('data-order="'.$test->id.'"');
});

it('answers every foreign order id with the same 404 (MA-10)', function (Closure $make) {
    $order = $make($this->tgUser);

    $this->get('/tma/uz/orders/'.$order->id)->assertNotFound();
})->with([
    'another user' => [fn () => Order::factory()->telegram(tmaUser(['phone' => '+998907654321']))->create()],
    'site order' => [fn () => Order::factory()->create()],
    'test order' => [fn ($user) => Order::factory()->test()->create(['telegram_user_id' => $user->id])],
]);

it('routes orders by numeric id only', function () {
    $order = Order::factory()->telegram($this->tgUser)->create();

    $this->get('/tma/uz/orders/'.$order->ulid)->assertNotFound();
});

it('shows bank details and the invoice only once a bank transfer is confirmed; the ULID only in the invoice link (MA-17, MA-22)', function () {
    sellerRequisites();
    $order = Order::factory()->telegram($this->tgUser)->bankTransfer()->totalling(1_000_000_00)->create();
    OrderItem::factory()->for($order)->create();

    $new = $this->get('/tma/uz/orders/'.$order->id)->assertOk()->getContent();
    expect($new)->not->toContain('data-bank-block')->not->toContain('data-invoice')->toContain('data-bank-pending')
        ->and($new)->not->toContain($order->ulid);

    $order->update(['status' => OrderStatus::Confirmed]);
    $confirmed = $this->get('/tma/uz/orders/'.$order->id)->assertOk()->getContent();
    expect($confirmed)->toContain('data-bank-block')
        ->toContain('data-open-link="https://poson.example.uz/o/'.$order->ulid.'/invoice.pdf?lang=uz"')
        ->and(substr_count($confirmed, $order->ulid))->toBe(2)
        ->and($confirmed)->not->toContain('uzqr-payment')
        ->not->toContain('livewire');

    $order->update(['public_until' => now()->subDay()]);
    expect($this->get('/tma/uz/orders/'.$order->id)->getContent())->toContain('data-bank-block')->not->toContain('data-invoice');

    $order->update(['status' => OrderStatus::Cancelled, 'public_until' => now()->addDay()]);
    expect($this->get('/tma/uz/orders/'.$order->id)->getContent())->not->toContain('data-bank-block')->not->toContain($order->ulid);
});

it('sends a one-tap quote with the registration name and phone, type from the product (MA-9, MA-19)', function () {
    $product = Product::factory()->create();

    $this->post('/tma/uz/p/'.$product->slug.'/quote', [
        'comment' => 'Ikki dona kerak', 'name' => 'Hacker', 'phone' => '+998911111111', 'type' => 'service_request',
    ])->assertSessionHas('tma_status', 'requested');

    $lead = Lead::query()->sole();
    expect($lead->source)->toBe(LeadSource::Telegram)
        ->and($lead->type)->toBe(LeadType::Quote)
        ->and($lead->name)->toBe('Aziz Karimov')
        ->and($lead->phone)->toBe($this->tgUser->phone)
        ->and($lead->message)->toBe('Ikki dona kerak')
        ->and($lead->items()->sole()->product_id)->toBe($product->id);
});

it('makes a service product request a service_request', function () {
    $product = Product::factory()->service()->create();

    $this->post('/tma/uz/p/'.$product->slug.'/quote')->assertSessionHas('tma_status', 'requested');

    expect(Lead::query()->sole()->type)->toBe(LeadType::ServiceRequest);
});

it('refuses a long comment, a hidden product and an unusable phone', function () {
    $product = Product::factory()->create();

    $this->post('/tma/uz/p/'.$product->slug.'/quote', ['comment' => str_repeat('a', 501)])->assertSessionHasErrors('request');
    $this->post('/tma/uz/p/'.Product::factory()->inactive()->create()->slug.'/quote')->assertNotFound();

    $this->tgUser->forceFill(['phone' => '+88800001234567'])->save();
    $this->post('/tma/uz/p/'.$product->slug.'/quote')->assertRedirect(route('tma.unavailable', ['locale' => 'uz', 'reason' => 'phone']));

    expect(Lead::query()->count())->toBe(0);
});

it('shares the per-phone lead budget with the site forms (MA-12)', function () {
    $product = Product::factory()->create();

    foreach (range(1, LeadLimits::PHONE_LIMIT) as $hit) {
        RateLimiter::hit(LeadLimits::phoneKey($this->tgUser->phone), LeadLimits::PHONE_SECONDS);
    }

    $this->post('/tma/uz/p/'.$product->slug.'/quote')->assertSessionHasErrors('request');
    expect(Lead::query()->count())->toBe(0);
});

it('limits one-tap requests per user (MA-12)', function () {
    foreach (range(1, 5) as $hit) {
        RateLimiter::hit('tma-lead:'.$this->tgUser->id, 600);
    }

    $this->post('/tma/uz/p/'.Product::factory()->create()->slug.'/quote')->assertSessionHasErrors('request');
});

function tmaServiceable(): Product
{
    return Product::factory()->inCategory(Category::query()->firstWhere('slug', 'scanners') ?? Category::factory()->create(['slug' => 'scanners']))->create(['model' => 'DS2208']);
}

it('sends a device service request with the device from the product, never from the request (MA-19)', function () {
    $product = tmaServiceable();

    $this->post('/tma/uz/p/'.$product->slug.'/service', [
        'message' => 'Skaner shtrix-kodni oʻqimayapti',
        'serial_number' => 'SN 12/34.5-A',
        'preferred_contact' => 'telegram',
        'device_brand' => 'Fake', 'device_model' => 'Fake', 'type' => 'quote', 'phone' => '+998911111111',
    ])->assertRedirect(route('tma.product', ['locale' => 'uz', 'product' => $product->slug]))->assertSessionHas('tma_status', 'service_requested');

    $lead = Lead::query()->sole();
    expect($lead->type)->toBe(LeadType::ServiceRequest)
        ->and($lead->source)->toBe(LeadSource::Telegram)
        ->and($lead->device_brand)->toBe($product->brand->name)
        ->and($lead->device_model)->toBe('DS2208')
        ->and($lead->serial_number)->toBe('SN 12/34.5-A')
        ->and($lead->phone)->toBe($this->tgUser->phone);
});

it('validates the service form (P36-6)', function (array $input, string $field) {
    $this->post('/tma/uz/p/'.tmaServiceable()->slug.'/service', array_merge(['message' => 'Printer chop etmayapti', 'preferred_contact' => 'call'], $input))
        ->assertSessionHasErrors($field);

    expect(Lead::query()->count())->toBe(0);
})->with([
    'short problem' => [['message' => 'short'], 'message'],
    'serial with a line break' => [['serial_number' => "SN1\r\nInjected"], 'serial_number'],
    'serial with symbols' => [['serial_number' => '<script>'], 'serial_number'],
    'unknown contact' => [['preferred_contact' => 'pigeon'], 'preferred_contact'],
]);

it('re-checks the product on submit (P36-7) and limits service requests per user', function () {
    $product = tmaServiceable();

    foreach (range(1, 5) as $hit) {
        RateLimiter::hit('tma-service:'.$this->tgUser->id, 3600);
    }

    $this->post('/tma/uz/p/'.$product->slug.'/service', ['message' => 'Printer chop etmayapti', 'preferred_contact' => 'call'])->assertSessionHasErrors('form');

    $product->update(['is_active' => false]);
    $this->post('/tma/uz/p/'.$product->slug.'/service', ['message' => 'Printer chop etmayapti', 'preferred_contact' => 'call'])->assertNotFound();
    expect(Lead::query()->count())->toBe(0);
});
