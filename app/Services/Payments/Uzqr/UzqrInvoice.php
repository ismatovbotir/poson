<?php

namespace App\Services\Payments\Uzqr;

/**
 * Answer of invoice/create (validated by UzqrClient).
 */
final readonly class UzqrInvoice
{
    public function __construct(
        public string $invoiceId,
        public string $qrText,
    ) {}
}
