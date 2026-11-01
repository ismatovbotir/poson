<?php

namespace App\Support\Orders;

use App\Enums\DeliveryType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentMode;
use App\Models\Order;
use App\Services\Payments\Uzqr\UzqrConfig;
use App\Support\CompanySettings;
use App\Support\Integrations\IntegrationRegistry;
use App\Support\Orders\Contracts\PaymentHold;
use App\Support\Secrets;
use Illuminate\Support\Facades\Route;

/**
 * The payment method matrix (plan 11 v2 §1.3, security conditions 1, 7 and
 * 18), the one rule used by checkout, PlaceOrder, ChangeOrderPaymentMethod,
 * the /o pay button and (11c/11d) the webhooks:
 *
 * - payme / click: the provider is live for this kind of order (real
 *   orders: mode prod, credentials and the webhook route present, APP_URL
 *   public https; TEST orders: mode test with the test credentials), 0 <
 *   total ≤ max_online_total, and every line (plus a fixed paid delivery)
 *   has an IKPU while require_ikpu_online is on;
 * - uzqr_static: the UzQR image is uploaded, total > 0;
 * - on_receipt: pickup or courier in Kokand only;
 * - bank_transfer: companies and sole proprietors (YaTT), with complete
 *   company requisites (legal name, STIR, bank, MFO, account);
 * - uzqr_dynamic (plan 11e): the UzQR mode matches the order (real order:
 *   prod behind the go-live gate; TEST order: test with a separate test
 *   host), INN + key + https base URL configured, the UzqrHold registered,
 *   no auth-failed flag, fewer open invoices than checkout.uzqr_max_open
 *   and 0 < total ≤ max_online_total. No webhook, APP_URL or IKPU needed;
 * - forCheckout() (public checkout) hides uzqr_static while uzqr_dynamic
 *   is offered (static = fallback; staff can still pick it in admin).
 *
 * Until plans 11c/11d register the webhook routes, Payme and Click are
 * never offered.
 */
class PaymentOptions
{
    /**
     * Webhook route that must exist before a provider can be offered.
     */
    public const WEBHOOK_ROUTES = [
        'payme' => 'webhooks.payme',
        'click' => 'webhooks.click.prepare',
    ];

    public function __construct(protected CheckoutSettings $settings, protected CompanySettings $company, protected UzqrConfig $uzqr) {}

    /**
     * Methods allowed for the order, in display order.
     *
     * @return list<PaymentMethod>
     */
    public function for(PaymentContext $context): array
    {
        return array_values(array_filter(PaymentMethod::available(), fn (PaymentMethod $method): bool => $this->rejection($method, $context) === null));
    }

    /**
     * Methods the public checkout offers: as for(), but the static UzQR code
     * is only a fallback while dynamic UzQR is offered (plan 11e, U3).
     *
     * @return list<PaymentMethod>
     */
    public function forCheckout(PaymentContext $context): array
    {
        $methods = $this->for($context);

        if (in_array(PaymentMethod::UzqrDynamic, $methods, true)) {
            $methods = array_values(array_filter($methods, fn (PaymentMethod $method): bool => $method !== PaymentMethod::UzqrStatic));
        }

        return $methods;
    }

    /**
     * A stored order may be paid online now: payable (Order::isPayable()),
     * its method is online and still allowed by the matrix. Used by /o and
     * the pay button; the webhooks (11c/11d) use the same rule.
     */
    public function canPayOnline(Order $order): bool
    {
        return $order->isPayable()
            && $order->payment_method->isOnline()
            && $this->allows($order->payment_method, $order->paymentContext());
    }

    public function allows(PaymentMethod $method, PaymentContext $context): bool
    {
        return $this->rejection($method, $context) === null;
    }

    /**
     * Why a method can't be used (a short reason code), or null when it can.
     */
    public function rejection(PaymentMethod $method, PaymentContext $context): ?string
    {
        if ($method->isReserved()) {
            return 'reserved';
        }

        if ($method === PaymentMethod::UzqrDynamic) {
            return match (true) {
                ! $this->uzqr->canStart($context->isTest ? PaymentMode::Test : PaymentMode::Prod) => 'provider',
                $context->total <= 0 || $context->total > $this->settings->maxOnlineTotal() => 'online_total',
                $this->uzqr->openCapReached() => 'uzqr_busy',
                default => null,
            };
        }

        if ($method->isOnline()) {
            if (! $this->providerReady($method, $context->isTest)) {
                return 'provider';
            }

            if ($context->total <= 0 || $context->total > $this->settings->maxOnlineTotal()) {
                return 'online_total';
            }

            if ($this->settings->requireIkpuOnline() && ! $context->ikpuComplete) {
                return 'ikpu';
            }

            return null;
        }

        return match ($method) {
            PaymentMethod::UzqrStatic => $context->total > 0 && $this->settings->hasUzqr() ? null : 'uzqr',
            PaymentMethod::OnReceipt => in_array($context->deliveryType, [DeliveryType::Pickup, DeliveryType::CourierKokand], true) ? null : 'delivery',
            PaymentMethod::BankTransfer => match (true) {
                ! $context->buyerType->isBusiness() => 'buyer',
                ! $this->requisitesComplete() => 'requisites',
                default => null,
            },
            default => 'reserved',
        };
    }

    /**
     * The provider accepts payments for this kind of order: a real order
     * only in prod, a TEST order only in test (security condition 1).
     */
    public function providerReady(PaymentMethod $method, bool $isTest): bool
    {
        $provider = $method->value;
        $route = self::WEBHOOK_ROUTES[$provider] ?? null;

        if ($route === null || ! Route::has($route) || ! IntegrationRegistry::webhooksArePublic() || ! static::hasHold($provider)) {
            return false;
        }

        $mode = Secrets::get("{$provider}.mode") === PaymentMode::Prod->value ? PaymentMode::Prod : PaymentMode::Test;

        if ($mode !== ($isTest ? PaymentMode::Test : PaymentMode::Prod)) {
            return false;
        }

        $keys = match ($provider) {
            'payme' => $isTest ? ['payme.merchant_id', 'payme.test_key'] : ['payme.merchant_id', 'payme.key'],
            'click' => $isTest
                ? ['click.merchant_id', 'click.test_service_id', 'click.test_merchant_user_id', 'click.test_secret_key']
                : ['click.merchant_id', 'click.service_id', 'click.merchant_user_id', 'click.secret_key'],
            default => [],
        };

        foreach ($keys as $key) {
            if (! Secrets::isConfigured($key)) {
                return false;
            }
        }

        return $keys !== [];
    }

    /**
     * The provider's payment hold class is registered (config
     * checkout.payment_holds) and implements PaymentHold: without it an
     * order paid through the provider could be edited mid-payment, so the
     * provider is not offered (security review M3, fail closed).
     */
    public static function hasHold(string $provider): bool
    {
        $class = config('checkout.payment_holds.'.$provider);

        return is_string($class) && class_exists($class) && is_subclass_of($class, PaymentHold::class);
    }

    /**
     * Seller requisites needed on a bank invoice.
     */
    public function requisitesComplete(): bool
    {
        $legal = $this->company->legal();

        foreach (['legal_name', 'inn', 'bank', 'mfo', 'account'] as $field) {
            if (($legal[$field] ?? '') === '') {
                return false;
            }
        }

        return true;
    }
}
