<?php

namespace App\Enums;

/**
 * Where an order came from: the site checkout, an accepted quote (later
 * step) or created by staff in admin.
 */
enum OrderSource: string
{
    case Site = 'site';
    case Quote = 'quote';
    case Manual = 'manual';

    public function label(): string
    {
        return __("enums.order_source.{$this->value}");
    }
}
