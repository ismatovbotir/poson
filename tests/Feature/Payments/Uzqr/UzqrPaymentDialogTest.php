<?php

use App\Actions\Payments\Uzqr\StartUzqrPayment;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UzqrTransactionStatus;
use App\Events\Orders\OrderPaid;
use App\Livewire\Site\Orders\UzqrPayment;
use App\Models\UzqrTransaction;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\Uzqr;

beforeEach(function () {
    Uzqr::configure();
    Event::fake([OrderPaid::class]);
});

function uzqrDialog($order, bool $autoOpen = true)
{
    return Livewire::test(UzqrPayment::class, ['ulid' => $order->ulid, 'locale' => 'en', 'autoOpen' => $autoOpen]);
}

it('shows the dialog on /o for a dynamic UzQR order and opens it after checkout', function () {
    $order = Uzqr::order();

    $html = $this->get(route('orders.public', ['ulid' => $order->ulid, 'pay' => 'uzqr', 'lang' => 'en']))
        ->assertOk()
        ->assertSeeLivewire(UzqrPayment::class)
        ->assertSee(__('uzqr.site.title', [], 'en'))
        ->getContent();

    expect($html)->not->toContain('data-uzqr-block')->not->toContain('data-uzqr-qr');
    expectNoBannedWording($html);
    Http::assertNothingSent();
});

it('creates the QR only on a click, renders it as an image data URI and never exposes qr_text as a property', function () {
    Http::fake([Uzqr::url('invoice/create') => Uzqr::created('INV1', 'https://qr.example.uz/pay/SECRETQR')]);
    $order = Uzqr::order();

    $component = uzqrDialog($order);
    Http::assertNothingSent();

    $component->call('open')->assertSet('notice', null);

    $html = $component->html();
    expect($html)->toContain('data:image/svg+xml;base64,')->toContain('data:image/png;base64,')->toContain('wire:poll.3s')
        ->toContain(Money::format($order->total))
        ->not->toContain('<svg')->not->toContain('SECRETQR')
        ->and(json_encode($component->snapshot))->not->toContain('SECRETQR')->not->toContain('INV1');

    expect(UzqrTransaction::count())->toBe(1)->and($order->refresh()->payment_status)->toBe(PaymentStatus::Pending);
});

it('polls, settles and removes the poll attribute once paid', function () {
    Http::fake([
        Uzqr::url('invoice/create') => Uzqr::created('INV000000001'),
        Uzqr::url('invoice/status') => Uzqr::status(2),
    ]);
    $order = Uzqr::order();
    $component = uzqrDialog($order)->call('open');

    $component->call('refresh');

    expect($order->refresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and($component->html())->not->toContain('wire:poll')->toContain('data-uzqr-paid');
    Event::assertDispatchedTimes(OrderPaid::class, 1);
});

it('stops auto-polling after 15 minutes and shows a check button', function () {
    $order = Uzqr::order(['payment_status' => PaymentStatus::Pending]);
    UzqrTransaction::factory()->create(['order_id' => $order->id, 'amount' => $order->total, 'created_at' => now()->subMinutes(16)]);

    $html = uzqrDialog($order)->html();

    expect($html)->not->toContain('wire:poll')->toContain('data-uzqr-check');
});

it('offers a new QR after the previous one ended, cancelling nothing still open', function () {
    Http::fake([Uzqr::url('invoice/create') => Uzqr::created('INV2')]);
    $order = Uzqr::order();
    UzqrTransaction::factory()->failed()->create(['order_id' => $order->id, 'amount' => $order->total]);

    $component = uzqrDialog($order);
    expect($component->html())->toContain('data-uzqr-new');

    $component->call('newQr');
    expect(UzqrTransaction::query()->where('status', UzqrTransactionStatus::Pending->value)->count())->toBe(1);
});

it('cancels an invoice still open at the bank before a new QR and waits while the bank says 1', function () {
    Http::fake([
        Uzqr::url('invoice/cancel') => Http::response('', 200),
        Uzqr::url('invoice/status') => Uzqr::status(1),
    ]);
    $order = Uzqr::order(['payment_status' => PaymentStatus::Pending]);
    UzqrTransaction::factory()->create(['order_id' => $order->id, 'amount' => $order->total, 'invoice_id' => 'INV000000001', 'expires_at' => now()->subMinute()]);

    uzqrDialog($order)->call('newQr')->assertSet('notice', 'cancel_pending');

    expect(UzqrTransaction::count())->toBe(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'invoice/create'));
});

it('answers 404 for unknown, expired, TEST, renewed or non-UzQR links on every action', function (Closure $make) {
    $order = $make();

    uzqrDialog($order)->assertNotFound();
})->with([
    'expired link' => [fn () => Uzqr::order(['public_until' => now()->subDay()])],
    'TEST order' => [fn () => Uzqr::order(test: true)],
    'static method' => [fn () => Uzqr::order(['payment_method' => PaymentMethod::UzqrStatic])],
]);

it('re-resolves the order on each call: a link renewed after mount is a 404', function () {
    $order = Uzqr::order();
    $component = uzqrDialog($order);
    $order->forceFill(['ulid' => strtoupper((string) Str::ulid())])->save();

    $component->call('open')->assertNotFound();
});

it('takes no arguments and keeps the ULID locked', function () {
    $order = Uzqr::order();
    $other = Uzqr::order();

    expect(fn () => uzqrDialog($order)->set('ulid', $other->ulid))->toThrow(Exception::class, 'Cannot update locked property')
        ->and((new ReflectionMethod(UzqrPayment::class, 'open'))->getParameters()[0]->getType()?->getName())->toBe(StartUzqrPayment::class)
        ->and(collect((new ReflectionMethod(UzqrPayment::class, 'refresh'))->getParameters())->every(fn ($parameter) => class_exists((string) $parameter->getType())))->toBeTrue();
});

it('throttles opening per IP and polling per IP and order', function () {
    Http::fake([Uzqr::url('invoice/status') => Uzqr::status(1)]);
    $order = Uzqr::order(['payment_status' => PaymentStatus::Pending]);
    $transaction = UzqrTransaction::factory()->create(['order_id' => $order->id, 'amount' => $order->total, 'invoice_id' => 'INV000000001']);

    for ($i = 0; $i < 10; $i++) {
        RateLimiter::hit('uzqr-open:'.sha1('127.0.0.1'), 60);
    }

    uzqrDialog($order)->call('open')->assertSet('notice', 'too_many_short');

    config(['uzqr.limits.refresh_per_order_minute' => 2]);
    $component = uzqrDialog($order);

    foreach (range(1, 4) as $i) {
        Cache::lock('uzqr:poll:'.$transaction->id)->forceRelease();
        $component->call('refresh');
    }

    Http::assertSentCount(2);
});
