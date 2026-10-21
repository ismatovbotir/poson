<?php

namespace App\Enums;

/**
 * Purpose of a company phone number (Settings → Company → Contacts). Shown
 * next to the number on the site and mapped to a schema.org ContactPoint.
 * "Orders" (not "sales"): the brand-rule check bans the word "sale(s)" on
 * public pages, including attributes and JSON-LD.
 */
enum PhoneLabel: string
{
    case Orders = 'orders';
    case Service = 'service';
    case Accounting = 'accounting';
    case Office = 'office';

    public function label(): string
    {
        return __("enums.phone_label.{$this->value}");
    }

    /**
     * schema.org ContactPoint contactType.
     */
    public function contactType(): string
    {
        return match ($this) {
            self::Orders => 'customer service',
            self::Service => 'technical support',
            self::Accounting => 'billing support',
            self::Office => 'customer support',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
