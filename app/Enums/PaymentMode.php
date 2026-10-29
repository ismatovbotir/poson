<?php

namespace App\Enums;

/**
 * Which credentials a payment provider uses (Settings → Integrations →
 * Payme / Click, keys payme.mode and click.mode; plan 11 v2 §1.8): the
 * sandbox (test) credentials or the live ones. One active mode per
 * provider; switching to Prod needs an extra confirmation in admin.
 * Public checkout only offers a provider in Prod (security condition 1).
 */
enum PaymentMode: string
{
    case Test = 'test';
    case Prod = 'prod';

    public function label(): string
    {
        return __("enums.payment_mode.{$this->value}");
    }
}
