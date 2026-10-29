<?php

namespace App\Enums;

/**
 * How a payment method is confirmed: by the provider's webhook (never from
 * a return URL) or by staff against the bank statement / cash desk.
 */
enum PaymentConfirmation: string
{
    case Webhook = 'webhook';
    case Manual = 'manual';
}
