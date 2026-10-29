<?php

namespace App\Data\Orders;

/**
 * A manual payment as staff record it (security condition 20), raw from the
 * form (strings; the actions parse and check every field):
 * - method: a manual PaymentMethod value (uzqr_static, on_receipt, bank_transfer);
 * - amountReceived: tiyin, must equal the outstanding amount exactly;
 * - paidOn: "Y-m-d", not in the future (Tashkent);
 * - reference: bank / payment reference (≤ 100), required for uzqr_static
 *   and bank_transfer, unique per method;
 * - receiptChannel: cash | card | uzqr for on_receipt.
 */
final readonly class ManualPaymentData
{
    public function __construct(
        public string $method,
        public ?int $amountReceived,
        public string $paidOn,
        public ?string $reference = null,
        public ?string $receiptChannel = null,
        public ?string $note = null,
    ) {}
}
