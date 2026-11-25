<?php

namespace App\Http\Controllers\Tma;

use App\Actions\Orders\PlaceOrder;
use App\Data\Orders\CheckoutData;
use App\Data\Orders\OrderOrigin;
use App\Enums\BuyerType;
use App\Enums\DeliveryType;
use App\Enums\PaymentMethod;
use App\Enums\Region;
use App\Models\Cart;
use App\Support\Cart\CartPricer;
use App\Support\Cart\PricedCart;
use App\Support\Orders\CheckoutSettings;
use App\Support\Orders\DeliveryQuote;
use App\Support\Orders\PaymentContext;
use App\Support\Orders\PaymentOptions;
use App\Support\Telegram\MiniApp\TmaCart;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The Mini App checkout (plan 20 §6.1, M4; MA-8, MA-9, MA-10, MA-12, MA-21).
 * 404 while the cart is off.
 *
 * show(): the user's open cart (empty or with lines that can't be bought →
 * back to the cart), the form prefilled from the registration (name; buyer
 * type company with company name and STIR when the user declared a
 * company), delivery pickup / Kokand courier ("free within Kokand") / region
 * (the fee, or "the cost is quoted with the order"), the payment methods of
 * PaymentOptions::forMiniApp() for every buyer type × delivery combination
 * (tma.js shows the ones that fit; the server decides again), and the cart's
 * fingerprint. A combination without any method (an individual with a
 * regional courier) offers "Send as a request" (the cart quote).
 *
 * store(): the verified registration phone only (+888 / stale → the phone
 * screen; any "phone" field is ignored); budgets checked before PlaceOrder,
 * outside its transaction (per user, the shared per-phone checkout budget,
 * the foreign-phone cap) and counted only for a newly placed order; then PlaceOrder with OrderOrigin::telegram() (payment
 * allow-list, cart ownership and phone enforced inside it). A repeated tap
 * reaches the cart converted a moment ago and gets the same order. After
 * placing: the order page.
 */
class CheckoutController extends TmaController
{
    /**
     * Checkout attempts per user per 10 minutes.
     */
    public const PER_USER = 5;

    /**
     * Checkout submissions (placed or not) per user per 10 minutes, counted first.
     */
    public const ATTEMPTS_PER_USER = 20;

    public function show(Request $request, CartPricer $pricer, CheckoutSettings $settings, PaymentOptions $paymentOptions): Response|RedirectResponse
    {
        $this->ensureCart();
        $user = $this->user($request);
        $phone = $this->verifiedPhone($user);

        if ($phone === null) {
            return $this->phoneScreen();
        }

        $cart = TmaCart::current($user);
        $priced = $cart instanceof Cart ? $pricer->price($cart) : null;

        if ($priced === null || $priced->isEmpty() || $priced->hasInvalidLines()) {
            return redirect()->route('tma.cart');
        }

        $declared = filled($user->declared_company);

        return $this->page($request, 'tma.checkout', [
            'title' => __('tma.checkout.title'),
            'back' => route('tma.cart'),
            'priced' => $priced,
            'cartCount' => $priced->count(),
            'phone' => $phone,
            'defaults' => [
                'name' => (string) ($user->name ?? ''),
                'buyer_type' => $declared ? BuyerType::Company->value : BuyerType::Individual->value,
                'company_name' => $declared ? (string) $user->declared_company : '',
                'tin' => $declared ? (string) $user->declared_inn : '',
            ],
            'buyerTypes' => BuyerType::cases(),
            'deliveryTypes' => DeliveryType::cases(),
            'regions' => array_map(fn (Region $region): array => [
                'value' => $region->value,
                'label' => $region->label(),
                'fee' => $settings->regionFee($region),
            ], Region::cases()),
            'methods' => $this->methodMatrix($priced, $settings, $paymentOptions),
            'deliveryFingerprint' => static::deliveryFingerprint($settings),
        ]);
    }

    public function store(Request $request, PlaceOrder $placeOrder, CheckoutSettings $settings): RedirectResponse
    {
        $this->ensureCart();
        $user = $this->user($request);
        $phone = $this->verifiedPhone($user);

        if ($phone === null) {
            return $this->phoneScreen();
        }

        $input = fn (string $key): string => is_string($request->input($key)) ? (string) $request->input($key) : '';
        $form = redirect()->route('tma.checkout');
        $old = $request->only('name', 'buyer_type', 'company_name', 'tin', 'delivery_type', 'region', 'address', 'kokand_confirmed', 'comment', 'payment_method');

        // Every submission counts here first (placed or refused); the budgets below count placed orders only.
        if ($this->overBudget('tma-checkout-attempt:'.$user->id, self::ATTEMPTS_PER_USER, 600)) {
            return $form->withErrors(['form' => __('tma.request.errors.rate_limited')])->withInput($old);
        }

        $budgets = ['tma-checkout:'.$user->id => [self::PER_USER, 600], 'checkout-phone:'.sha1($phone) => [(int) config('checkout.limits.per_phone', 10), 86_400]];

        foreach ($budgets as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return $form->withErrors(['form' => __('tma.request.errors.rate_limited')])->withInput($old);
            }
        }

        if (($refusal = $this->phoneRefusal($phone)) !== null) {
            return $form->withErrors(['form' => $refusal])->withInput($old);
        }

        $cart = TmaCart::forCheckout($user);

        if (! $cart instanceof Cart) {
            return redirect()->route('tma.cart');
        }

        // The region fees the page showed (security review L1): a fee changed since → "prices were updated".
        if (! hash_equals(static::deliveryFingerprint($settings), $input('delivery_fingerprint'))) {
            return $form->withErrors(['fingerprint' => __('checkout.errors.changed')])->withInput($old);
        }

        $delivery = DeliveryQuote::for(DeliveryType::tryFrom($input('delivery_type')) ?? DeliveryType::Pickup, Region::tryFrom($input('region')), $settings);

        try {
            $order = $placeOrder->handle($cart->token, new CheckoutData(
                name: $input('name'),
                phone: $phone,
                buyerType: $input('buyer_type'),
                companyName: $input('company_name'),
                tin: $input('tin'),
                deliveryType: $input('delivery_type'),
                region: $input('region'),
                address: $input('address'),
                kokandConfirmed: $request->boolean('kokand_confirmed'),
                comment: $input('comment'),
                paymentMethod: $input('payment_method'),
                locale: app()->getLocale(),
                fingerprint: PlaceOrder::fingerprintFrom($input('cart_fingerprint'), $delivery),
            ), OrderOrigin::telegram($user));
        } catch (ValidationException $exception) {
            return $form->withErrors($exception->errors())->withInput($old);
        }

        // Budgets count placed orders only: a repeated tap answered with the same order counts once.
        if ($order->wasRecentlyCreated) {
            foreach ($budgets as $key => [, $decay]) {
                RateLimiter::hit($key, $decay);
            }

            $this->recordPhone($phone);
        }

        return redirect()->route('tma.orders.show', ['order' => $order->id])->with('tma_status', 'placed');
    }

    /**
     * A hash of the region delivery fees shown in the region list.
     */
    public static function deliveryFingerprint(CheckoutSettings $settings): string
    {
        return hash('sha256', (string) json_encode(array_map(fn (Region $region): ?int => $settings->regionFee($region), Region::cases())));
    }

    /**
     * Mini App payment methods per "buyer:delivery" combination (manual
     * methods don't depend on the region fee).
     *
     * @return array<string, list<string>> method value => list of "buyer_type:delivery_type"
     */
    protected function methodMatrix(PricedCart $priced, CheckoutSettings $settings, PaymentOptions $paymentOptions): array
    {
        $matrix = [];

        foreach (BuyerType::cases() as $buyerType) {
            foreach (DeliveryType::cases() as $deliveryType) {
                $delivery = DeliveryQuote::for($deliveryType, null, $settings);
                $context = new PaymentContext($deliveryType, $buyerType, $priced->subtotal + $delivery->amount, PlaceOrder::ikpuComplete($priced, $delivery, $settings));

                foreach ($paymentOptions->forMiniApp($context) as $method) {
                    $matrix[$method->value][] = $buyerType->value.':'.$deliveryType->value;
                }
            }
        }

        // Display order of the matrix.
        return array_filter(array_merge(array_fill_keys(array_map(fn (PaymentMethod $method): string => $method->value, PaymentMethod::available()), []), $matrix));
    }
}
