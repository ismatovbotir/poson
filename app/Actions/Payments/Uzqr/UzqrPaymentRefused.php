<?php

namespace App\Actions\Payments\Uzqr;

use RuntimeException;

/**
 * A dynamic UzQR action was refused; $reason is our own code with a
 * localized text (lang uzqr.errors.*), safe to show to the customer.
 */
class UzqrPaymentRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("UzQR payment refused: {$reason}");
    }

    public function userMessage(): string
    {
        return __('uzqr.errors.'.$this->reason);
    }
}
