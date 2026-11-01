<?php

namespace App\Services\Payments\Uzqr;

/**
 * Answer of invoice/status (validated by UzqrClient): status 1 in progress,
 * 2 paid (pay_id always present), 3 rejected / cancelled / failed. Only the
 * card type and the last 4 card digits are kept; payer_phone is never read
 * (condition 49). The bank sends no amount.
 */
final readonly class UzqrInvoiceStatus
{
    public const IN_PROGRESS = 1;

    public const PAID = 2;

    public const REJECTED = 3;

    public function __construct(
        public string $invoiceId,
        public int $status,
        public ?string $payId = null,
        public ?int $cardType = null,
        public ?string $cardLast4 = null,
    ) {}
}
