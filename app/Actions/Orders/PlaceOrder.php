<?php

namespace App\Actions\Orders;

use App\Data\Orders\CheckoutData;
use App\Data\Orders\OrderOrigin;
use App\Enums\BuyerType;
use App\Enums\DeliveryType;
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
use App\Models\TelegramUser;
use App\Rules\PhoneNumber;
use App\Support\Analytics\AdClickIds;
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
use App\Support\Telegram\VerifiedTelegramPhone;
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
 * 1. Validate the typed fields (enums with tryFrom, phone (any country), STIR / PINFL
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
 *
 * Origin (plan 20 §6.1; optional, the site checkout passes none):
 *  - Site: the cart must have no Telegram owner (MA-21), methods from
 *    PaymentOptions::forCheckout().
 *  - Telegram (Mini App): the phone is the user's verified registration
 *    phone (VerifiedTelegramPhone, MA-9; whatever CheckoutData says), the
 *    locked cart must belong to that user (MA-21), the methods are
 *    PaymentOptions::forMiniApp() computed here, and an online or reserved
 *    method is refused whatever it says (MA-8). source telegram and
 *    telegram_user_id are written; the customer is the user's linked
 *    customer only while the link is verified (isLinkVerified()), else the
 *    STIR + phone match. Retail only, like the site.
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
        return static::fingerprintFrom($cart->fingerprint, $delivery);
    }

    /**
     * The same fingerprint from the cart's own fingerprint (the Mini App page
     * shows the cart, the delivery choice is made in the same form).
     */
    public static function fingerprintFrom(string $cartFingerprint, DeliveryQuote $delivery): string
    {
        return hash('sha256', $cartFingerprint.'|'.$delivery->pricing->value.'|'.$delivery->amount);
    }

    /**
     * @throws ValidationException
     */
    public function handle(string $cartToken, CheckoutData $data, ?OrderOrigin $origin = null): Order
    {
        if (! config('site.cart_enabled')) {
            throw ValidationException::withMessages(['form' => __('checkout.cart.errors.disabled')]);
        }

        $origin ??= OrderOrigin::site();
        $telegramUser = null;

        if ($origin->isTelegram()) {
            $telegramUser = TelegramUser::query()->find($origin->telegramUserId);
            $phone = $telegramUser instanceof TelegramUser ? VerifiedTelegramPhone::for($telegramUser) : null;

            if ($phone === null) {
                throw ValidationException::withMessages(['form' => __('tma.checkout.phone_unverified')]);
            }

            $data = $data->withPhone($phone);
        }

        $input = $this->validated($data);

        $result = DB::transaction(function () use ($cartToken, $data, $input, $origin, $telegramUser): array {
            $cart = Cart::query()->where('token', $cartToken)->lockForUpdate()->first();

            // MA-21: a Mini App cart only for its own user, a site cart only without an origin user.
            if (! $cart instanceof Cart || $cart->telegram_user_id !== $origin->telegramUserId) {
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

            $allowed = $origin->isTelegram() ? $this->paymentOptions->forMiniApp($context) : $this->paymentOptions->forCheckout($context);
            $method = $input['payment_method'];

            if (! in_array($method, $allowed, true) || ($origin->isTelegram() && ($method->isOnline() || $method->isReserved()))) {
                throw ValidationException::withMessages(['paymentMethod' => __('checkout.errors.payment_unavailable')]);
            }

            $customerId = $telegramUser instanceof TelegramUser && $telegramUser->isLinkVerified()
                ? $telegramUser->customer_id
                : $this->linkCustomer->findMatch($input['tin'], $input['phone']);

            $order = Order::query()->create([
                'number' => OrderNumber::next(),
                'ulid' => strtoupper((string) Str::ulid()),
                'public_until' => now()->addDays((int) config('checkout.public_link_days', 90)),
                'is_test' => false,
                'source' => $origin->source,
                'customer_id' => $customerId,
                'telegram_user_id' => $origin->telegramUserId,
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
                'attribution' => Attribution::withoutClickIds(Attribution::sanitize($data->attribution)) ?: null,
                'yclid' => AdClickIds::cleanYclid($data->yclid),
                'ym_client_id' => AdClickIds::cleanClientId($data->ymClientId),
                'traffic_channel' => $data->attribution !== [] ? Attribution::channel(Attribution::sanitize($data->attribution)) : TrafficChannel::tryFrom((string) $data->trafficChannel),
                'status_changed_at' => now(),
            ]);

            foreach ($priced->lines as $sort => $line) {
                $this->createItem($order, $line, $sort);
            }

            $cart->forceFill(['converted_order_id' => $order->id, 'converted_at' => now()])->save();

            $this->log->handle($order, 'created', null, ['source' => $origin->source->value, 'total' => $total]);

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
            'phone' => ['required', 'string', 'max:32', new PhoneNumber(public: true)],
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
            'phone' => (string) Phone::normalizeAny($data->phone),
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
