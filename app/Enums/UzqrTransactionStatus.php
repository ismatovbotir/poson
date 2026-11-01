<?php

namespace App\Enums;

/**
 * State of a dynamic UzQR invoice on our side (plan 11e §3):
 *
 * - creating: row inserted, invoice/create not answered yet (open);
 * - pending: invoice issued, QR payable at the bank (open);
 * - paid: bank status 2 (settled, or unmatched → needs an Admin);
 * - failed: bank status 3 or 3001 (final, bank-confirmed);
 * - expired: past expires_at; final only once the bank confirmed it
 *   (closed_at set), otherwise it still holds the order (condition 39/40);
 * - create_failed: invoice/create refused or never answered (final);
 * - released: an Admin released the hold of an expired invoice the bank
 *   never confirmed (conditions 57–64). Never holds the order, but stays
 *   checkable (sweep, "Check status now") until the bank gives a final
 *   status (closed_at): 2 settles it or marks it unmatched.
 */
enum UzqrTransactionStatus: string
{
    case Creating = 'creating';
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Expired = 'expired';
    case CreateFailed = 'create_failed';
    case Released = 'released';

    public function label(): string
    {
        return __("enums.uzqr_transaction_status.{$this->value}");
    }

    public function color(): string
    {
        return match ($this) {
            self::Creating, self::Pending => 'sky',
            self::Paid => 'brand',
            self::Expired, self::Released => 'amber',
            self::Failed, self::CreateFailed => 'slate',
        };
    }

    /**
     * The invoice may still be (or become) payable: never two per order.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Creating, self::Pending], true);
    }

    /**
     * Statuses that may hold an order (expired only while not bank-confirmed).
     *
     * @return list<string>
     */
    public static function holdingValues(): array
    {
        return [self::Creating->value, self::Pending->value, self::Expired->value];
    }
}
