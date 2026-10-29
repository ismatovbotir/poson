<?php

namespace App\Models;

use App\Enums\BuyerType;
use App\Enums\DeliveryPricing;
use App\Enums\DeliveryType;
use App\Enums\OrderPaymentPurpose;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PriceLevel;
use App\Enums\Region;
use App\Enums\TrafficChannel;
use App\Support\Orders\Contracts\PaymentHold;
use App\Support\Orders\PaymentContext;
use App\Support\Search;
use Carbon\CarbonImmutable;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * An order (plan 11 v2). Written only through App\Actions\Orders\* (site
 * checkout: PlaceOrder; staff: CreateManualOrder and the edit actions),
 * always under a row lock of the order.
 *
 * Money in integer tiyin, VAT 12% included: total = subtotal +
 * delivery_amount; once paid the total never changes (delivery billed later
 * is an order_payments row of purpose delivery).
 *
 * Access (OrderPolicy and the list scope agree): orders.viewAny + view see
 * every real order (Admin, Sales, Service engineer read-only); TEST orders
 * (is_test) only with payments.test (Admin).
 *
 * @property int $id
 * @property string $number
 * @property string $ulid
 * @property Carbon|null $public_until
 * @property bool $is_test
 * @property OrderSource $source
 * @property int|null $customer_id
 * @property int|null $manager_id
 * @property int|null $created_by_id
 * @property OrderStatus $status
 * @property PaymentStatus $payment_status
 * @property PaymentMethod $payment_method
 * @property Carbon|null $payment_pending_at
 * @property PriceLevel $price_level
 * @property string $locale
 * @property BuyerType $buyer_type
 * @property string $name
 * @property string $phone
 * @property string|null $company_name
 * @property string|null $tin
 * @property DeliveryType $delivery_type
 * @property Region|null $region
 * @property string|null $address
 * @property bool $kokand_address_confirmed
 * @property string|null $comment
 * @property string|null $staff_note
 * @property DeliveryPricing $delivery_pricing
 * @property int $delivery_amount
 * @property string|null $delivery_ikpu
 * @property string|null $delivery_package_code
 * @property int $subtotal
 * @property int $vat_amount
 * @property int $total
 * @property CarbonImmutable|null $invoice_valid_until
 * @property int $invoice_version
 * @property Carbon|null $amount_updated_at
 * @property array<string, string>|null $attribution
 * @property TrafficChannel|null $traffic_channel
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $shipped_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $refunded_at
 * @property string|null $cancel_reason
 * @property Carbon|null $status_changed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'number', 'ulid', 'public_until', 'is_test', 'source', 'customer_id', 'manager_id', 'created_by_id',
    'status', 'payment_status', 'payment_method', 'payment_pending_at', 'price_level', 'locale',
    'buyer_type', 'name', 'phone', 'company_name', 'tin',
    'delivery_type', 'region', 'address', 'kokand_address_confirmed', 'comment', 'staff_note',
    'delivery_pricing', 'delivery_amount', 'delivery_ikpu', 'delivery_package_code',
    'subtotal', 'vat_amount', 'total', 'invoice_valid_until', 'invoice_version', 'amount_updated_at',
    'attribution', 'traffic_channel',
    'confirmed_at', 'shipped_at', 'completed_at', 'cancelled_at', 'paid_at', 'refunded_at', 'cancel_reason', 'status_changed_at',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * Most lines in one order (security condition 6).
     */
    public const MAX_LINES = 50;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'public_until' => 'datetime',
            'is_test' => 'boolean',
            'source' => OrderSource::class,
            'customer_id' => 'integer',
            'manager_id' => 'integer',
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'payment_method' => PaymentMethod::class,
            'payment_pending_at' => 'datetime',
            'price_level' => PriceLevel::class,
            'buyer_type' => BuyerType::class,
            'delivery_type' => DeliveryType::class,
            'region' => Region::class,
            'kokand_address_confirmed' => 'boolean',
            'delivery_pricing' => DeliveryPricing::class,
            'delivery_amount' => 'integer',
            'subtotal' => 'integer',
            'vat_amount' => 'integer',
            'total' => 'integer',
            'invoice_valid_until' => 'immutable_date',
            'invoice_version' => 'integer',
            'amount_updated_at' => 'datetime',
            'attribution' => 'array',
            'traffic_channel' => TrafficChannel::class,
            'confirmed_at' => 'datetime',
            'shipped_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * @return HasMany<OrderPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return MorphMany<Activity, $this>
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    /**
     * Lines, the fixed delivery fee and the payment method may change: unpaid,
     * no active online transaction, still new or confirmed (§1.7).
     */
    public function isLinesEditable(): bool
    {
        return $this->payment_status === PaymentStatus::Unpaid
            && ! $this->hasActiveTransaction()
            && in_array($this->status, [OrderStatus::New, OrderStatus::Confirmed], true);
    }

    /**
     * Buyer contact, company, tax id, comment, note and manager: before completed, not cancelled.
     */
    public function isDetailsEditable(): bool
    {
        return ! $this->status->isClosed();
    }

    /**
     * Delivery address / region / type: before shipped (not cancelled).
     */
    public function isDeliveryEditable(): bool
    {
        return ! $this->status->isShippedOrLater() && $this->status !== OrderStatus::Cancelled;
    }

    /**
     * An online transaction holds the order: asked of every PaymentHold in
     * config('checkout.payment_holds') (provider => class). Plans 11c
     * ('payme'), 11d ('click') and 11e ('uzqr', dynamic UzQR) each add their
     * class there; until then nothing can hold an order, and those providers
     * are never offered (PaymentOptions::hasHold()).
     */
    public function hasActiveTransaction(): bool
    {
        foreach ((array) config('checkout.payment_holds', []) as $class) {
            $hold = is_string($class) ? app($class) : null;

            if ($hold instanceof PaymentHold && $hold->holds($this)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Refundable (plan 11 v2 §1.6): paid, not shipped yet (or cancelled), not refunded.
     */
    public function isRefundable(): bool
    {
        return $this->payment_status === PaymentStatus::Paid
            && $this->refunded_at === null
            && in_array($this->status, [OrderStatus::New, OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::Cancelled], true);
    }

    /**
     * The customer's /o link works: a real order within public_until.
     */
    public function isPublicLinkValid(): bool
    {
        return ! $this->is_test && $this->public_until !== null && $this->public_until->isFuture();
    }

    /**
     * The goods can be paid now (security review M1): unpaid, not cancelled
     * and not held by an online transaction. The one rule for /o, the pay
     * button, PaymentOptions::canPayOnline() and (11c/11d) the webhooks.
     */
    public function isPayable(): bool
    {
        return $this->payment_status === PaymentStatus::Unpaid
            && $this->status !== OrderStatus::Cancelled
            && ! $this->hasActiveTransaction();
    }

    /**
     * Unpaid goods total (0 once paid, pending or refunded).
     */
    public function outstandingGoods(): int
    {
        return $this->payment_status === PaymentStatus::Unpaid ? $this->total : 0;
    }

    /**
     * The pending delivery charge (at most one), when "payments" is loaded or queried.
     */
    public function pendingDeliveryCharge(): ?OrderPayment
    {
        $payments = $this->relationLoaded('payments') ? $this->payments : $this->payments()->get();

        return $payments->first(fn (OrderPayment $payment): bool => $payment->purpose === OrderPaymentPurpose::Delivery && $payment->status === OrderPaymentStatus::Pending);
    }

    /**
     * Every line has an IKPU, and so has the delivery when it is a paid line of the total.
     */
    public function isIkpuComplete(): bool
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();

        if ($items->isEmpty() || $items->contains(fn (OrderItem $item): bool => ! $item->hasIkpu())) {
            return false;
        }

        return $this->delivery_amount === 0 || (is_string($this->delivery_ikpu) && preg_match('/^\d{17}$/', $this->delivery_ikpu) === 1);
    }

    public function paymentContext(): PaymentContext
    {
        return new PaymentContext($this->delivery_type, $this->buyer_type, $this->total, $this->isIkpuComplete(), $this->is_test);
    }

    /**
     * The invoice number: the order number, with "/2", "/3" … once reissued (condition 27).
     */
    public function invoiceNumber(): string
    {
        return $this->invoice_version > 1 ? $this->number.'/'.$this->invoice_version : $this->number;
    }

    /**
     * Real (non-TEST) orders: lists, dashboards and reports (plan 11 v2 §1.8).
     *
     * @param  Builder<self>  $query
     */
    public function scopeReal(Builder $query): void
    {
        $query->where('orders.is_test', false);
    }

    public function isVisibleTo(User $user): bool
    {
        return $user->can('orders.viewAny') && $user->can('orders.view') && (! $this->is_test || $user->can('payments.test'));
    }

    /**
     * Orders the user may see: every real order with orders.viewAny + view;
     * TEST orders only with payments.test.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->can('orders.viewAny') || ! $user->can('orders.view')) {
            $query->whereRaw('1 = 0');

            return;
        }

        if (! $user->can('payments.test')) {
            $query->where('orders.is_test', false);
        }
    }

    /**
     * Number, buyer name or company, tax id, or phone digits (at least 3).
     *
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $pattern = Search::likePattern($term);
        $digits = (string) preg_replace('/\D+/', '', $term);

        $query->where(function (Builder $inner) use ($pattern, $digits): void {
            $inner->whereRaw("lower(orders.number) like ? escape '!'", [$pattern])
                ->orWhereRaw("lower(orders.name) like ? escape '!'", [$pattern])
                ->orWhereRaw("lower(orders.company_name) like ? escape '!'", [$pattern]);

            if (strlen($digits) >= 3) {
                $inner->orWhere('orders.phone', 'like', '%'.$digits.'%')
                    ->orWhere('orders.tin', 'like', $digits.'%');
            }
        });
    }
}
