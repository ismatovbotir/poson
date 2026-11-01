<?php

namespace App\Actions\Orders;

use App\Data\Orders\CheckoutData;
use App\Enums\BuyerType;
use App\Enums\DeliveryType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PriceLevel;
use App\Enums\ProductUnit;
use App\Enums\Region;
use App\Enums\TrafficChannel;
use App\Events\Orders\OrderPlaced;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Rules\UzPhone;
use App\Support\Analytics\Attribution;
use App\Support\Cart\CartPricer;
use App\Support\Cart\PricedCart;
use App\Support\Cart\PricedLine;
use App\Support\Orders\CheckoutSettings;
use App\Support\Orders\DeliveryQuote;
use App\Support\Orders\OrderNumber;
use App\Support\Orders\PaymentContext;
use App\Support\Orders\PaymentOptions;
use App\Support\Orders\TaxId;
use App\Support\Phone;
use App\Support\Quotes\WorkingDays;
use App\Support\Vat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Place an order from the visitor's cart (plan 11 v2 §1, security
 * conditions 4, 6, 7, 11, 12, 18, 19).
 *
 * 1. Validate the typed fields (enums with tryFrom, UzPhone, STIR / PINFL
 *    per buyer type, Kokand address confirmation, string lengths).
 * 2. In one short transaction: lock the cart row. A cart already converted
 *    returns its order (idempotent: a double submit gets the same ULID).
 *    Reprice it (CartPricer, Retail only), refuse invalid lines and a
 *    fingerprint other than the one the page showed ("prices or
 *    availability were updated"), apply the caps (lines, qty, max total),
 *    the delivery quote (fixed fee or billed separately) and the payment
 *    matrix (PaymentOptions, incl. online cap and IKPU). Then number, insert
 *    the order and its line snapshots, link a customer only when the 9-digit
 *    STIR and the phone both match the same customer (prices never change),
 *    and mark the cart converted.
 * 3. OrderPlaced after commit (Telegram sales chat).
 */
class PlaceOrder
{
    public function __construct(
        protected CartPricer $pricer,
        protected CheckoutSettings $settings,
        protected PaymentOptions $paymentOptions,
        protected LinkOrderCustomer $linkCustomer,
        protected LogOrderActivity $log,
    ) {}

    /**
     * The fingerprint of a priced cart with a delivery choice (what the page shows).
     */
    public static function fingerprint(PricedCart $cart, DeliveryQuote $delivery): string
    {
        return hash('sha256', $cart->fingerprint.'|'.$delivery->pricing->value.'|'.$delivery->amount);
    }

    /**
     * @throws ValidationException
     */
    public function handle(string $cartToken, CheckoutData $data): Order
    {
        if (! config('site.cart_enabled')) {
            throw ValidationException::withMessages(['form' => __('checkout.cart.errors.disabled')]);
        }

        $input = $this->validated($data);

        $result = DB::transaction(function () use ($cartToken, $data, $input): array {
            $cart = Cart::query()->where('token', $cartToken)->lockForUpdate()->first();

            if (! $cart instanceof Cart) {
                throw ValidationException::withMessages(['form' => __('checkout.errors.empty')]);
            }

            if ($cart->converted_order_id !== null) {
                $existing = Order::query()->find($cart->converted_order_id);

                if ($existing instanceof Order) {
                    return [$existing, false];
                }

                throw ValidationException::withMessages(['form' => __('checkout.cart.errors.closed')]);
            }

            $priced = $this->pricer->price($cart);

            if ($priced->isEmpty()) {
                throw ValidationException::withMessages(['form' => __('checkout.errors.empty')]);
            }

            if ($priced->hasInvalidLines()) {
                throw ValidationException::withMessages(['form' => __('checkout.errors.invalid_lines')]);
            }

            if ($priced->count() > Order::MAX_LINES) {
                throw ValidationException::withMessages(['form' => __('checkout.cart.errors.too_many_lines', ['max' => Order::MAX_LINES])]);
            }

            $delivery = DeliveryQuote::for($input['delivery_type'], $input['region'], $this->settings);

            if (! hash_equals(static::fingerprint($priced, $delivery), $data->fingerprint)) {
                throw ValidationException::withMessages(['fingerprint' => __('checkout.errors.changed')]);
            }

            $total = $priced->subtotal + $delivery->amount;

            if ($total > $this->settings->maxTotal()) {
                throw ValidationException::withMessages(['form' => __('checkout.errors.total_too_high')]);
            }

            $ikpuComplete = static::ikpuComplete($priced, $delivery, $this->settings);
            $context = new PaymentContext($input['delivery_type'], $input['buyer_type'], $total, $ikpuComplete);

            if (! in_array($input['payment_method'], $this->paymentOptions->forCheckout($context), true)) {
                throw ValidationException::withMessages(['paymentMethod' => __('checkout.errors.payment_unavailable')]);
            }

            $order = Order::query()->create([
                'number' => OrderNumber::next(),
                'ulid' => strtoupper((string) Str::ulid()),
                'public_until' => now()->addDays((int) config('checkout.public_link_days', 90)),
                'is_test' => false,
                'source' => OrderSource::Site,
                'customer_id' => $this->linkCustomer->findMatch($input['tin'], $input['phone']),
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Unpaid,
                'payment_method' => $input['payment_method'],
                'price_level' => PriceLevel::Retail,
                'locale' => $input['locale'],
                'buyer_type' => $input['buyer_type'],
                'name' => $input['name'],
                'phone' => $input['phone'],
                'company_name' => $input['company_name'],
                'tin' => $input['tin'],
                'delivery_type' => $input['delivery_type'],
                'region' => $input['region'],
                'address' => $input['address'],
                'kokand_address_confirmed' => false,
                'comment' => $input['comment'],
                'delivery_pricing' => $delivery->pricing,
                'delivery_amount' => $delivery->amount,
                'delivery_ikpu' => $delivery->amount > 0 ? $this->settings->deliveryIkpu() : null,
                'delivery_package_code' => $delivery->amount > 0 ? $this->settings->deliveryPackageCode() : null,
                'subtotal' => $priced->subtotal,
                'vat_amount' => Vat::of($total),
                'total' => $total,
                'invoice_valid_until' => WorkingDays::add(now(), (int) config('checkout.invoice_valid_working_days', 5))->toDateString(),
                'attribution' => Attribution::sanitize($data->attribution) ?: null,
                'traffic_channel' => $data->attribution !== [] ? Attribution::channel(Attribution::sanitize($data->attribution)) : TrafficChannel::tryFrom((string) $data->trafficChannel),
                'status_changed_at' => now(),
            ]);

            foreach ($priced->lines as $sort => $line) {
                $this->createItem($order, $line, $sort);
            }

            $cart->forceFill(['converted_order_id' => $order->id, 'converted_at' => now()])->save();

            $this->log->handle($order, 'created', null, ['source' => OrderSource::Site->value, 'total' => $total]);

            return [$order, true];
        });

        [$order, $created] = $result;

        if ($created) {
            OrderPlaced::dispatch($order);
        }

        return $order;
    }

    /**
     * @return array{name: string, phone: string, buyer_type: BuyerType, company_name: string|null, tin: string|null, delivery_type: DeliveryType, region: Region|null, address: string|null, comment: string|null, payment_method: PaymentMethod, locale: string}
     *
     * @throws ValidationException
     */
    protected function validated(CheckoutData $data): array
    {
        $buyerType = BuyerType::tryFrom($data->buyerType);
        $deliveryType = DeliveryType::tryFrom($data->deliveryType);
        $method = PaymentMethod::tryFrom($data->paymentMethod);
        $tin = TaxId::normalize($data->tin);

        $validator = Validator::make([
            'name' => trim($data->name),
            'phone' => $data->phone,
            'buyerType' => $data->buyerType,
            'companyName' => trim($data->companyName),
            'tin' => $tin,
            'deliveryType' => $data->deliveryType,
            'region' => $data->region,
            'address' => trim($data->address),
            'comment' => trim($data->comment),
            'paymentMethod' => $data->paymentMethod,
        ], [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'max:32', new UzPhone],
            'buyerType' => ['required', Rule::enum(BuyerType::class)],
            'companyName' => [$buyerType?->isBusiness() ? 'required' : 'nullable', 'string', 'max:160'],
            'tin' => [$buyerType?->isBusiness() ? 'required' : 'nullable', 'string', 'max:14'],
            'deliveryType' => ['required', Rule::enum(DeliveryType::class)],
            'region' => [$deliveryType === DeliveryType::CourierRegion ? 'required' : 'nullable', Rule::enum(Region::class)],
            'address' => [$deliveryType?->needsAddress() ? 'required' : 'nullable', 'string', 'max:500'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'paymentMethod' => ['required', Rule::enum(PaymentMethod::class)],
        ], [], [
            'name' => __('checkout.fields.name'),
            'phone' => __('checkout.fields.phone'),
            'companyName' => __('checkout.fields.company_name'),
            'tin' => __('checkout.fields.tin'),
            'region' => __('checkout.fields.region'),
            'address' => __('checkout.fields.address'),
            'comment' => __('checkout.fields.comment'),
            'paymentMethod' => __('checkout.fields.payment_method'),
        ]);

        $validator->after(function ($validator) use ($buyerType, $deliveryType, $method, $tin, $data): void {
            if ($buyerType !== null && $buyerType->isBusiness() && $tin !== '' && ! TaxId::isValid($buyerType, $tin)) {
                $validator->errors()->add('tin', __(TaxId::messageKey($buyerType)));
            }

            if ($deliveryType === DeliveryType::CourierKokand && ! $data->kokandConfirmed) {
                $validator->errors()->add('kokandConfirmed', __('checkout.errors.kokand_confirm'));
            }

            if ($method !== null && $method->isReserved()) {
                $validator->errors()->add('paymentMethod', __('checkout.errors.payment_unavailable'));
            }
        });

        $validator->validate();

        /** @var BuyerType $buyerType */
        /** @var DeliveryType $deliveryType */
        /** @var PaymentMethod $method */
        $locale = in_array($data->locale, config('app.supported_locales'), true) ? $data->locale : (string) config('app.locale');

        return [
            'name' => mb_substr(trim($data->name), 0, 120),
            'phone' => (string) Phone::normalize($data->phone),
            'buyer_type' => $buyerType,
            'company_name' => $buyerType->isBusiness() ? mb_substr(trim($data->companyName), 0, 160) : null,
            'tin' => $buyerType->isBusiness() ? $tin : null,
            'delivery_type' => $deliveryType,
            'region' => $deliveryType === DeliveryType::CourierRegion ? Region::tryFrom($data->region) : null,
            'address' => $deliveryType->needsAddress() ? mb_substr(trim($data->address), 0, 500) : null,
            'comment' => trim($data->comment) !== '' ? mb_substr(trim($data->comment), 0, 1000) : null,
            'payment_method' => $method,
            'locale' => $locale,
        ];
    }

    /**
     * Every line has an IKPU (and the fixed delivery, when it is paid in the total).
     */
    public static function ikpuComplete(PricedCart $cart, DeliveryQuote $delivery, CheckoutSettings $settings): bool
    {
        foreach ($cart->lines as $line) {
            if (! is_string($line->product->ikpu_code) || ! preg_match(CheckoutSettings::IKPU, $line->product->ikpu_code)) {
                return false;
            }
        }

        return $delivery->amount === 0 || $settings->deliveryIkpu() !== null;
    }

    protected function createItem(Order $order, PricedLine $line, int $sort): void
    {
        $product = $line->product;

        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => mb_substr((string) $product->getTranslation('name', $order->locale), 0, 255),
            'sku' => $product->sku,
            'unit' => $line->isRollLine() ? ProductUnit::Roll : $product->unit,
            'qty' => $line->qty,
            'price' => (int) $line->unitPrice,
            'total' => (int) $line->total,
            'ikpu_code' => $product->ikpu_code,
            'package_code' => $product->package_code,
            'vat_percent' => Vat::PERCENT,
            'was_on_order' => $line->isOnOrder(),
            ...($line->rollLine?->snapshot() ?? []),
            'sort' => $sort,
        ]);
    }
}
