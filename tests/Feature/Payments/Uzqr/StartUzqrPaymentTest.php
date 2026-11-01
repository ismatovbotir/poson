<?php

use App\Actions\Payments\Uzqr\StartUzqrPayment;
use App\Actions\Payments\Uzqr\SweepUzqrInvoices;
use App\Actions\Payments\Uzqr\UzqrPaymentRefused;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Enums\UzqrTransactionStatus;
use App\Events\Uzqr\UzqrIntegrationProblem;
use App\Models\Lead;
use App\Models\UzqrTransaction;
use App\Services\Payments\Uzqr\UzqrConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\Uzqr;

beforeEach(function () {
    Uzqr::configure();
    Event::fake([UzqrIntegrationProblem::class]);
});

function startUzqr($order): UzqrTransaction
{
    return app(StartUzqrPayment::class)->handle($order);
}

function expectRefused(callable $callback, string $reason): void
{
    try {
        $callback();
        test()->fail('expected UzqrPaymentRefused');
    } catch (UzqrPaymentRefused $refused) {
        expect($refused->reason)->toBe($reason);
    }
}

it('creates an invoice for the exact total and holds the order', function () {
    Http::fake([Uzqr::url('invoice/create') => Uzqr::created('INV000000001')]);
    $order = Uzqr::order([], total: 123_456_789);

    $transaction = startUzqr($order);

    expect($transaction->status)->toBe(UzqrTransactionStatus::Pending)
        ->and($transaction->mode)->toBe(PaymentMode::Prod)
        ->and($transaction->external_id)->toBe(UzqrTransaction::externalIdFor($transaction->id, PaymentMode::Prod))
        ->and($transaction->external_id)->toMatch('/^PS\d{12}$/')
        ->and($transaction->invoice_id)->toBe('INV000000001')
        ->and($transaction->amount)->toBe(123_456_789)
        ->and($transaction->expires_at->diffInMinutes($transaction->created_at, true))->toEqual(24 * 60 - 2)
        ->and($order->refresh()->payment_status)->toBe(PaymentStatus::Pending)
        ->and($order->hasActiveTransaction())->toBeTrue()
        ->and($order->isPayable())->toBeFalse();

    Http::assertSent(fn (Request $request) => $request['amount'] === '1234567.89' && $request['external_id'] === $transaction->external_id);
});

it('reuses the open invoice: one bank call for two opens', function () {
    Http::fake([Uzqr::url('invoice/create') => Uzqr::created()]);
    $order = Uzqr::order();

    $first = startUzqr($order);
    $second = startUzqr($order);

    expect($second->id)->toBe($first->id)->and(UzqrTransaction::count())->toBe(1);
    Http::assertSentCount(1);
});

it('returns a row another request is still creating instead of a second one', function () {
    Http::fake([Uzqr::url('invoice/create') => Uzqr::created()]);
    $order = Uzqr::order();
    $creating = UzqrTransaction::factory()->creating()->create(['order_id' => $order->id, 'amount' => $order->total]);

    expect(startUzqr($order)->id)->toBe($creating->id)->and(UzqrTransaction::count())->toBe(1);
    Http::assertNothingSent();
});

it('keeps a transiently failed row creating and retries later with the same external_id', function () {
    Http::fakeSequence(Uzqr::url('invoice/create'))
        ->push(['error' => ['error_code' => 9003]], 503)->push(['error' => ['error_code' => 9003]], 503)
        ->push(['invoice_id' => 'INV5', 'qr_text' => 'QR5']);
    $order = Uzqr::order();

    // Interactive: one in-request retry at most (change review L2).
    $transaction = startUzqr($order);
    expect($transaction->status)->toBe(UzqrTransactionStatus::Creating)->and($transaction->last_error)->toBe('server_error');
    Http::assertSentCount(2);

    $this->travel(15)->seconds();
    $retried = startUzqr($order);

    expect($retried->id)->toBe($transaction->id)->and($retried->status)->toBe(UzqrTransactionStatus::Pending)->and($retried->invoice_id)->toBe('INV5');
    Http::assertSent(fn (Request $request) => $request['external_id'] === $transaction->external_id);
    expect(collect(Http::recorded())->map(fn ($pair) => $pair[0]['external_id'])->unique()->all())->toBe([$transaction->external_id]);
});

it('closes a permanent failure as create_failed and lets a new QR be made', function () {
    Http::fake([Uzqr::url('invoice/create') => Uzqr::error(2002, 400)]);
    $order = Uzqr::order();

    $transaction = startUzqr($order);

    expect($transaction->status)->toBe(UzqrTransactionStatus::CreateFailed)->and($transaction->last_error_code)->toBe(2002)
        ->and($order->refresh()->payment_status)->toBe(PaymentStatus::Unpaid)->and($order->hasActiveTransaction())->toBeFalse();
});

it('turns creating rows older than 2 minutes into create_failed (sweep) and never reuses the external_id', function () {
    Http::fake([Uzqr::url('invoice/create') => Uzqr::created('INV7')]);
    $order = Uzqr::order();
    $stale = UzqrTransaction::factory()->creating()->create(['order_id' => $order->id, 'amount' => $order->total, 'created_at' => now()->subMinutes(3)]);

    app(SweepUzqrInvoices::class)->handle();

    expect($stale->refresh()->status)->toBe(UzqrTransactionStatus::CreateFailed)->and($stale->last_error)->toBe('create_timeout');

    $new = startUzqr($order);
    expect($new->id)->not->toBe($stale->id)->and($new->external_id)->not->toBe($stale->external_id);
});

it('refuses orders that cannot be paid with dynamic UzQR', function (Closure $make, string $reason) {
    Http::fake();
    expectRefused(fn () => startUzqr($make()), $reason);
    expect(UzqrTransaction::count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'paid' => [fn () => Uzqr::order(['payment_status' => PaymentStatus::Paid]), 'not_payable'],
    'cancelled' => [fn () => Uzqr::order(['status' => OrderStatus::Cancelled]), 'not_payable'],
    'other method' => [fn () => Uzqr::order(['payment_method' => PaymentMethod::UzqrStatic]), 'method'],
    'TEST order in prod mode' => [fn () => Uzqr::order(test: true), 'unavailable'],
    'above the online cap' => [fn () => Uzqr::order(total: 60_000_000_00), 'unavailable'],
]);

it('refuses real orders while the go-live gate is closed', function () {
    Uzqr::configure(gateOpen: false);
    Http::fake();

    expectRefused(fn () => startUzqr(Uzqr::order()), 'unavailable');
    Http::assertNothingSent();
});

it('creates test invoices only for TEST orders in test mode, with the test host and key', function () {
    Uzqr::configure('test');
    Http::fake([Uzqr::url('invoice/create', 'test') => Uzqr::created('INVT1')]);

    $transaction = startUzqr(Uzqr::order(test: true));

    expect($transaction->mode)->toBe(PaymentMode::Test)->and($transaction->external_id)->toMatch('/^PT\d{12}$/');
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), Uzqr::TEST) && str_contains(Uzqr::authHeader($request), ':'.sha1(explode(':', Uzqr::authHeader($request))[2].Uzqr::TEST_KEY).':'));

    expectRefused(fn () => startUzqr(Uzqr::order()), 'unavailable');
});

it('caps invoices per order per hour and per day', function () {
    Http::fake([Uzqr::url('invoice/create') => Uzqr::created()]);
    $order = Uzqr::order();
    UzqrTransaction::factory()->failed()->count(3)->create(['order_id' => $order->id]);

    expectRefused(fn () => startUzqr($order), 'order_cap');

    UzqrTransaction::query()->update(['created_at' => now()->subHours(2)]);
    UzqrTransaction::factory()->failed()->count(2)->create(['order_id' => $order->id, 'created_at' => now()->subHours(2)]);

    if (now(Lead::TIMEZONE)->subHours(2)->isSameDay(now(Lead::TIMEZONE))) {
        expectRefused(fn () => startUzqr($order), 'order_cap');
    }

    Http::assertNothingSent();
});

it('flags auth failures, hides the method and alerts finance once an hour', function () {
    Http::fake([Uzqr::url('invoice/create') => Uzqr::error(1001, 401)]);

    $transaction = startUzqr(Uzqr::order());

    expect($transaction->status)->toBe(UzqrTransactionStatus::CreateFailed)
        ->and(app(UzqrConfig::class)->authFailed(PaymentMode::Prod))->toBeTrue()
        ->and(app(UzqrConfig::class)->canStart(PaymentMode::Prod))->toBeFalse();

    expectRefused(fn () => startUzqr(Uzqr::order()), 'unavailable');

    Event::assertDispatchedTimes(UzqrIntegrationProblem::class, 1);
    Event::assertDispatched(UzqrIntegrationProblem::class, fn ($event) => $event->problem === 'auth_failed');
});

it('refuses a QR whose EMVCo amount differs from the order total', function () {
    Http::fake([
        Uzqr::url('invoice/create') => Uzqr::created('INV8', '000201010212540599.00'.'5303860'),
        Uzqr::url('invoice/cancel') => Http::response('', 200),
    ]);

    $transaction = startUzqr(Uzqr::order(total: 10_000));

    expect($transaction->status)->toBe(UzqrTransactionStatus::CreateFailed)->and($transaction->last_error)->toBe('qr_amount_mismatch')->and($transaction->qr_text)->toBeNull();
    Event::assertDispatched(UzqrIntegrationProblem::class, fn ($event) => $event->problem === 'qr_amount_mismatch');
});

it('refuses an invoice_id another row already has', function () {
    UzqrTransaction::factory()->failed()->create(['invoice_id' => 'INVDUP']);
    Http::fake([Uzqr::url('invoice/create') => Uzqr::created('INVDUP')]);

    $transaction = startUzqr(Uzqr::order());

    expect($transaction->status)->toBe(UzqrTransactionStatus::CreateFailed)->and($transaction->last_error)->toBe('duplicate_invoice');
});

it('refuses new invoices above the global open cap', function () {
    config(['checkout.uzqr_max_open' => 1]);
    UzqrTransaction::factory()->create();
    Http::fake();

    expectRefused(fn () => startUzqr(Uzqr::order()), 'unavailable');
});
