<?php

use App\Enums\PaymentMode;
use App\Events\Uzqr\UzqrIntegrationProblem;
use App\Events\Uzqr\UzqrPaymentUnmatched;
use App\Events\Uzqr\UzqrSettingsChanged;
use App\Models\UzqrTransaction;
use App\Services\Payments\Uzqr\UzqrAlerts;
use Illuminate\Support\Facades\Event;
use Tests\Support\Uzqr;

it('alerts finance + sales about an unmatched payment without payer data, pay_id or card digits', function () {
    $transaction = UzqrTransaction::factory()->unmatched('amount_mismatch')->create(['order_id' => Uzqr::order(test: true)->id, 'card_last4' => '1234']);

    $event = new UzqrPaymentUnmatched($transaction);
    $html = $event->telegramMessage('uz')->html;

    expect($event->telegramChats())->toBe(['finance', 'sales'])
        ->and($html)->toContain($transaction->order->number)->toContain('TEST')->toContain(__('uzqr.unmatched_reasons.amount_mismatch', [], 'uz'))
        ->not->toContain($transaction->pay_id)->not->toContain('1234')->not->toContain('+998')->not->toContain($transaction->invoice_id);
});

it('dedupes integration problems hourly per problem and mode', function () {
    Event::fake([UzqrIntegrationProblem::class]);
    $alerts = app(UzqrAlerts::class);

    expect($alerts->problem('auth_failed', PaymentMode::Prod))->toBeTrue()
        ->and($alerts->problem('auth_failed', PaymentMode::Prod))->toBeFalse()
        ->and($alerts->problem('auth_failed', PaymentMode::Test))->toBeTrue();

    $this->travel(61)->minutes();
    expect($alerts->problem('auth_failed', PaymentMode::Prod))->toBeTrue();
    Event::assertDispatchedTimes(UzqrIntegrationProblem::class, 3);

    $message = (new UzqrIntegrationProblem('auth_failed', PaymentMode::Prod))->telegramMessage('ru')->html;
    expect($message)->not->toContain(Uzqr::KEY)->toContain(e(__('uzqr.problems.auth_failed', [], 'ru')));
});

it('lists changed UzQR setting names only', function () {
    $html = (new UzqrSettingsChanged(['uzqr.api_key', 'uzqr.mode']))->telegramMessage('en')->html;

    expect($html)->toContain(__('admin.settings.integrations.fields.uzqr_api_key', [], 'en'))->not->toContain('fake');
});

it('labels the methods distinctly and keeps all UzQR copy brand-safe in every locale', function () {
    foreach (['uz', 'ru', 'en'] as $locale) {
        expect(__('enums.payment_method.uzqr_dynamic', [], $locale))->not->toBe(__('enums.payment_method.uzqr_static', [], $locale));

        expectNoBannedWording(json_encode([
            trans('uzqr', [], $locale),
            trans('telegram.uzqr', [], $locale),
            trans('admin.settings.integrations.uzqr', [], $locale),
        ], JSON_UNESCAPED_UNICODE));
    }
});
