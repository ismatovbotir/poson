<?php

namespace App\Livewire\Site\Checkout;

use App\Actions\Analytics\RecordOrderPlaced;
use App\Actions\Orders\PlaceOrder;
use App\Data\Orders\CheckoutData;
use App\Enums\BuyerType;
use App\Enums\DeliveryType;
use App\Enums\PaymentMethod;
use App\Enums\Region;
use App\Livewire\Site\Concerns\GuardsLeadSubmissions;
use App\Models\Cart;
use App\Rules\UzPhone;
use App\Support\Analytics\Attribution;
use App\Support\Cart\CartPricer;
use App\Support\Cart\CartToken;
use App\Support\Orders\CheckoutSettings;
use App\Support\Orders\DeliveryQuote;
use App\Support\Orders\PaymentContext;
use App\Support\Orders\PaymentOptions;
use App\Support\Phone;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The checkout page /{locale}/checkout (plan 11 v2 §1, security conditions
 * 4, 6, 7, 12, 14, 16, 19; 404 while config site.cart_enabled is off;
 * noindex, disallowed in robots.txt).
 *
 * Everything is re-priced from the cart on every render (CartPricer,
 * Retail only); the page keeps only the typed fields as public strings
 * (enums are parsed with tryFrom() in PlaceOrder) and, locked, the
 * fingerprint of what it showed (lines, prices, delivery), the render time
 * and the attribution. Payment methods offered come from PaymentOptions
 * (Payme / Click only in live mode with webhooks; on receipt only for
 * pickup and Kokand; bank transfer for companies / YaTT).
 *
 * Spam: honeypot "fax_ext", minimum fill time, per-IP (5 / 10 min) and
 * per-phone (10 / day) limits. A double submit returns the same order
 * (PlaceOrder is idempotent per cart). After placing: the /o/{ulid} page.
 */
class Page extends Component
{
    use GuardsLeadSubmissions;

    public string $name = '';

    public string $phone = '';

    public string $buyerType = 'individual';

    public string $companyName = '';

    public string $tin = '';

    public string $deliveryType = 'pickup';

    public string $region = '';

    public string $address = '';

    public bool $kokandConfirmed = false;

    public string $comment = '';

    public string $paymentMethod = '';

    /**
     * Honeypot (see Site\LeadForm).
     */
    public string $fax_ext = '';

    #[Locked]
    public string $fingerprint = '';

    #[Locked]
    public string $locale = '';

    #[Locked]
    public ?string $pageUrl = null;

    /**
     * @var array<string, string>
     */
    #[Locked]
    public array $attribution = [];

    #[Locked]
    public int $renderedAt = 0;

    public function boot(): void
    {
        abort_unless(config('site.cart_enabled'), 404);
    }

    public function mount(): void
    {
        $this->locale = app()->getLocale();
        $this->pageUrl = url()->current();
        $this->attribution = $this->captureAttribution();
        $this->renderedAt = now()->getTimestamp();
    }

    public function placeOrder(PlaceOrder $placeOrder, RecordOrderPlaced $recordOrder): void
    {
        $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'max:32', new UzPhone],
            'paymentMethod' => ['required', 'string', 'max:20'],
        ], [], [
            'name' => __('checkout.fields.name'),
            'phone' => __('checkout.fields.phone'),
            'paymentMethod' => __('checkout.fields.payment_method'),
        ]);

        $token = app(CartToken::class)->current();

        if ($token === null) {
            $this->addError('form', __('checkout.errors.empty'));

            return;
        }

        if ($this->looksLikeBot()) {
            $this->addError('form', __('checkout.errors.try_again'));

            return;
        }

        if ($this->checkoutLimitExceeded((string) Phone::normalize($this->phone))) {
            return;
        }

        // ValidationExceptions (fields, "fingerprint" = prices changed) land in the error bag; the next render re-prices.
        $order = $placeOrder->handle($token, new CheckoutData(
            name: $this->name,
            phone: $this->phone,
            buyerType: $this->buyerType,
            companyName: $this->companyName,
            tin: $this->tin,
            deliveryType: $this->deliveryType,
            region: $this->region,
            address: $this->address,
            kokandConfirmed: $this->kokandConfirmed,
            comment: $this->comment,
            paymentMethod: $this->paymentMethod,
            locale: $this->locale,
            fingerprint: $this->fingerprint,
            attribution: $this->attribution,
            trafficChannel: Attribution::channel($this->attribution)->value,
        ));

        $recordOrder->handle(request(), $this->pageUrl, $this->locale);

        $this->redirect(route('orders.public', ['ulid' => $order->ulid, 'lang' => $this->locale]));
    }

    public function render(CartPricer $pricer, CheckoutSettings $settings, PaymentOptions $paymentOptions): View
    {
        $cart = app(CartToken::class)->cart();
        $priced = $cart instanceof Cart ? $pricer->price($cart) : null;
        $deliveryType = DeliveryType::tryFrom($this->deliveryType) ?? DeliveryType::Pickup;
        $region = Region::tryFrom($this->region);
        $buyerType = BuyerType::tryFrom($this->buyerType) ?? BuyerType::Individual;
        $delivery = DeliveryQuote::for($deliveryType, $region, $settings);
        $methods = [];

        if ($priced !== null && ! $priced->isEmpty()) {
            $this->fingerprint = PlaceOrder::fingerprint($priced, $delivery);
            $total = $priced->subtotal + $delivery->amount;
            $methods = $paymentOptions->for(new PaymentContext($deliveryType, $buyerType, $total, PlaceOrder::ikpuComplete($priced, $delivery, $settings)));

            if (! in_array(PaymentMethod::tryFrom($this->paymentMethod), $methods, true)) {
                $this->paymentMethod = $methods !== [] ? $methods[0]->value : '';
            }
        }

        return view('livewire.site.checkout.page', [
            'cart' => $priced,
            'delivery' => $delivery,
            'deliveryTypeEnum' => $deliveryType,
            'buyerTypeEnum' => $buyerType,
            'methods' => $methods,
            'regionFees' => $settings->regionFeesInSom(),
            'total' => $priced !== null ? $priced->subtotal + $delivery->amount : 0,
            'breadcrumbs' => [
                ['label' => __('site.breadcrumbs.home'), 'url' => route('site.home')],
                ['label' => __('checkout.cart.title'), 'url' => route('site.cart')],
                ['label' => __('checkout.title'), 'url' => null],
            ],
        ])
            ->title(__('checkout.title').' — '.__('site.meta.site_name'))
            ->layoutData([
                'description' => __('checkout.seo_description'),
                'robots' => 'noindex,nofollow',
            ]);
    }

    /**
     * Count a valid submission against the IP and phone budgets; true (with a form error) when exceeded.
     */
    private function checkoutLimitExceeded(string $phone): bool
    {
        $limits = (array) config('checkout.limits');
        $budgets = [
            'checkout-ip:'.sha1((string) request()->ip()) => [(int) ($limits['per_ip'] ?? 5), (int) ($limits['per_ip_minutes'] ?? 10) * 60],
            'checkout-phone:'.sha1($phone) => [(int) ($limits['per_phone'] ?? 10), 86_400],
        ];

        foreach ($budgets as $key => [$max, $decay]) {
            if (RateLimiter::hit($key, $decay) > $max) {
                $this->addError('form', __('checkout.errors.rate_limited', ['seconds' => RateLimiter::availableIn($key)]));

                return true;
            }
        }

        return false;
    }
}
