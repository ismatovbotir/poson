<?php

namespace App\Enums;

/**
 * Payment state of an order's goods total (plan 11 v2 §1.6): unpaid →
 * pending (an online provider holds the order: Payme state 1 / Click
 * prepare, plans 11c/11d) → paid → refunded; pending goes back to unpaid
 * when the hold is cancelled or expires (ReleaseStalePaymentHolds).
 */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Pending = 'pending';
    case Paid = 'paid';
    case Refunded = 'refunded';

    public function label(): string
    {
        return __("enums.payment_status.{$this->value}");
    }

    public function color(): string
    {
        return match ($this) {
            self::Unpaid => 'amber',
            self::Pending => 'sky',
            self::Paid => 'brand',
            self::Refunded => 'muted',
        };
    }
}
