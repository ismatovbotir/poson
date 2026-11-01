<?php

use App\Actions\Orders\DeleteTestOrder;
use App\Actions\Payments\Uzqr\ReleaseUzqrHold;
use App\Actions\Payments\Uzqr\SweepUzqrInvoices;
use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Enums\UzqrSettlement;
use App\Enums\UzqrTransactionStatus;
use App\Events\Orders\OrderPaid;
use App\Events\Uzqr\UzqrHoldReleased;
use App\Events\Uzqr\UzqrIntegrationProblem;
use App\Events\Uzqr\UzqrPaymentUnmatched;
use App\Livewire\Admin\Orders\UzqrPanel;
use App\Livewire\Site\Orders\UzqrPayment;
use App\Models\Activity;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\UzqrTransaction;
use App\Support\Admin\PasswordConfirmation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Uzqr;

/**
 * Admin "Release hold" of an expired UzQR invoice the bank never confirmed (sign-off conditions 57–67).
 */
beforeEach(function () {
    Uzqr::configure();
    Event::fake([OrderPaid::class, UzqrHoldReleased::class, UzqrPaymentUnmatched::class, UzqrIntegrationProblem::class]);
    config(['uzqr.limits.upstream_per_second' => 50]);
});

/**
 * An expired invoice still "1" at the bank, holding a pending order, eligible for release.
 *
 * @param  array<string, mixed>  $attributes
 */
function releasableUzqr(array $attributes = [], bool $test = false): UzqrTransaction
{
    $order = Uzqr::order(['payment_status' => PaymentStatus::Pending], $test);

    return UzqrTransaction::factory()->create([
        'order_id' => $order->id,
        'amount' => $order->total,
        'mode' => $test ? PaymentMode::Test : PaymentMode::Prod,
        'invoice_id' => 'INV000000001',
        'status' => UzqrTransactionStatus::Expired,
        'remote_status' => 1,
        'expires_at' => now()->subHours(25),
        'last_checked_at' => now()->subMinutes(30),
        'cancel_requested_at' => now()->subHours(25),
        'next_check_at' => null,
        ...$attributes,
    ]);
}

function releasePanel(UzqrTransaction $transaction, $user, bool $confirmed = true)
{
    if ($confirmed) {
        session()->put(PasswordConfirmation::SESSION_KEY, now()->unix());
    }

    return Livewire::actingAs($user)->test(UzqrPanel::class, ['orderId' => $transaction->order_id]);
}

function releaseVia(UzqrTransaction $transaction, $admin, string $note = 'Customer paid by bank transfer instead')
{
    return releasePanel($transaction, $admin)->call('startRelease', $transaction->id)->set('releaseNote', $note)->call('release');
}

function bankAnswers(mixed $cancel, mixed $status): void
{
    Http::fake([
        Uzqr::url('invoice/cancel') => $cancel,
        Uzqr::url('invoice/status') => $status,
    ]);
}

it('decides eligibility only for expired, unconfirmed rows 24 h past expiry and checked since (57)', function (array $attributes, bool $releasable) {
    expect(releasableUzqr($attributes)->isReleasable())->toBe($releasable);
})->with([
    'eligible' => [[], true],
    'expired only 23 h ago' => [['expires_at' => now()->subHours(23)], false],
    'last checked before expiry + 24 h' => [['last_checked_at' => now()->subHours(2)], false],
    'never checked' => [['last_checked_at' => null], false],
    'bank-confirmed (closed)' => [['closed_at' => now()], false],
    'pending' => [['status' => UzqrTransactionStatus::Pending], false],
    'creating' => [['status' => UzqrTransactionStatus::Creating], false],
    'paid' => [['status' => UzqrTransactionStatus::Paid, 'settlement' => UzqrSettlement::Settled], false],
    'unmatched' => [['settlement' => UzqrSettlement::Unmatched], false],
    'no invoice id' => [['invoice_id' => null], false],
    'already released' => [['status' => UzqrTransactionStatus::Released], false],
]);

it('shows "Release hold" to an Admin only and only once eligible (67)', function () {
    $transaction = releasableUzqr();
    $early = releasableUzqr(['invoice_id' => 'INV000000002', 'expires_at' => now()->subHours(23)]);

    expect(releasePanel($transaction, staffUser())->html())->toContain('data-action="uzqr-release"')
        ->and(releasePanel($transaction, staffUser(Role::SalesManager))->html())->not->toContain('data-action="uzqr-release"')
        ->and(releasePanel($early, staffUser())->html())->not->toContain('data-action="uzqr-release"');
});

it('answers 403 to a Sales manager calling the release directly (58, 67)', function (string $method, array $args) {
    Http::fake();
    $transaction = releasableUzqr();

    releasePanel($transaction, staffUser(Role::SalesManager))->call($method, ...$args)->assertForbidden();

    Http::assertNothingSent();
    expect($transaction->refresh()->status)->toBe(UzqrTransactionStatus::Expired);
})->with([
    'startRelease' => ['startRelease', [1]],
    'release' => ['release', []],
]);

it('refuses the release in the action for a non-Admin actor', function () {
    Http::fake();
    $transaction = releasableUzqr();

    expect(fn () => app(ReleaseUzqrHold::class)->handle($transaction, 'note', staffUser(Role::SalesManager)))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

it('sends the Admin to confirm the password first and changes nothing (58, 67)', function () {
    Http::fake();
    $transaction = releasableUzqr();
    $admin = staffUser();

    releasePanel($transaction, $admin, confirmed: false)->call('startRelease', $transaction->id)->assertRedirect(route('admin.confirm-password'));
    releasePanel($transaction, $admin, confirmed: false)->set('releaseNote', 'x')->call('release')->assertRedirect(route('admin.confirm-password'));

    $this->travel(16)->minutes();
    releasePanel($transaction, $admin, confirmed: false)->call('startRelease', $transaction->id)->assertRedirect(route('admin.confirm-password'));

    Http::assertNothingSent();
    expect($transaction->refresh()->status)->toBe(UzqrTransactionStatus::Expired)->and($transaction->resolved_at)->toBeNull();
});

it('requires a reason of 1–500 characters (58)', function () {
    Http::fake();
    $transaction = releasableUzqr();

    releaseVia($transaction, staffUser(), '')->assertHasErrors('releaseNote');
    releaseVia($transaction, staffUser(), str_repeat('a', 501))->assertHasErrors('releaseNote');
    expect(fn () => app(ReleaseUzqrHold::class)->handle($transaction, '   ', staffUser()))->toThrow(ValidationException::class);

    Http::assertNothingSent();
});

it('releases when the bank still says 1: hold gone, order unpaid, activity, one alert without PII (59, 60, 63, 67)', function () {
    bankAnswers(Http::response('', 200), Uzqr::status(1));
    $transaction = releasableUzqr(['card_last4' => null]);
    $admin = staffUser();

    releaseVia($transaction, $admin)->assertHasNoErrors()->assertDispatched('toast');

    $transaction->refresh();
    $order = $transaction->order->refresh();
    expect($transaction->status)->toBe(UzqrTransactionStatus::Released)
        ->and($transaction->resolution)->toBe(UzqrTransaction::RESOLUTION_HOLD_RELEASED)
        ->and($transaction->resolved_by_id)->toBe($admin->id)
        ->and($transaction->resolution_note)->toBe('Customer paid by bank transfer instead')
        ->and($transaction->closed_at)->toBeNull()->and($transaction->settlement)->toBeNull()
        ->and($transaction->next_check_at)->not->toBeNull()
        ->and($transaction->holdsOrder())->toBeFalse()->and($transaction->isCheckable())->toBeTrue()
        ->and($order->hasActiveTransaction())->toBeFalse()
        ->and($order->payment_status)->toBe(PaymentStatus::Unpaid);

    $activity = Activity::query()->where('meta->action', 'uzqr_hold_released')->sole();
    expect($activity->user_id)->toBe($admin->id)->and($activity->meta['transaction_id'])->toBe($transaction->id)
        ->and($activity->meta['amount'])->toBe($transaction->amount)->and($activity->meta['bank'])->toBe('still_open')
        ->and($activity->body)->toBe('Customer paid by bank transfer instead');

    Event::assertDispatchedTimes(UzqrHoldReleased::class, 1);
    $event = new UzqrHoldReleased($transaction, $admin);
    $html = $event->telegramMessage('ru')->html;
    expect($event->telegramChats())->toBe(['finance', 'sales'])
        ->and($html)->toContain($order->number)->toContain(e($admin->name))
        ->not->toContain('INV000000001')->not->toContain('+998')->not->toContain('8600')->not->toContain('Bank text');
    expectNoBannedWording($html);
});

it('marks the alert of a released TEST order as TEST', function () {
    $transaction = releasableUzqr(test: true);

    expect((new UzqrHoldReleased($transaction))->telegramMessage('uz')->html)->toContain('TEST');
});

it('releases on a transient or auth error of the bank, recording the outcome (59)', function (mixed $status, string $bank) {
    bankAnswers(Http::response('', 200), $status);
    $transaction = releasableUzqr();

    expect(app(ReleaseUzqrHold::class)->handle($transaction, 'Bank is down, customer waiting', staffUser()))->toBe('released')
        ->and($transaction->refresh()->status)->toBe(UzqrTransactionStatus::Released)
        ->and(Activity::query()->where('meta->action', 'uzqr_hold_released')->sole()->meta['bank'])->toBe($bank);
})->with([
    '503' => [fn () => Uzqr::error(9003, 503), 'bank_error'],
    'auth' => [fn () => Uzqr::error(1001, 401), 'auth_error'],
]);

it('settles or closes instead of releasing when the bank gives a final status (59, 67)', function (mixed $cancel, mixed $status, string $outcome, UzqrTransactionStatus $final) {
    bankAnswers($cancel, $status);
    $transaction = releasableUzqr();

    expect(app(ReleaseUzqrHold::class)->handle($transaction, 'Release please', staffUser()))->toBe($outcome);

    $transaction->refresh();
    expect($transaction->status)->toBe($final)->and($transaction->resolution)->toBeNull()
        ->and($transaction->closed_at)->not->toBeNull()
        ->and(Activity::query()->where('meta->action', 'uzqr_hold_released')->exists())->toBeFalse()
        ->and(Activity::query()->where('meta->action', 'uzqr_hold_release_stopped')->sole()->meta['bank'])->toBe($outcome);
    Event::assertNotDispatched(UzqrHoldReleased::class);

    if ($outcome === 'settled') {
        expect($transaction->order->refresh()->payment_status)->toBe(PaymentStatus::Paid);
        Event::assertDispatchedTimes(OrderPaid::class, 1);
    } else {
        expect($transaction->order->refresh()->payment_status)->toBe(PaymentStatus::Unpaid);
    }
})->with([
    'status 2' => [fn () => Http::response('', 200), fn () => Uzqr::status(2), 'settled', UzqrTransactionStatus::Paid],
    'cancel 3002 + status 2' => [fn () => Uzqr::error(3002, 409), fn () => Uzqr::status(2), 'settled', UzqrTransactionStatus::Paid],
    'status 3' => [fn () => Http::response('', 200), fn () => Uzqr::status(3), 'closed', UzqrTransactionStatus::Expired],
    'cancel 3001' => [fn () => Uzqr::error(3001, 404), fn () => Uzqr::status(1), 'closed', UzqrTransactionStatus::Expired],
]);

it('never releases when cancel says already paid but the status is not usable', function (mixed $status) {
    bankAnswers(Uzqr::error(3002, 409), $status);
    $transaction = releasableUzqr();

    expect(fn () => app(ReleaseUzqrHold::class)->handle($transaction, 'Release please', staffUser()))->toThrow(ValidationException::class)
        ->and($transaction->refresh()->status)->toBe(UzqrTransactionStatus::Expired)
        ->and($transaction->order->refresh()->payment_status)->toBe(PaymentStatus::Pending);
})->with([
    'status 1' => [fn () => Uzqr::status(1)],
    'status 503' => [fn () => Uzqr::error(9003, 503)],
]);

it('refuses a row that is not releasable without calling the bank', function () {
    Http::fake();
    $transaction = releasableUzqr(['expires_at' => now()->subHours(2)]);

    expect(fn () => app(ReleaseUzqrHold::class)->handle($transaction, 'too early', staffUser()))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('settles a released hold once when the bank later says 2 for an unchanged order (61, 67)', function () {
    $bank = (object) ['status' => 1];
    bankAnswers(Http::response('', 200), fn () => Uzqr::status($bank->status));
    $transaction = releasableUzqr();
    app(ReleaseUzqrHold::class)->handle($transaction, 'Released', staffUser());

    $bank->status = 2;
    $this->travel(24 * 60 + 1)->minutes();
    app(SweepUzqrInvoices::class)->handle();
    Cache::flush();
    app(SweepUzqrInvoices::class)->handle();
    releasePanel($transaction, staffUser())->call('checkStatus');

    $transaction->refresh();
    expect($transaction->status)->toBe(UzqrTransactionStatus::Paid)->and($transaction->settlement)->toBe(UzqrSettlement::Settled)
        ->and($transaction->order->refresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and(OrderPayment::query()->where('provider', 'uzqr')->count())->toBe(1);
    Event::assertDispatchedTimes(OrderPaid::class, 1);
});

it('marks a late payment of a released hold unmatched when the total changed or a new QR was paid (62, 67)', function (string $case) {
    $bank = (object) ['old' => 1];
    Http::fake([
        Uzqr::url('invoice/cancel') => Http::response('', 200),
        Uzqr::url('invoice/create') => Uzqr::created('INVNEW000001'),
        Uzqr::url('invoice/status') => fn ($request) => $request['invoice_id'] === 'INVNEW000001'
            ? Uzqr::status(2, 'INVNEW000001', ['pay_id' => 'PAYNEW00000000000001'])
            : Uzqr::status($bank->old),
    ]);
    $transaction = releasableUzqr();
    app(ReleaseUzqrHold::class)->handle($transaction, 'Released', staffUser());
    $order = $transaction->order->refresh();

    if ($case === 'total changed') {
        $order->forceFill(['total' => $order->total + 100])->save();
    } else {
        // The customer paid a new QR after the release.
        Livewire::test(UzqrPayment::class, ['ulid' => $order->ulid, 'locale' => 'en', 'autoOpen' => true])->call('newQr')->call('refresh');
        expect($order->refresh()->payment_status)->toBe(PaymentStatus::Paid);
    }

    $bank->old = 2;
    $this->travel(24 * 60 + 1)->minutes();
    app(SweepUzqrInvoices::class)->handle();

    $transaction->refresh();
    expect($transaction->status)->toBe(UzqrTransactionStatus::Paid)->and($transaction->settlement)->toBe(UzqrSettlement::Unmatched)
        ->and($transaction->isUnresolvedUnmatched())->toBeTrue()
        ->and(OrderPayment::query()->where('provider', 'uzqr')->count())->toBe($case === 'total changed' ? 0 : 1);
    Event::assertDispatchedTimes(UzqrPaymentUnmatched::class, 1);
    Event::assertDispatchedTimes(OrderPaid::class, $case === 'total changed' ? 0 : 1);
})->with(['total changed', 'new QR paid']);

it('sweeps released holds daily, closes them on 3 and stops after 30 days (61, 67)', function () {
    $bank = (object) ['status' => 1];
    bankAnswers(Http::response('', 200), fn () => Uzqr::status($bank->status));
    $transaction = releasableUzqr();
    app(ReleaseUzqrHold::class)->handle($transaction, 'Released', staffUser());

    expect(app(SweepUzqrInvoices::class)->handle())->toBe(0);

    $this->travel(24 * 60 + 1)->minutes();
    expect(app(SweepUzqrInvoices::class)->handle())->toBe(1)
        ->and($transaction->refresh()->next_check_at->isFuture())->toBeTrue()
        ->and($transaction->status)->toBe(UzqrTransactionStatus::Released);

    $this->travel(30)->days();
    Cache::flush();
    app(SweepUzqrInvoices::class)->handle();
    expect($transaction->refresh()->next_check_at)->toBeNull()->and($transaction->closed_at)->toBeNull();

    expect(releasePanel($transaction, staffUser(Role::SalesManager))->html())->toContain('data-action="uzqr-check"');

    $bank->status = 3;
    $this->travel(5)->seconds();
    releasePanel($transaction, staffUser())->call('checkStatus');
    expect($transaction->refresh()->closed_at)->not->toBeNull()->and($transaction->status)->toBe(UzqrTransactionStatus::Released)
        ->and($transaction->isCheckable())->toBeFalse();
});

it('shows a released hold as ended on /o and issues a new QR without cancelling it again (62, 64)', function () {
    bankAnswers(Http::response('', 200), Uzqr::status(1));
    $transaction = releasableUzqr();
    app(ReleaseUzqrHold::class)->handle($transaction, 'Released', staffUser());
    Http::fake([Uzqr::url('invoice/create') => Uzqr::created('INVNEW000001')]);

    $component = Livewire::test(UzqrPayment::class, ['ulid' => $transaction->order->ulid, 'locale' => 'en', 'autoOpen' => true]);
    expect($component->html())->toContain('data-uzqr-dynamic="ended"')->not->toContain('data-uzqr-qr');

    $component->call('newQr')->assertSet('notice', null);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'invoice/cancel'));
    expect(UzqrTransaction::query()->where('invoice_id', 'INVNEW000001')->value('status'))->toBe(UzqrTransactionStatus::Pending)
        ->and($transaction->refresh()->status)->toBe(UzqrTransactionStatus::Released);
});

it('refuses deleting a TEST order with an open released hold (65, 67)', function () {
    Uzqr::configure('test');
    $transaction = releasableUzqr(test: true);
    $transaction->forceFill(['status' => UzqrTransactionStatus::Released, 'resolution' => UzqrTransaction::RESOLUTION_HOLD_RELEASED, 'resolved_at' => now()])->save();
    $transaction->order->forceFill(['payment_status' => PaymentStatus::Unpaid])->save();

    expect(fn () => app(DeleteTestOrder::class)->handle($transaction->order))->toThrow(ValidationException::class)
        ->and(Order::query()->whereKey($transaction->order_id)->exists())->toBeTrue();

    $transaction->forceFill(['closed_at' => now()])->save();
    app(DeleteTestOrder::class)->handle($transaction->order);
    expect(Order::query()->whereKey($transaction->order_id)->exists())->toBeFalse();
});

it('keeps "released" out of the open / holding statuses (60)', function () {
    expect(UzqrTransactionStatus::Released->isOpen())->toBeFalse()
        ->and(UzqrTransactionStatus::holdingValues())->not->toContain('released');

    foreach (['uz', 'ru', 'en'] as $locale) {
        expectNoBannedWording(json_encode([__('uzqr.admin', [], $locale), __('telegram.uzqr', [], $locale), __('enums.uzqr_transaction_status.released', [], $locale)], JSON_UNESCAPED_UNICODE));
    }
});
