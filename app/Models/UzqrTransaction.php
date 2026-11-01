<?php

namespace App\Models;

use App\Enums\PaymentMode;
use App\Enums\UzqrSettlement;
use App\Enums\UzqrTransactionStatus;
use Database\Factories\UzqrTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One dynamic UzQR invoice of an order (plan 11e). Written only by
 * App\Actions\Payments\Uzqr\*, always under the order lock first, then this
 * row's lock (security condition 37). Never deleted, except test-mode rows
 * of a TEST order without a settled payment (DeleteTestOrder, condition 35).
 *
 * Never stores payer_phone: only card_type and card_last4 (condition 49).
 * qr_text is hidden from arrays / JSON and never a Livewire property.
 *
 * @property int $id
 * @property PaymentMode $mode
 * @property int $order_id
 * @property string|null $external_id
 * @property string|null $invoice_id
 * @property string|null $pay_id
 * @property int $amount
 * @property UzqrTransactionStatus $status
 * @property int|null $remote_status
 * @property string|null $qr_text
 * @property int|null $card_type
 * @property string|null $card_last4
 * @property UzqrSettlement|null $settlement
 * @property string|null $unmatched_reason
 * @property Carbon|null $expires_at
 * @property Carbon|null $next_check_at
 * @property Carbon|null $last_checked_at
 * @property int $check_failures
 * @property int|null $last_error_code
 * @property string|null $last_error
 * @property Carbon|null $cancel_requested_at
 * @property Carbon|null $alerted_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $closed_at
 * @property string|null $resolution
 * @property string|null $resolution_reference
 * @property string|null $resolution_note
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by_id
 * @property Carbon|null $receipt_confirmed_at
 * @property int|null $receipt_confirmed_by_id
 * @property int|null $created_by_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'mode', 'order_id', 'external_id', 'invoice_id', 'pay_id', 'amount', 'status', 'remote_status', 'qr_text',
    'card_type', 'card_last4', 'settlement', 'unmatched_reason', 'expires_at', 'next_check_at', 'last_checked_at',
    'check_failures', 'last_error_code', 'last_error', 'cancel_requested_at', 'alerted_at', 'paid_at', 'closed_at',
    'resolution', 'resolution_reference', 'resolution_note', 'resolved_at', 'resolved_by_id',
    'receipt_confirmed_at', 'receipt_confirmed_by_id', 'created_by_id',
])]
class UzqrTransaction extends Model
{
    /** @use HasFactory<UzqrTransactionFactory> */
    use HasFactory;

    public const PROVIDER = 'uzqr';

    public const RESOLUTION_APPLIED = 'applied';

    public const RESOLUTION_REFUNDED = 'refunded_externally';

    public const RESOLUTION_HOLD_RELEASED = 'hold_released';

    /**
     * An Admin may release a hold only this long after the expiry, and only after a bank check that late (condition 57).
     */
    public const RELEASE_AFTER_HOURS = 24;

    /**
     * @var list<string>
     */
    protected $hidden = ['qr_text'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => PaymentMode::class,
            'order_id' => 'integer',
            'amount' => 'integer',
            'status' => UzqrTransactionStatus::class,
            'remote_status' => 'integer',
            'card_type' => 'integer',
            'settlement' => UzqrSettlement::class,
            'expires_at' => 'datetime',
            'next_check_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'check_failures' => 'integer',
            'last_error_code' => 'integer',
            'cancel_requested_at' => 'datetime',
            'alerted_at' => 'datetime',
            'paid_at' => 'datetime',
            'closed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'receipt_confirmed_at' => 'datetime',
        ];
    }

    /**
     * "PS" / "PT" + the 12-digit row id (alphanumeric, ≤ 20, never reused).
     */
    public static function externalIdFor(int $id, PaymentMode $mode): string
    {
        return ($mode === PaymentMode::Prod ? 'PS' : 'PT').str_pad((string) $id, 12, '0', STR_PAD_LEFT);
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
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    /**
     * Holds the order: creating, pending, or expired without a bank-confirmed final status.
     */
    public function holdsOrder(): bool
    {
        return $this->status->isOpen() || ($this->status === UzqrTransactionStatus::Expired && $this->closed_at === null);
    }

    /**
     * The bank may still change the status: worth a status call (also a released hold, condition 61).
     */
    public function isCheckable(): bool
    {
        return $this->invoice_id !== null && ($this->holdsOrder() || $this->isReleasedOpen());
    }

    /**
     * Last moment the sweep keeps re-checking a row the bank never finalised
     * (M-a, condition 61): an expired holding row until expires_at +
     * released_check_days, a released hold until resolved_at + the same
     * window; null = no cutoff (still within the invoice lifetime).
     */
    public function checkUntil(): ?Carbon
    {
        $days = (int) config('uzqr.released_check_days', 30);

        return match (true) {
            $this->isReleasedOpen() => ($this->resolved_at ?? $this->updated_at)->copy()->addDays($days),
            $this->status === UzqrTransactionStatus::Expired && $this->closed_at === null && $this->expires_at !== null => $this->expires_at->copy()->addDays($days),
            default => null,
        };
    }

    /**
     * Hold released by an Admin, the bank has not given a final status yet.
     */
    public function isReleasedOpen(): bool
    {
        return $this->status === UzqrTransactionStatus::Released && $this->closed_at === null;
    }

    /**
     * An Admin may release the hold (condition 57): an expired invoice the
     * bank never confirmed, at least 24 h past its expiry and last checked
     * no earlier than that, with no settlement. Never creating, pending,
     * paid or unmatched rows. Re-checked by ReleaseUzqrHold under the locks.
     */
    public function isReleasable(): bool
    {
        if ($this->status !== UzqrTransactionStatus::Expired || $this->closed_at !== null || $this->invoice_id === null
            || $this->settlement !== null || $this->expires_at === null || $this->last_checked_at === null) {
            return false;
        }

        $threshold = $this->expires_at->copy()->addHours(self::RELEASE_AFTER_HOURS);

        return now()->greaterThanOrEqualTo($threshold) && $this->last_checked_at->greaterThanOrEqualTo($threshold);
    }

    public function isUnresolvedUnmatched(): bool
    {
        return $this->settlement === UzqrSettlement::Unmatched && $this->resolved_at === null;
    }

    /**
     * Rows holding an order (see holdsOrder()).
     *
     * @param  Builder<self>  $query
     */
    public function scopeHolding(Builder $query): void
    {
        $query->where(fn (Builder $inner) => $inner
            ->whereIn('uzqr_transactions.status', [UzqrTransactionStatus::Creating->value, UzqrTransactionStatus::Pending->value])
            ->orWhere(fn (Builder $expired) => $expired->where('uzqr_transactions.status', UzqrTransactionStatus::Expired->value)->whereNull('uzqr_transactions.closed_at')));
    }

    /**
     * Rows the bank may still change: holding ones and released holds without
     * a final status still inside the released_check_days window (condition
     * 61). A released hold past the window stays in the history only (it no
     * longer blocks DeleteTestOrder or the sweep).
     *
     * @param  Builder<self>  $query
     */
    public function scopeAwaitingBank(Builder $query): void
    {
        $query->where(fn (Builder $inner) => $inner
            ->where(fn (Builder $holding) => $holding->holding())
            ->orWhere(fn (Builder $released) => $released->releasedOpen()->where('uzqr_transactions.resolved_at', '>', now()->subDays((int) config('uzqr.released_check_days', 30)))));
    }

    /**
     * Rows a manual "Check status now" may ask the bank about: holding ones and every released hold without a final status.
     *
     * @param  Builder<self>  $query
     */
    public function scopeCheckable(Builder $query): void
    {
        $query->whereNotNull('uzqr_transactions.invoice_id')->where(fn (Builder $inner) => $inner
            ->where(fn (Builder $holding) => $holding->holding())
            ->orWhere(fn (Builder $released) => $released->releasedOpen()));
    }

    /**
     * Released holds without a bank-final status (any age).
     *
     * @param  Builder<self>  $query
     */
    public function scopeReleasedOpen(Builder $query): void
    {
        $query->where('uzqr_transactions.status', UzqrTransactionStatus::Released->value)->whereNull('uzqr_transactions.closed_at');
    }

    /**
     * Unmatched payments no Admin has resolved yet.
     *
     * @param  Builder<self>  $query
     */
    public function scopeUnresolvedUnmatched(Builder $query): void
    {
        $query->where('settlement', UzqrSettlement::Unmatched->value)->whereNull('resolved_at');
    }

    /**
     * Settled payments whose fiscal receipt finance has not confirmed yet (condition 47).
     *
     * @param  Builder<self>  $query
     */
    public function scopeReceiptRequired(Builder $query): void
    {
        $query->where('settlement', UzqrSettlement::Settled->value)->whereNull('receipt_confirmed_at');
    }
}
