<?php

namespace App\Enums;

/**
 * Which Payme key the merchant API uses (Settings → Integrations → Payme):
 * the sandbox (test.paycom.uz) key or the live one. Switching to Prod needs
 * an extra confirmation in admin.
 */
enum PaymeMode: string
{
    case Test = 'test';
    case Prod = 'prod';

    public function label(): string
    {
        return __("enums.payme_mode.{$this->value}");
    }
}
