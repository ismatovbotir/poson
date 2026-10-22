<?php

namespace App\Enums;

/**
 * Legal form of a customer.
 */
enum CustomerType: string
{
    case Company = 'company';
    case Individual = 'individual';

    public function label(): string
    {
        return __("enums.customer_type.{$this->value}");
    }
}
