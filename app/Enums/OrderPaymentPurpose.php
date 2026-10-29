<?php

namespace App\Enums;

/**
 * What an order_payments row pays: the goods total, or a delivery charge
 * billed separately (plan 11 v2 §1.2, security condition 23).
 */
enum OrderPaymentPurpose: string
{
    case Goods = 'goods';
    case Delivery = 'delivery';

    public function label(): string
    {
        return __("enums.order_payment_purpose.{$this->value}");
    }
}
