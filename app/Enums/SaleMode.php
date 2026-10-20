<?php

namespace App\Enums;

/**
 * How a product is sold: through the cart, through a quote request, or both.
 * A product without a price is always sold through a quote.
 */
enum SaleMode: string
{
    case Cart = 'cart';
    case Quote = 'quote';
    case Both = 'both';

    public function label(): string
    {
        return __("enums.sale_mode.{$this->value}");
    }

    public function allowsCart(): bool
    {
        return $this !== self::Quote;
    }

    public function allowsQuote(): bool
    {
        return $this !== self::Cart;
    }
}
