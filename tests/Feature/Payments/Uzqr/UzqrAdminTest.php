<?php

use App\Actions\Settings\SaveIntegrationSettings;
use App\Actions\Settings\SetSecret;
use App\Actions\Settings\TestIntegrationConnection;
use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Enums\UzqrTransactionStatus;
use App\Events\Uzqr\UzqrSettingsChanged;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Orders\Show;
use App\Livewire\Admin\Orders\UzqrPanel;
use App\Livewire\Admin\Payments\Index as PaymentsIndex;
use App\Livewire\Admin\Settings\Integrations;
use App\Models\IntegrationSecret;
use App\Models\UzqrTransaction;
use App\Services\Payments\Uzqr\UzqrConfig;
use App\Support\Secrets;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Uzqr;

beforeEach(function () {
    Uzqr::configure();
});

function uzqrPanel($order, $user)
{
    return Livewire::actingAs($user)->test(UzqrPanel::class, ['orderId' => $order->id]);
}

it('shows the UzQR panel on the order page to payment viewers, never to the service engineer', function () {
    $order = Uzqr::order();
    UzqrTransaction::factory()->create(['order_id' => $order->id, 'amount' => $order->total, 'card_last4' => null]);

    Livewire::actingAs(staffUser(Role::SalesManager))->test(Show::class, ['order' => $order])->assertSeeLivewire(UzqrPanel::class);
    Livewire::actingAs(staffUser(Role::ServiceEngineer))->test(Show::class, ['order' => $order])->assertDontSeeLivewire(UzqrPanel::class);

    uzqrPanel($order, staffUser(Role::ServiceEngineer))->assertForbidden();
});

it('never shows the QR of a real order or the payer phone in admin', function () {
    $order = Uzqr::order(['payment_status' => PaymentStatus::Pending]);
    UzqrTransaction::factory()->create(['order_id' => $order->id, 'amount' => $order->total, 'qr_text' => 'https://qr.example.uz/REALQR', 'card_last4' => '1234', 'card_type' => 2]);

    $html = uzqrPanel($order, staffUser())->html();

    expect($html)->not->toContain('data-uzqr-test-qr')->not->toContain('REALQR')->not->toContain('+998');
});

it('checks the status now, at most 6 times a minute per user', function () {
    Http::fake([Uzqr::url('invoice/status') => Uzqr::status(1)]);
    $order = Uzqr::order(['payment_status' => PaymentStatus::Pending]);
    $transaction = UzqrTransaction::factory()->create(['order_id' => $order->id, 'amount' => $order->total, 'invoice_id' => 'INV000000001']);
    $panel = uzqrPanel($order, staffUser(Role::SalesManager));
    config(['uzqr.limits.upstream_per_second' => 50]);

    foreach (range(1, 7) as $i) {
        Cache::lock('uzqr:poll:'.$transaction->id)->forceRelease();
        $panel->call('checkStatus');
    }

    Http::assertSentCount(6);
    $panel->assertDispatched('toast');
});

it('lets sales cancel a QR (released only after the bank confirms) but not resolve unmatched payments', function () {
    Http::fake([
        Uzqr::url('invoice/cancel') => Http::response('', 200),
        Uzqr::url('invoice/status') => Uzqr::status(3),
    ]);
    $order = Uzqr::order(['payment_status' => PaymentStatus::Pending]);
    $transaction = UzqrTransaction::factory()->create(['order_id' => $order->id, 'amount' => $order->total, 'invoice_id' => 'INV000000001']);
    $unmatched = UzqrTransaction::factory()->unmatched()->create(['order_id' => $order->id]);
    $sales = staffUser(Role::SalesManager);

    uzqrPanel($order, $sales)->call('cancelQr');

    expect($transaction->refresh()->status)->toBe(UzqrTransactionStatus::Failed)->and($order->refresh()->payment_status)->toBe(PaymentStatus::Unpaid);

    uzqrPanel($order, $sales)->call('startResolve', $unmatched->id)->assertForbidden();
});

it('lets the Admin resolve an unmatched payment from the panel', function () {
    $order = Uzqr::order(['payment_status' => PaymentStatus::Pending]);
    $unmatched = UzqrTransaction::factory()->unmatched('method_changed')->create(['order_id' => $order->id, 'amount' => $order->total]);

    uzqrPanel($order, staffUser())
        ->call('startResolve', $unmatched->id)
        ->set('resolution', 'refunded_externally')
        ->set('resolutionReference', 'BANK-1')
        ->set('resolutionNote', 'Returned')
        ->call('resolve')
        ->assertHasNoErrors();

    expect($unmatched->refresh()->resolved_at)->not->toBeNull();
});

it('starts a test payment only for TEST orders in test mode with payments.test (Livewire POST)', function () {
    Uzqr::configure('test');
    Http::fake([Uzqr::url('invoice/create', 'test') => Uzqr::created('INVT1', 'https://qr.example.uz/TESTQR')]);
    $order = Uzqr::order(test: true);

    $panel = uzqrPanel($order, staffUser())->call('testPay');
    expect(UzqrTransaction::query()->where('order_id', $order->id)->value('mode'))->toBe(PaymentMode::Test)
        ->and($panel->html())->toContain('data-uzqr-test-qr');

    $real = Uzqr::order();
    uzqrPanel($real, staffUser())->call('testPay')->assertNotFound();
    expect(UzqrTransaction::query()->where('order_id', $real->id)->exists())->toBeFalse();
    uzqrPanel($order, staffUser(Role::SalesManager))->assertForbidden();
});

it('hides test pay without a separate test host (404)', function () {
    Uzqr::configure('test', testHost: false);
    $order = Uzqr::order(test: true);

    uzqrPanel($order, staffUser())->call('testPay')->assertNotFound();
});

it('lists unmatched payments and fiscal receipts in the payments ledger; the Admin ticks a receipt off', function () {
    $order = Uzqr::order();
    UzqrTransaction::factory()->unmatched()->create(['order_id' => $order->id]);
    $settled = UzqrTransaction::factory()->settled()->create(['order_id' => Uzqr::order()->id]);

    Livewire::actingAs(staffUser(Role::SalesManager))->test(PaymentsIndex::class)
        ->assertSee(__('uzqr.admin.attention_title'))
        ->assertDontSee(__('uzqr.admin.receipt_issued'))
        ->call('confirmReceipt', $settled->id)->assertForbidden();

    Livewire::actingAs(staffUser())->test(PaymentsIndex::class)->call('confirmReceipt', $settled->id);

    expect($settled->refresh()->receipt_confirmed_at)->not->toBeNull();
});

describe('integrations', function () {
    it('shows the UzQR section with hosts and gate, never the key', function () {
        IntegrationSecret::factory()->withValue('uzqr.api_key', 'fake-uzqr-db-key-SECRETVALUE-1234')->create();
        freshSecrets(['services.uzqr.inn' => Uzqr::INN]);
        config(['uzqr.go_live.answered' => []]);
        confirmAdminPassword();

        $component = Livewire::actingAs(staffUser())->test(Integrations::class);
        $payload = $component->html().json_encode($component->snapshot);

        expect($payload)->toContain('data-uzqr-panel')->toContain('data-uzqr-gate')->toContain('api.uzqr.example.uz')
            ->not->toContain('SECRETVALUE');
    });

    it('refuses prod while the go-live gate is closed and test mode without a test host', function () {
        config(['uzqr.go_live.answered' => ['B1', 'B2']]);
        freshSecrets(['services.uzqr.inn' => Uzqr::INN, 'services.uzqr.api_key' => Uzqr::KEY]);

        expect(fn () => app(SaveIntegrationSettings::class)->validate(['uzqr.mode' => 'prod']))->toThrow(ValidationException::class);

        config(['uzqr.go_live.answered' => ['B1', 'B2', 'B3', 'B5']]);
        expect(app(SaveIntegrationSettings::class)->validate(['uzqr.mode' => 'prod']))->toBe(['uzqr.mode' => 'prod']);

        config(['uzqr.base_urls.test' => null]);
        expect(fn () => app(SaveIntegrationSettings::class)->validate(['uzqr.mode' => 'test']))->toThrow(ValidationException::class);

        freshSecrets();
        expect(fn () => app(SaveIntegrationSettings::class)->validate(['uzqr.inn' => '12345']))->toThrow(ValidationException::class)
            ->and(app(SaveIntegrationSettings::class)->validate(['uzqr.inn' => '31234567890123']))->toBe(['uzqr.inn' => '31234567890123']);
    });

    it('rejects a test key equal to the live key and alerts on UzQR setting changes', function () {
        Event::fake([UzqrSettingsChanged::class]);
        freshSecrets();
        $admin = staffUser();

        app(SetSecret::class)->handle('uzqr.api_key', 'fake-uzqr-live-key-abcdef0123456', $admin, null);
        expect(fn () => app(SetSecret::class)->handle('uzqr.test_api_key', 'fake-uzqr-live-key-abcdef0123456', $admin, null))->toThrow(ValidationException::class);

        app(SaveIntegrationSettings::class)->handle(['uzqr.inn' => Uzqr::INN], $admin, null);

        Event::assertDispatchedTimes(UzqrSettingsChanged::class, 2);
        Event::assertDispatched(UzqrSettingsChanged::class, fn ($event) => $event->keys === ['uzqr.api_key']);
        expect(Secrets::get('uzqr.inn'))->toBe(Uzqr::INN);
    });

    it('tests the connection: 400 = ok and clears the auth flag, 401 = rejected; outcome-only log', function () {
        $admin = staffUser();
        app(UzqrConfig::class)->flagAuthFailed(PaymentMode::Prod);
        Http::fakeSequence(Uzqr::url('invoice/create'))->pushResponse(Uzqr::error(2001, 400))->pushResponse(Uzqr::error(1001, 401));

        $ok = app(TestIntegrationConnection::class)->handle($admin, 'uzqr');
        expect($ok->ok)->toBeTrue()->and(app(UzqrConfig::class)->authFailed(PaymentMode::Prod))->toBeFalse();

        $bad = app(TestIntegrationConnection::class)->handle($admin, 'uzqr');
        expect($bad->ok)->toBeFalse()->and($bad->code)->toBe('unauthorized')->and($bad->message())->not->toContain('Bank');
    });

    it('asks for confirmation when switching the UzQR mode with open invoices', function () {
        UzqrTransaction::factory()->create();
        freshSecrets(['services.uzqr.inn' => Uzqr::INN, 'services.uzqr.api_key' => Uzqr::KEY]);
        confirmAdminPassword();

        Livewire::actingAs(staffUser())->test(Integrations::class)
            ->set('form.values.uzqr.mode', 'prod')
            ->call('save', 'uzqr')
            ->assertDispatched('confirm');
    });
});

it('counts unmatched UzQR payments on the dashboard for payment viewers', function () {
    UzqrTransaction::factory()->unmatched()->create();

    Livewire::actingAs(staffUser(Role::SalesManager))->test(Dashboard::class)->assertSee(__('uzqr.admin.unmatched_count', ['count' => 1]));
    Livewire::actingAs(staffUser(Role::ContentManager))->test(Dashboard::class)->assertDontSee(__('uzqr.admin.unmatched_count', ['count' => 1]));
});
