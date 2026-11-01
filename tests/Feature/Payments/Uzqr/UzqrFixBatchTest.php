<?php

use App\Actions\Orders\DeleteTestOrder;
use App\Actions\Payments\Uzqr\RefreshUzqrInvoice;
use App\Actions\Payments\Uzqr\ReleaseUzqrHold;
use App\Actions\Payments\Uzqr\SweepUzqrInvoices;
use App\Enums\OrderStatus;
use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Enums\UzqrTransactionStatus;
use App\Events\Orders\OrderPaid;
use App\Events\Uzqr\UzqrHoldReleased;
use App\Events\Uzqr\UzqrIntegrationProblem;
use App\Events\Uzqr\UzqrPaymentUnmatched;
use App\Livewire\Admin\Orders\UzqrPanel;
use App\Livewire\Site\Orders\UzqrPayment;
use App\Models\Activity;
use App\Models\Order;
use App\Models\UzqrTransaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Uzqr;

/**
 * Plan 11e fix batch: M-a (expired holding rows keep being checked), released
 * rows past the re-check window, manual check of every awaiting row, audit of
 * a superseded release, the per-order new-QR cap and hardening.
 */
beforeEach(function () {
    Uzqr::configure();
    Event::fake([OrderPaid::class, UzqrHoldReleased::class, UzqrPaymentUnmatched::class, UzqrIntegrationProblem::class]);
    config(['uzqr.limits.upstream_per_second' => 50]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function batchUzqr(array $attributes = [], bool $test = false, ?Order $order = null): UzqrTransaction
{
    $order ??= Uzqr::order(['payment_status' => PaymentStatus::Pending], $test);

    return UzqrTransaction::factory()->create([
        'order_id' => $order->id,
        'amount' => $order->total,
        'mode' => $test ? PaymentMode::Test : PaymentMode::Prod,
        'invoice_id' => 'INV000000001',
        'qr_text' => 'https://qr.example.uz/pay/abc123',
        ...$attributes,
    ]);
}

/**
 * A released hold without a bank-final status, released $daysAgo days ago.
 */
function batchReleased(float $daysAgo, bool $test = false, string $invoiceId = 'INV000000001'): UzqrTransaction
{
    $order = Uzqr::order(['payment_status' => PaymentStatus::Unpaid], $test);

    return batchUzqr([
        'invoice_id' => $invoiceId,
        'status' => UzqrTransactionStatus::Released,
        'remote_status' => 1,
        'expires_at' => now()->subDays((int) ceil($daysAgo) + 2),
        'resolution' => UzqrTransaction::RESOLUTION_HOLD_RELEASED,
        'resolved_at' => now()->subMinutes((int) round($daysAgo * 24 * 60)),
        'next_check_at' => null,
    ], $test, $order);
}

function batchSweep(): int
{
    Cache::flush();

    return app(SweepUzqrInvoices::class)->handle();
}

it('keeps checking an expired row that still holds the order daily until expiry + released_check_days (M-a)', function () {
    Http::fake([Uzqr::url('invoice/status') => Uzqr::status(1)]);
    $transaction = batchUzqr([
        'status' => UzqrTransactionStatus::Expired,
        'remote_status' => 1,
        'expires_at' => now()->subDays(2),
        'cancel_requested_at' => now()->subDays(2),
        'alerted_at' => now()->subDays(2),
        'next_check_at' => now()->subMinute(),
    ]);

    // expiry + 2 d
    expect(batchSweep())->toBe(1);
    $transaction->refresh();
    expect($transaction->next_check_at->between(now()->addDay()->subMinute(), now()->addDay()->addMinute()))->toBeTrue()
        ->and($transaction->status)->toBe(UzqrTransactionStatus::Expired)
        ->and($transaction->holdsOrder())->toBeTrue();

    // expiry + 10 d
    $this->travel(8)->days();
    expect(batchSweep())->toBe(1)
        ->and($transaction->refresh()->next_check_at?->isFuture())->toBeTrue()
        ->and($transaction->order->refresh()->payment_status)->toBe(PaymentStatus::Pending);

    // expiry + 31 d: no more automatic checks, the order stays held (only an Admin releases it).
    $this->travel(21)->days();
    expect(batchSweep())->toBe(1)
        ->and($transaction->refresh()->next_check_at)->toBeNull()
        ->and($transaction->holdsOrder())->toBeTrue()
        ->and(batchSweep())->toBe(0);
});

it('stops rescheduling after a bank error once a row is past its window, keeps it inside (sec L3)', function (string $kind, int $days, bool $rescheduled) {
    Http::fake([Uzqr::url('invoice/status') => Uzqr::error(9001, 500)]);
    $transaction = $kind === 'released'
        ? batchReleased($days)
        : batchUzqr(['status' => UzqrTransactionStatus::Expired, 'remote_status' => 1, 'expires_at' => now()->subDays($days), 'cancel_requested_at' => now()->subDays($days)]);

    app(RefreshUzqrInvoice::class)->handle($transaction, 'admin');

    $transaction->refresh();
    expect($transaction->last_error_code)->toBe(9001)
        ->and($transaction->next_check_at !== null)->toBe($rescheduled);
})->with([
    'released inside' => ['released', 10, true],
    'released past' => ['released', 31, false],
    'expired inside' => ['expired', 10, true],
    'expired past' => ['expired', 31, false],
]);

it('drops released holds past the window from awaitingBank and the sweep, keeps them in the history (review #1)', function () {
    $inside = batchReleased(29);
    $past = batchReleased(31, invoiceId: 'INV000000002');
    $inside->forceFill(['next_check_at' => now()->subMinute()])->save();
    $past->forceFill(['next_check_at' => now()->subMinute()])->save();
    Http::fake([Uzqr::url('invoice/status') => Uzqr::status(1)]);

    expect(UzqrTransaction::query()->awaitingBank()->pluck('id')->all())->toBe([$inside->id])
        ->and(UzqrTransaction::query()->checkable()->pluck('id')->sort()->values()->all())->toBe([$inside->id, $past->id])
        ->and(batchSweep())->toBe(1);

    $html = Livewire::actingAs(staffUser())->test(UzqrPanel::class, ['orderId' => $past->order_id])->html();
    expect($html)->toContain(UzqrTransactionStatus::Released->label());
});

it('lets DeleteTestOrder remove a TEST order whose released hold is past the window, refuses inside it (review #1)', function (int $days, bool $deletable) {
    Uzqr::configure('test');
    $transaction = batchReleased($days, test: true);

    if ($deletable) {
        app(DeleteTestOrder::class)->handle($transaction->order);
        expect(Order::query()->whereKey($transaction->order_id)->exists())->toBeFalse();
    } else {
        expect(fn () => app(DeleteTestOrder::class)->handle($transaction->order))->toThrow(ValidationException::class)
            ->and(Order::query()->whereKey($transaction->order_id)->exists())->toBeTrue();
    }
})->with(['inside the window' => [29, false], 'past the window' => [31, true]]);

it('still checks a released hold past the window on a manual "Check status now" and closes it on 3', function () {
    Http::fake([Uzqr::url('invoice/status') => Uzqr::status(3)]);
    $transaction = batchReleased(31);

    Livewire::actingAs(staffUser())->test(UzqrPanel::class, ['orderId' => $transaction->order_id])->call('checkStatus');

    Http::assertSentCount(1);
    expect($transaction->refresh()->closed_at)->not->toBeNull()->and($transaction->isCheckable())->toBeFalse();
});

it('checks every awaiting row of the order on "Check status now", up to 3 (review)', function () {
    Http::fake([Uzqr::url('invoice/status') => fn ($request) => Uzqr::status(1, $request['invoice_id'])]);
    $released = batchReleased(5);
    $order = $released->order;
    $order->forceFill(['payment_status' => PaymentStatus::Pending])->save();
    $pending = batchUzqr(['invoice_id' => 'INV000000002', 'expires_at' => now()->addHours(20)], order: $order);

    Livewire::actingAs(staffUser())->test(UzqrPanel::class, ['orderId' => $order->id])->call('checkStatus');

    $asked = collect(Http::recorded())->map(fn ($pair) => $pair[0]['invoice_id'])->sort()->values()->all();
    expect($asked)->toBe(['INV000000001', 'INV000000002'])
        ->and($released->refresh()->last_checked_at)->not->toBeNull()
        ->and($pending->refresh()->last_checked_at)->not->toBeNull();
});

it('limits a manual check to 3 rows and to the admin limiter', function () {
    config(['uzqr.limits.admin_check_per_minute' => 1]);
    Http::fake([Uzqr::url('invoice/status') => fn ($request) => Uzqr::status(1, $request['invoice_id'])]);
    $order = Uzqr::order(['payment_status' => PaymentStatus::Pending]);

    foreach (range(1, 4) as $i) {
        batchReleased(2, invoiceId: 'INV00000000'.$i)->forceFill(['order_id' => $order->id])->save();
    }

    $component = Livewire::actingAs(staffUser())->test(UzqrPanel::class, ['orderId' => $order->id]);
    $component->call('checkStatus');
    Http::assertSentCount(3);

    $component->call('checkStatus');
    Http::assertSentCount(3);
});

it('records who released a hold and when before a late payment supersedes it (sec L2)', function (bool $payable) {
    $bank = (object) ['status' => 1];
    Http::fake([
        Uzqr::url('invoice/cancel') => Http::response('', 200),
        Uzqr::url('invoice/status') => fn () => Uzqr::status($bank->status),
    ]);
    $transaction = batchUzqr([
        'status' => UzqrTransactionStatus::Expired,
        'remote_status' => 1,
        'expires_at' => now()->subHours(25),
        'last_checked_at' => now()->subMinutes(30),
        'cancel_requested_at' => now()->subHours(25),
    ]);
    $admin = staffUser();
    app(ReleaseUzqrHold::class)->handle($transaction, 'Released', $admin);
    $releasedAt = $transaction->refresh()->resolved_at;

    if (! $payable) {
        $transaction->order->forceFill(['total' => $transaction->order->total + 100])->save();
    }

    $bank->status = 2;
    $this->travel(5)->seconds();
    app(RefreshUzqrInvoice::class)->handle($transaction, 'admin');

    $activity = Activity::query()->where('meta->action', 'uzqr_release_superseded')->sole();
    expect($activity->meta['released_by_id'])->toBe($admin->id)
        ->and($activity->meta['released_at'])->toBe($releasedAt->toIso8601String())
        ->and($activity->meta['transaction_id'])->toBe($transaction->id)
        ->and($transaction->refresh()->resolved_by_id)->toBeNull()
        ->and($transaction->status)->toBe(UzqrTransactionStatus::Paid);

    if ($payable) {
        expect($activity->meta)->not->toHaveKey('reason');
    } else {
        expect($activity->meta['reason'])->toBe('amount_mismatch');
    }

    foreach (['uz', 'ru', 'en'] as $locale) {
        expect(__('uzqr.timeline.uzqr_release_superseded', ['amount' => '1'], $locale))->not->toStartWith('uzqr.');
    }
})->with(['settled' => true, 'unmatched' => false]);

it('counts the per-order new-QR cap only when a new QR is started, not on cancel_pending (review #3)', function () {
    $bank = (object) ['status' => 1];
    Http::fake([
        Uzqr::url('invoice/cancel') => Http::response('', 200),
        Uzqr::url('invoice/status') => fn () => Uzqr::status($bank->status),
        Uzqr::url('invoice/create') => Uzqr::created('INVNEW000001'),
    ]);
    $transaction = batchUzqr(['expires_at' => now()->subMinute()]);
    $key = 'uzqr-new-order:'.$transaction->order_id;
    $component = Livewire::test(UzqrPayment::class, ['ulid' => $transaction->order->ulid, 'locale' => 'en', 'autoOpen' => true]);

    foreach (range(1, 5) as $i) {
        $component->call('newQr')->assertSet('notice', 'cancel_pending');
    }

    expect(RateLimiter::attempts($key))->toBe(0);

    $bank->status = 3;
    $this->travel(61)->seconds();
    Cache::flush();
    $component->call('newQr')->assertSet('notice', null);

    expect(RateLimiter::attempts($key))->toBe(1)
        ->and(UzqrTransaction::query()->where('invoice_id', 'INVNEW000001')->exists())->toBeTrue();
});

it('does not count the cap when the start is refused', function () {
    $order = Uzqr::order(['status' => OrderStatus::Cancelled]);

    Livewire::test(UzqrPayment::class, ['ulid' => $order->ulid, 'locale' => 'en', 'autoOpen' => true])->call('newQr');

    expect(RateLimiter::attempts('uzqr-new-order:'.$order->id))->toBe(0);
    Http::assertNothingSent();
});

it('refuses a new QR without a bank call once 3 were started this hour', function () {
    Http::fake([Uzqr::url('invoice/create') => Uzqr::created('INVNEW000001')]);
    $transaction = batchUzqr(['status' => UzqrTransactionStatus::Failed, 'closed_at' => now()], order: Uzqr::order());
    RateLimiter::hit('uzqr-new-order:'.$transaction->order_id, 3600);
    RateLimiter::hit('uzqr-new-order:'.$transaction->order_id, 3600);
    RateLimiter::hit('uzqr-new-order:'.$transaction->order_id, 3600);

    Livewire::test(UzqrPayment::class, ['ulid' => $transaction->order->ulid, 'locale' => 'en', 'autoOpen' => true])
        ->call('newQr')->assertSet('notice', 'order_cap');

    Http::assertNothingSent();
});

it('caches the rendered QR per row and invoice id', function () {
    $transaction = batchUzqr(['expires_at' => now()->addHours(20)]);

    Livewire::test(UzqrPayment::class, ['ulid' => $transaction->order->ulid, 'locale' => 'en', 'autoOpen' => true]);

    expect(Cache::has('uzqr:qr:'.$transaction->id.':INV000000001'))->toBeTrue()
        ->and(Cache::has('uzqr:qr:'.$transaction->id))->toBeFalse();
});
