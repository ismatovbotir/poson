<?php

namespace App\Enums;

/**
 * What the client asked for.
 */
enum LeadType: string
{
    case Callback = 'callback';
    case Quote = 'quote';
    case ServiceRequest = 'service_request';
    case Checkout = 'checkout';

    public function label(): string
    {
        return __("enums.lead_type.{$this->value}");
    }
}
