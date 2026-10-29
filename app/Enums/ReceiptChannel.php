<?php

namespace App\Enums;

/**
 * How an on-receipt payment was taken at handover.
 */
enum ReceiptChannel: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Uzqr = 'uzqr';

    public function label(): string
    {
        return __("enums.receipt_channel.{$this->value}");
    }
}
