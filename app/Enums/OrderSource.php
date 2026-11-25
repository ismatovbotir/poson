<?php

namespace App\Enums;

/**
 * Where an order came from: the site checkout, an accepted quote (later
 * step), created by staff in admin, or the Telegram Mini App checkout
 * (plan 20; orders.telegram_user_id is set).
 */
enum OrderSource: string
{
    case Site = 'site';
    case Quote = 'quote';
    case Manual = 'manual';
    case Telegram = 'telegram';

    /**
     * Placed by the customer at a checkout (site or Telegram Mini App, plan 20):
     * Retail prices from the catalog only, no free lines, no posted prices.
     */
    public function isCustomerCheckout(): bool
    {
        return in_array($this, [self::Site, self::Telegram], true);
    }

    public function label(): string
    {
        return __("enums.order_source.{$this->value}");
    }
}
