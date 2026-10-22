<?php

namespace App\Enums;

/**
 * Call-to-action of a product (App\Support\Catalog\ProductCtas): the partner
 * "Try" link, "Contact us" (lead form with the product), "Add to cart" and
 * "Request a quote".
 */
enum ProductCtaKind: string
{
    case Try = 'try';
    case Contact = 'contact';
    case Cart = 'cart';
    case Quote = 'quote';

    /**
     * Translation key of the button label (site locale).
     */
    public function labelKey(): string
    {
        return match ($this) {
            self::Try => 'site.cta.try',
            self::Contact => 'site.cta.contact',
            self::Cart => 'site.card.add_to_cart',
            self::Quote => 'site.card.request_quote',
        };
    }

    public function label(?string $locale = null): string
    {
        return __($this->labelKey(), [], $locale);
    }
}
