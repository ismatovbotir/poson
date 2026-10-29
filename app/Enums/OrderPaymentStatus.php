<?php

namespace App\Enums;

/**
 * A row of the order payment ledger: pending (a delivery charge waiting for
 * payment), paid, refunded, or cancelled (a voided manual payment or a
 * cancelled delivery charge; rows are never deleted, condition 22).
 */
enum OrderPaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __("enums.order_payment_status.{$this->value}");
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Paid => 'brand',
            self::Refunded => 'muted',
            self::Cancelled => 'slate',
        };
    }
}
