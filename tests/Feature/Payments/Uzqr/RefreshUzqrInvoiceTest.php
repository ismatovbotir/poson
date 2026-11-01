<?php

use App\Actions\Payments\Uzqr\CancelUzqrInvoice;
use App\Actions\Payments\Uzqr\RefreshUzqrInvoice;
use App\Actions\Payments\Uzqr\SweepUzqrInvoices;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Enums\UzqrSettlement;
use App\Enums\UzqrTransactionStatus;
use App\Events\Orders\OrderPaid;
use App\Events\Uzqr\UzqrIntegrationProblem;
use App\Events\Uzqr\UzqrPaymentUnmatched;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\UzqrTransaction;
use App\Services\Payments\Uzqr\UzqrConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\Uzqr;

beforeEach(function () {
    Uzqr::configure();
    Uzqr::clearLog();
    Event::fake([OrderPaid::class, UzqrPaymentUnmatched::class, UzqrIntegrationProblem::class]);
});

/**
 * A pending invoice holding an unpaid UzQR order.
 *
 * @param  array<string, mixed>  $attributes
 */
function pendingUzqr(array $attributes = [], array $orderAttributes = [], bool $test = false): UzqrTransaction
{
    $order = Uzqr::order(['payment_status' => PaymentStatus::Pending, ...$orderAttributes], $test);

    return UzqrTransaction::factory()->create(['order_id' => $order->id, 'amount' => $order->total, 'invoice_id' => 'INV000000001', 'mode' => $test ? PaymentMode::Test : PaymentMode::Prod, ...$attributes]);
}

function refreshUzqr(UzqrTransaction $transaction, string $context = 'poll'): UzqrTransaction
{
    Cache::lock('uzqr:poll:'.$transaction->id)->forceRelease();

    return app(RefreshUzqrInvoice::class)->handle($transaction, $context);
}

it('keeps an in-progress invoice pending with backoff by age', function () {
    Http::fake([Uzqr::url('invoice/status') => Uzqr::status(1)]);
    $transaction = pendingUzqr();

    $transaction = refreshUzqr($transaction);

    expect($transaction->status)->toBe(UzqrTransactionStatus::Pending)
        ->and($transaction->next_check_at->diffInSeconds(now(), true))->toBeLessThanOrEqual(61);

    $this->travel(30)->minutes();
    expect(RefreshUzqrInvoice::nextCheck($transaction->created_at, $transaction->expires_at)->diffInMinutes(now(), true))->toEqual(5);
    $this->travel(3)->hours();
    expect(RefreshUzqrInvoice::nextCheck($transaction->created_at, $transaction->expires_at)->diffInMinutes(now(), true))->toEqual(30);
    $this->travel(20 * 60 + 50)->minutes();
    expect(RefreshUzqrInvoice::nextCheck($transaction->created_at, $transaction->expires_at)->equalTo($transaction->expires_at))->toBeTrue();
});

it('settles status 2 once: ledger row, order paid, one OrderPaid, card last 4 only', function () {
    Http::fake([Uzqr::url('invoice/status') => Uzqr::status(2)]);
    $transaction = pendingUzqr();
    $order = $transaction->order;

    refreshUzqr($transaction);
    refreshUzqr($transaction, 'sweep');
    refreshUzqr($transaction, 'admin');

    $transaction->refresh();
    expect($transaction->status)->toBe(UzqrTransactionStatus::Paid)
        ->and($transaction->settlement)->toBe(UzqrSettlement::Settled)
        ->and($transaction->pay_id)->toBe(Uzqr::PAY_ID)
        ->and($transaction->card_last4)->toBe('1234')
        ->and($transaction->card_type)->toBe(2)
        ->and($order->refresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and($order->hasActiveTransaction())->toBeFalse();

    $payments = OrderPayment::query()->where('order_id', $order->id)->get();
    expect($payments)->toHaveCount(1)
        ->and($payments[0]->provider)->toBe('uzqr')
        ->and($payments[0]->provider_ref)->toBe(Uzqr::PAY_ID)
        ->and($payments[0]->method)->toBe(PaymentMethod::UzqrDynamic)
        ->and($payments[0]->amount)->toBe($order->total);

    Event::assertDispatchedTimes(OrderPaid::class, 1);
    Http::assertSentCount(1);
});

it('never stores or logs the payer phone or the full card number', function () {
    Http::fake([Uzqr::url('invoice/status') => Uzqr::status(2)]);
    refreshUzqr(pendingUzqr());

    $dump = json_encode([
        DB::table('uzqr_transactions')->get(), DB::table('order_payments')->get(), DB::table('activities')->get(), DB::table('orders')->get(['id', 'comment', 'staff_note']),
    ]);

    expect($dump)->not->toContain('+998901234567')->not->toContain('998901234567')->not->toContain('8600*')->not->toContain('8600')
        ->and(Uzqr::log())->not->toContain('998901234567')->not->toContain('8600')->not->toContain('Bank text');
});

it('closes status 3 and 3001 as final and gives the order back', function (Closure $response) {
    Http::fake([Uzqr::url('invoice/status') => $response()]);
    $transaction = pendingUzqr();

    $transaction = refreshUzqr($transaction);

    expect($transaction->status)->toBe(UzqrTransactionStatus::Failed)->and($transaction->closed_at)->not->toBeNull()
        ->and($transaction->order->refresh()->payment_status)->toBe(PaymentStatus::Unpaid)
        ->and($transaction->order->isPayable())->toBeTrue();
})->with([
    'status 3' => [fn () => Uzqr::status(3)],
    '3001' => [fn () => Uzqr::error(3001, 404)],
]);

it('keeps status 2 without pay_id pending and alerts', function () {
    Http::fake([Uzqr::url('invoice/status') => Http::response(['invoice_id' => 'INV000000001', 'status' => 2, 'pay_id' => null])]);
    $transaction = pendingUzqr();

    $transaction = refreshUzqr($transaction);

    expect($transaction->status)->toBe(UzqrTransactionStatus::Pending)->and($transaction->last_error)->toBe('missing_pay_id')
        ->and(OrderPayment::count())->toBe(0);
    Event::assertDispatched(UzqrIntegrationProblem::class, fn ($event) => $event->problem === 'missing_pay_id');
});

it('rejects an answer for another invoice', function () {
    Http::fake([Uzqr::url('invoice/status') => Uzqr::status(2, 'INVOTHER')]);
    $transaction = refreshUzqr(pendingUzqr());

    expect($transaction->status)->toBe(UzqrTransactionStatus::Pending)->and(OrderPayment::count())->toBe(0);
});

it('marks the payment unmatched and leaves the order untouched', function (Closure $tamper, string $reason) {
    Http::fake([Uzqr::url('invoice/status') => Uzqr::status(2)]);
    $transaction = pendingUzqr();
    $tamper($transaction->order);
    $before = $transaction->order->refresh()->only(['payment_status', 'total', 'payment_method', 'status']);

    $transaction = refreshUzqr($transaction);

    expect($transaction->settlement)->toBe(UzqrSettlement::Unmatched)->and($transaction->unmatched_reason)->toBe($reason)
        ->and($transaction->order->refresh()->only(['payment_status', 'total', 'payment_method', 'status']))->toBe($before)
        ->and(OrderPayment::query()->where('provider', 'uzqr')->count())->toBe(0);

    Event::assertDispatched(UzqrPaymentUnmatched::class);
    Event::assertNotDispatched(OrderPaid::class);
})->with([
    'total changed' => [fn (Order $order) => $order->forceFill(['total' => $order->total + 100])->save(), 'amount_mismatch'],
    'paid another way' => [fn (Order $order) => $order->forceFill(['payment_status' => PaymentStatus::Paid])->save(), 'not_payable'],
    'cancelled' => [fn (Order $order) => $order->forceFill(['status' => OrderStatus::Cancelled])->save(), 'order_cancelled'],
    'method changed' => [fn (Order $order) => $order->forceFill(['payment_method' => PaymentMethod::UzqrStatic])->save(), 'method_changed'],
    'mode mismatch' => [fn (Order $order) => $order->forceFill(['is_test' => true])->save(), 'mode_mismatch'],
    'other paid goods row' => [fn (Order $order) => OrderPayment::factory()->create(['order_id' => $order->id, 'amount' => $order->total, 'status' => OrderPaymentStatus::Paid]), 'already_paid'],
]);

it('flags a pay_id that is already recorded as unmatched', function () {
    Http::fake([Uzqr::url('invoice/status') => Uzqr::status(2)]);
    UzqrTransaction::factory()->settled()->create(['pay_id' => Uzqr::PAY_ID]);

    $transaction = refreshUzqr(pendingUzqr());

    expect($transaction->unmatched_reason)->toBe('duplicate_pay_id')->and($transaction->pay_id)->toBeNull();
});

it('coalesces polls: one bank call per invoice per 3 seconds', function () {
    Http::fake([Uzqr::url('invoice/status') => Uzqr::status(1)]);
    $transaction = pendingUzqr();

    app(RefreshUzqrInvoice::class)->handle($transaction);
    app(RefreshUzqrInvoice::class)->handle($transaction);
    app(RefreshUzqrInvoice::class)->handle($transaction);

    Http::assertSentCount(1);
});

it('polls with the row’s own mode credentials after the active mode changed', function () {
    Http::fake([Uzqr::url('invoice/status', 'test') => Uzqr::status(1)]);
    $transaction = pendingUzqr(test: true);

    refreshUzqr($transaction);

    Http::assertSent(fn ($request) => str_starts_with($request->url(), Uzqr::TEST));
});

it('backs off on 429 / 5xx', function () {
    Http::fake([Uzqr::url('invoice/status') => Uzqr::error(6001, 429)]);
    $transaction = refreshUzqr(pendingUzqr());

    expect($transaction->status)->toBe(UzqrTransactionStatus::Pending)->and($transaction->check_failures)->toBe(1)
        ->and($transaction->next_check_at->greaterThan(now()->addMinute()))->toBeTrue();
});

it('flags auth failures on status calls', function () {
    Http::fake([Uzqr::url('invoice/status') => Uzqr::error(1002, 403)]);
    $transaction = refreshUzqr(pendingUzqr(['invoice_id' => 'INV2']));
    expect(app(UzqrConfig::class)->authFailed(PaymentMode::Prod))->toBeTrue();
    Event::assertDispatched(UzqrIntegrationProblem::class, fn ($event) => $event->problem === 'blocked');
});

it('cancels at the bank at expiry, keeps the hold while the bank still says 1, alerts, then releases on 3', function () {
    Http::fake([
        Uzqr::url('invoice/cancel') => Http::response('', 200),
        Uzqr::url('invoice/status') => Http::sequence()->pushResponse(Uzqr::status(1))->pushResponse(Uzqr::status(1))->pushResponse(Uzqr::status(3)),
    ]);
    $transaction = pendingUzqr(['expires_at' => now()->subMinute()]);

    $transaction = refreshUzqr($transaction);
    expect($transaction->cancel_requested_at)->not->toBeNull()->and($transaction->status)->toBe(UzqrTransactionStatus::Pending);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), 'invoice/cancel'));

    $this->travel(10)->minutes();
    $transaction = refreshUzqr($transaction);
    expect($transaction->status)->toBe(UzqrTransactionStatus::Expired)->and($transaction->closed_at)->toBeNull()
        ->and($transaction->order->hasActiveTransaction())->toBeTrue()
        ->and((int) round($transaction->next_check_at->diffInMinutes(now(), true)))->toBe(60);
    Event::assertDispatched(UzqrIntegrationProblem::class, fn ($event) => $event->problem === 'expired_unconfirmed');

    $transaction = refreshUzqr($transaction);
    expect($transaction->status)->toBe(UzqrTransactionStatus::Expired)->and($transaction->closed_at)->not->toBeNull()
        ->and($transaction->order->refresh()->payment_status)->toBe(PaymentStatus::Unpaid);
});

it('settles when cancel answers 3002 already paid', function () {
    Http::fake([
        Uzqr::url('invoice/cancel') => Uzqr::error(3002, 409),
        Uzqr::url('invoice/status') => Uzqr::status(2),
    ]);

    $transaction = app(CancelUzqrInvoice::class)->handle(pendingUzqr());

    expect($transaction->status)->toBe(UzqrTransactionStatus::Paid)->and($transaction->order->refresh()->payment_status)->toBe(PaymentStatus::Paid);
});

it('releases the hold after a staff cancel only once the bank confirms', function () {
    Http::fake([
        Uzqr::url('invoice/cancel') => Http::response('', 200),
        Uzqr::url('invoice/status') => Http::sequence()->pushResponse(Uzqr::status(1))->pushResponse(Uzqr::status(3)),
    ]);
    $transaction = pendingUzqr();

    $transaction = app(CancelUzqrInvoice::class)->handle($transaction);
    expect($transaction->holdsOrder())->toBeTrue()->and($transaction->order->hasActiveTransaction())->toBeTrue();

    $transaction = refreshUzqr($transaction);
    expect($transaction->status)->toBe(UzqrTransactionStatus::Failed)->and($transaction->order->hasActiveTransaction())->toBeFalse();
});

it('sweeps only due rows, oldest first, up to the batch size', function () {
    Http::fake([Uzqr::url('invoice/status') => fn ($request) => Uzqr::status(1, $request['invoice_id'])]);
    $due = pendingUzqr(['invoice_id' => 'INVDUE', 'next_check_at' => now()->subMinute()]);
    $later = pendingUzqr(['invoice_id' => 'INVLATER', 'next_check_at' => now()->addMinutes(5)]);
    UzqrTransaction::factory()->failed()->create(['invoice_id' => 'INVCLOSED', 'next_check_at' => now()->subMinute()]);

    expect(app(SweepUzqrInvoices::class)->handle())->toBe(1);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'INVDUE'));
    expect($later->refresh()->last_checked_at)->toBeNull()
        ->and($this->artisan('uzqr:sync')->run())->toBe(0);
});

it('settles once when poll, sweep and admin all see status 2', function () {
    Http::fake([Uzqr::url('invoice/status') => Uzqr::status(2)]);
    $transaction = pendingUzqr(['next_check_at' => now()->subMinute()]);

    refreshUzqr($transaction);
    app(SweepUzqrInvoices::class)->handle();
    refreshUzqr($transaction, 'admin');

    expect(OrderPayment::count())->toBe(1);
    Event::assertDispatchedTimes(OrderPaid::class, 1);
});
