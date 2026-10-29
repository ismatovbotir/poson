<?php

namespace App\Models;

use App\Enums\OrderPaymentPurpose;
use App\Enums\OrderPaymentStatus;
use App\Enums\PaymentMethod;
use App\Enums\ReceiptChannel;
use Database\Factories\OrderPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row of an order's payment ledger (plan 11 v2 §2, security conditions
 * 20–23): the goods payment or a delivery charge. Written only by the order
 * actions; never deleted (except with a TEST order): voiding or cancelling
 * sets status cancelled and frees the reference (reference_key = null).
 *
 * @property int $id
 * @property int $order_id
 * @property OrderPaymentPurpose $purpose
 * @property PaymentMethod|null $method
 * @property ReceiptChannel|null $receipt_channel
 * @property OrderPaymentStatus $status
 * @property int $amount
 * @property bool $is_test
 * @property string|null $provider
 * @property string|null $provider_ref
 * @property string|null $reference
 * @property string|null $reference_key
 * @property string|null $note
 * @property Carbon|null $paid_on
 * @property Carbon|null $paid_at
 * @property int|null $recorded_by_id
 * @property int|null $created_by_id
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by_id
 * @property string|null $cancel_reason
 * @property Carbon|null $refunded_at
 * @property int|null $refunded_by_id
 * @property string|null $refund_reason
 * @property Carbon|null $reconciled_at
 * @property int|null $reconciled_by_id
 * @property Carbon $created_at
 */
#[Fillable([
    'order_id', 'purpose', 'method', 'receipt_channel', 'status', 'amount', 'is_test', 'provider', 'provider_ref',
    'reference', 'reference_key', 'note', 'paid_on', 'paid_at', 'recorded_by_id', 'created_by_id',
    'cancelled_at', 'cancelled_by_id', 'cancel_reason', 'refunded_at', 'refunded_by_id', 'refund_reason',
    'reconciled_at', 'reconciled_by_id',
])]
class OrderPayment extends Model
{
    /** @use HasFactory<OrderPaymentFactory> */
    use HasFactory;

    public const MAX_REFERENCE = 100;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => OrderPaymentPurpose::class,
            'method' => PaymentMethod::class,
            'receipt_channel' => ReceiptChannel::class,
            'status' => OrderPaymentStatus::class,
            'amount' => 'integer',
            'is_test' => 'boolean',
            'paid_on' => 'immutable_date',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
            'reconciled_at' => 'datetime',
            'order_id' => 'integer',
        ];
    }

    /**
     * "method:REFERENCE" with spaces removed and upper-cased: the unique key
     * of an active manual payment reference (security condition 20).
     */
    public static function referenceKey(PaymentMethod $method, string $reference): string
    {
        return $method->value.':'.mb_strtoupper((string) preg_replace('/\s+/u', '', $reference));
    }

    /**
     * The last 4 characters of a reference for alerts ("…1234").
     */
    public static function maskReference(?string $reference): ?string
    {
        if ($reference === null || trim($reference) === '') {
            return null;
        }

        return '…'.mb_substr(trim($reference), -4);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by_id');
    }

    /**
     * Confirmed by staff (not a provider webhook).
     */
    public function isManual(): bool
    {
        return $this->method !== null && $this->method->isManual();
    }

    /**
     * Paid manual payments not yet ticked off against the bank statement.
     *
     * @param  Builder<self>  $query
     */
    public function scopeUnreconciled(Builder $query): void
    {
        $query->where('status', OrderPaymentStatus::Paid->value)
            ->whereIn('method', array_map(fn (PaymentMethod $method): string => $method->value, PaymentMethod::manual()))
            ->whereNull('reconciled_at');
    }
}
