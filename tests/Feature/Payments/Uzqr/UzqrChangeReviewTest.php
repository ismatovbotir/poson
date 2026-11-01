<?php

use App\Actions\Payments\Uzqr\ResolveUzqrUnmatched;
use App\Actions\Payments\Uzqr\StartUzqrPayment;
use App\Actions\Payments\Uzqr\UzqrPaymentRefused;
use App\Enums\PaymentMethod;
use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Enums\UzqrTransactionStatus;
use App\Events\Orders\OrderPaid;
use App\Events\Uzqr\UzqrIntegrationProblem;
use App\Livewire\Site\Orders\UzqrPayment;
use App\Models\UzqrTransaction;
use App\Services\Payments\Uzqr\UzqrAlerts;
use App\Support\Orders\PaymentOptions;
use App\Support\Qr\QrRenderer;
use App\Support\Secrets;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\Uzqr;

/**
 * Change review 2026-11-01 (plan 11e): M1–M3, L2–L5, poll limit and abuse tests 8, 9, 16, 21.
 */
beforeEach(function () {
    Uzqr::configure();
    Event::fake([OrderPaid::class, UzqrIntegrationProblem::class]);
});

function reviewDialog($order)
{
    return Livewire::test(UzqrPayment::class, ['ulid' => $order->ulid, 'locale' => 'en', 'autoOpen' => true]);
}

/**
 * An order with a pending invoice; $expired = past its expiry (bank not final yet).
 */
function reviewPending(bool $expired = false): UzqrTransaction
{
    $order = Uzqr::order(['payment_status' => PaymentStatus::Pending]);

    return UzqrTransaction::factory()->create([
        'order_id' => $order->id,
        'amount' => $order->total,
        'invoice_id' => 'INV000000001',
        'qr_text' => 'https://qr.example.uz/pay/abc123',
        'expires_at' => $expired ? now()->subMinute() : now()->addHours(20),
    ]);
}

function sentTo(string $endpoint): int
{
    return collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), $endpoint))->count();
}

it('returns no PNG when the renderer fails, hides "Save PNG" and still shows the SVG (M1, abuse 21)', function () {
    app()->instance(QrRenderer::class, new class extends QrRenderer
    {
        protected function pngWriter(): Writer
        {
            throw new Error('Call to undefined function imagecreatetruecolor()');
        }
    });
    $transaction = reviewPending();

    expect(app(QrRenderer::class)->pngDataUri('https://qr.example.uz/pay/abc123'))->toBeNull();

    $html = $this->get(route('orders.public', ['ulid' => $transaction->order->ulid, 'pay' => 'uzqr', 'lang' => 'en']))->assertOk()->getContent();

    expect($html)->toContain('data:image/svg+xml;base64,')->not->toContain('data-uzqr-png')->not->toContain('data:image/png');
});

it('renders the QR once per invoice, not on every poll (M2)', function () {
    Http::fake([Uzqr::url('invoice/status') => Uzqr::status(1)]);
    $counter = new class extends QrRenderer
    {
        public int $svg = 0;

        public int $png = 0;

        public function svgDataUri(string $text): string
        {
            $this->svg++;

            return parent::svgDataUri($text);
        }

        public function pngDataUri(string $text): ?string
        {
            $this->png++;

            return parent::pngDataUri($text);
        }
    };
    app()->instance(QrRenderer::class, $counter);
    $transaction = reviewPending();

    $component = reviewDialog($transaction->order);
    $this->travel(4)->seconds();
    $component->call('refresh');
    $this->travel(4)->seconds();
    $component->call('refresh');

    expect($counter->svg)->toBe(1)->and($counter->png)->toBe(1)
        ->and($component->html())->toContain('data:image/svg+xml;base64,')->toContain('data-uzqr-png');
});

it('refuses newQr while the QR is still payable: no cancel call (M3)', function (bool $creating) {
    Http::fake();
    $transaction = reviewPending();

    if ($creating) {
        $transaction->forceFill(['status' => UzqrTransactionStatus::Creating, 'invoice_id' => null, 'expires_at' => null])->save();
    }

    reviewDialog($transaction->order)->call('newQr')->assertSet('notice', 'qr_still_valid');

    Http::assertNothingSent();
    expect($transaction->refresh()->status)->toBe($creating ? UzqrTransactionStatus::Creating : UzqrTransactionStatus::Pending)
        ->and($transaction->cancel_requested_at)->toBeNull()
        ->and(UzqrTransaction::count())->toBe(1);
})->with(['pending' => false, 'creating' => true]);

it('never lets a crafted newQr loop cancel a live QR or burn the order quota (abuse 9)', function () {
    Http::fake([Uzqr::url('invoice/create') => Uzqr::created('INV2')]);
    $transaction = reviewPending();
    $component = reviewDialog($transaction->order);

    foreach (range(1, 15) as $i) {
        $component->call('newQr');
    }

    Http::assertNothingSent();
    expect($transaction->refresh()->status)->toBe(UzqrTransactionStatus::Pending)->and($transaction->cancel_requested_at)->toBeNull();

    // The customer can still get a new QR once this one ends.
    $transaction->forceFill(['status' => UzqrTransactionStatus::Failed, 'closed_at' => now()])->save();
    $transaction->order->forceFill(['payment_status' => PaymentStatus::Unpaid])->save();

    $component->call('newQr')->assertSet('notice', null);
    expect(UzqrTransaction::query()->where('invoice_id', 'INV2')->exists())->toBeTrue();
});

it('sends one bank cancel for two newQr within 60 s and resends after a minute (M3)', function () {
    Http::fake([
        Uzqr::url('invoice/cancel') => Http::response('', 200),
        Uzqr::url('invoice/status') => Uzqr::status(1),
    ]);
    $transaction = reviewPending(expired: true);
    $component = reviewDialog($transaction->order);

    $component->call('newQr')->assertSet('notice', 'cancel_pending');
    $this->travel(30)->seconds();
    $component->call('newQr')->assertSet('notice', 'cancel_pending');

    expect(sentTo('invoice/cancel'))->toBe(1);

    $this->travel(31)->seconds();
    $component->call('newQr');

    expect(sentTo('invoice/cancel'))->toBe(2)->and(UzqrTransaction::count())->toBe(1);
});

it('caps newQr amplification: 10 per IP a minute, at most one cancel, coalesced status calls (abuse 8)', function () {
    Http::fake([
        Uzqr::url('invoice/cancel') => Http::response('', 200),
        Uzqr::url('invoice/status') => Uzqr::status(1),
    ]);
    config(['uzqr.limits.upstream_per_second' => 50]);
    $transaction = reviewPending(expired: true);
    $component = reviewDialog($transaction->order);

    foreach (range(1, 10) as $i) {
        $component->call('newQr');
    }

    // cancel_pending never burns the per-order new-QR cap (code review #3).
    $component->assertSet('notice', 'cancel_pending');
    expect(sentTo('invoice/cancel'))->toBe(1)->and(sentTo('invoice/status'))->toBeLessThanOrEqual(2)
        ->and(sentTo('invoice/create'))->toBe(0);

    $component->call('newQr')->assertSet('notice', 'too_many_short');
    expect(sentTo('invoice/cancel'))->toBe(1);
});

it('allows 60 polls a minute per order, then stops calling the bank', function () {
    Http::fake([Uzqr::url('invoice/status') => Uzqr::status(1)]);
    config(['uzqr.limits.refresh_per_ip_minute' => 1000]);
    $transaction = reviewPending();
    $component = reviewDialog($transaction->order);

    foreach (range(1, 61) as $i) {
        $component->call('refresh');
    }

    $component->assertSet('notice', 'too_many_short');
    expect(config('uzqr.limits.refresh_per_order_minute'))->toBe(60);
});

it('keeps the notice locked against client updates (L3)', function () {
    reviewDialog(Uzqr::order())->set('notice', 'paid');
})->throws(CannotUpdateLockedPropertyException::class);

it('hides dynamic UzQR when UZQR_MODE=prod comes from .env but the go-live gate is closed (abuse 16)', function () {
    Http::fake();
    Uzqr::configure('prod', gateOpen: false);
    uzqrUploaded();
    $order = Uzqr::order();

    expect(Secrets::get('uzqr.mode'))->toBe('prod')
        ->and(app(PaymentOptions::class)->forCheckout($order->paymentContext()))->not->toContain(PaymentMethod::UzqrDynamic)
        ->and(fn () => app(StartUzqrPayment::class)->handle($order))->toThrow(fn (UzqrPaymentRefused $e) => expect($e->reason)->toBe('unavailable'));

    reviewDialog($order)->call('open')->assertSet('notice', 'unavailable');
    Http::assertNothingSent();
    expect(UzqrTransaction::count())->toBe(0);
});

it('takes the alert dedupe slot only after the commit (L4)', function () {
    $alerts = app(UzqrAlerts::class);

    try {
        DB::transaction(function () use ($alerts): void {
            $alerts->problem('expired_unconfirmed', PaymentMode::Prod);

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
        // expected
    }

    Event::assertNotDispatched(UzqrIntegrationProblem::class);

    DB::transaction(fn () => $alerts->problem('expired_unconfirmed', PaymentMode::Prod));

    expect($alerts->problem('expired_unconfirmed', PaymentMode::Prod))->toBeFalse();
    Event::assertDispatchedTimes(UzqrIntegrationProblem::class, 1);
});

it('refuses a resolution reference over 100 or a note over 500 characters instead of cutting them (L5)', function () {
    $admin = staffUser();
    $order = Uzqr::order(['payment_status' => PaymentStatus::Pending]);
    $transaction = UzqrTransaction::factory()->unmatched('method_changed')->create(['order_id' => $order->id, 'amount' => $order->total]);
    $resolve = app(ResolveUzqrUnmatched::class);

    expect(fn () => $resolve->handle($transaction, 'refunded_externally', str_repeat('R', 101), 'ok', $admin))->toThrow(ValidationException::class)
        ->and(fn () => $resolve->handle($transaction, 'refunded_externally', 'R-1', str_repeat('n', 501), $admin))->toThrow(ValidationException::class)
        ->and($transaction->refresh()->resolved_at)->toBeNull();

    $resolved = $resolve->handle($transaction, 'refunded_externally', str_repeat('R', 100), str_repeat('n', 500), $admin);
    expect(mb_strlen($resolved->resolution_reference))->toBe(100)->and(mb_strlen($resolved->resolution_note))->toBe(500);
});
