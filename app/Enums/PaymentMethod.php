<?php

namespace App\Enums;

/**
 * How the customer pays an order (plan 11 v2 §1.3). Guards never key on a
 * concrete case: they ask confirmation(), isOnline() and isPrepaid(), so
 * the reserved uzqr_dynamic (webhook-confirmed UzQR, a later step) slots in.
 *
 * - payme / click: online, confirmed only by the provider's webhook;
 * - uzqr_static: the company's static UzQR code, exact total, staff confirm
 *   it against the bank statement (reference required);
 * - on_receipt: cash / card / UzQR at handover (channel recorded);
 * - bank_transfer: invoice, companies and sole proprietors (reference required).
 */
enum PaymentMethod: string
{
    case Payme = 'payme';
    case Click = 'click';
    case UzqrStatic = 'uzqr_static';
    case OnReceipt = 'on_receipt';
    case BankTransfer = 'bank_transfer';
    case UzqrDynamic = 'uzqr_dynamic';

    public function label(): string
    {
        return __("enums.payment_method.{$this->value}");
    }

    public function confirmation(): PaymentConfirmation
    {
        return match ($this) {
            self::Payme, self::Click, self::UzqrDynamic => PaymentConfirmation::Webhook,
            self::UzqrStatic, self::OnReceipt, self::BankTransfer => PaymentConfirmation::Manual,
        };
    }

    /**
     * Paid through a provider's webhook (Payme, Click, later dynamic UzQR).
     */
    public function isOnline(): bool
    {
        return $this->confirmation() === PaymentConfirmation::Webhook;
    }

    /**
     * Confirmed by staff (mark paid with the amount received).
     */
    public function isManual(): bool
    {
        return $this->confirmation() === PaymentConfirmation::Manual;
    }

    /**
     * Paid before the goods are handed over (everything but on_receipt).
     */
    public function isPrepaid(): bool
    {
        return $this !== self::OnReceipt;
    }

    /**
     * Mark-paid needs the bank / payment reference (security condition 20).
     */
    public function requiresReference(): bool
    {
        return $this->isManual() && $this->isPrepaid();
    }

    /**
     * Reserved for a later step: never offered, never accepted.
     */
    public function isReserved(): bool
    {
        return $this === self::UzqrDynamic;
    }

    /**
     * Methods that exist in this version.
     *
     * @return list<self>
     */
    public static function available(): array
    {
        return array_values(array_filter(self::cases(), fn (self $method): bool => ! $method->isReserved()));
    }

    /**
     * Methods staff can mark paid by hand.
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return array_values(array_filter(self::available(), fn (self $method): bool => $method->isManual()));
    }
}
