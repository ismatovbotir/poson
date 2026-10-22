<?php

namespace App\Support\Catalog;

use App\Data\Site\ProductCta;
use App\Enums\ProductCtaKind;
use App\Enums\ProductType;
use App\Models\Product;

/**
 * THE source of a product's call-to-action buttons (decision 2026-10-22),
 * for the website (product card, product page and its mobile sticky bar)
 * and the future Telegram Mini App / bot. Clients render the returned list
 * in order and never re-implement these rules; leads from every client go
 * to the same lead action (source site / telegram).
 *
 * Rules, first match wins:
 *
 *  1. Software whose (active) brand has a valid https website → "Try"
 *     (primary; the tracked partner redirect /{locale}/go/{slug} to the
 *     brand website, which is the partner / referral link, new tab) +
 *     "Contact us" (secondary; the lead form with the product). Other types
 *     never get "Try", even when their brand has a website.
 *  2. The cart is on (config site.cart_enabled) and the product can go to the
 *     cart (sale mode cart / both with a Retail price) → "Add to cart"
 *     (primary) + "Request a quote" (secondary; "Contact us" for software).
 *  3. Software → "Contact us" (primary).
 *  4. Everything else → "Request a quote" (primary).
 *
 * compact (product cards): the secondary quote / contact button next to
 * "Add to cart" is left out (the card keeps one buying button); the
 * "Try" + "Contact us" pair is always kept.
 *
 * Brand rule: only the Retail price decides; Dealer / Wholesale prices are
 * never read here.
 */
class ProductCtas
{
    /**
     * Needs "brand" loaded (Product::partnerUrl()).
     *
     * @return list<ProductCta>
     */
    public static function for(Product $product, bool $compact = false, ?string $locale = null, ?bool $cartEnabled = null): array
    {
        $locale ??= app()->getLocale();
        $cartEnabled ??= (bool) config('site.cart_enabled');
        $isSoftware = $product->type === ProductType::Software;
        $productUrl = route('site.product', ['locale' => $locale, 'product' => $product->slug]);
        $leadFormUrl = $productUrl.'#'.ProductCta::LEAD_FORM_ANCHOR;

        if ($product->partnerUrl() !== null) {
            return [
                self::make($product, ProductCtaKind::Try, ProductCta::ACTION_EXTERNAL, route('site.product.partner', ['locale' => $locale, 'product' => $product->slug]), true),
                self::make($product, ProductCtaKind::Contact, ProductCta::ACTION_LEAD_FORM, $leadFormUrl, false),
            ];
        }

        $inquiry = $isSoftware ? ProductCtaKind::Contact : ProductCtaKind::Quote;

        if ($cartEnabled && self::canAddToCart($product)) {
            return array_values(array_filter([
                // The cart page doesn't exist yet: the button leads to the product page.
                self::make($product, ProductCtaKind::Cart, ProductCta::ACTION_CART, $productUrl, true),
                $compact ? null : self::make($product, $inquiry, ProductCta::ACTION_LEAD_FORM, $leadFormUrl, false),
            ]));
        }

        return [self::make($product, $inquiry, ProductCta::ACTION_LEAD_FORM, $leadFormUrl, true)];
    }

    /**
     * Sale mode cart / both with a Retail price (a product without a price is quote-only).
     */
    public static function canAddToCart(Product $product): bool
    {
        return $product->price !== null && $product->effectiveSaleMode()->allowsCart();
    }

    private static function make(Product $product, ProductCtaKind $kind, string $action, string $url, bool $primary): ProductCta
    {
        return new ProductCta(
            kind: $kind,
            labelKey: $kind->labelKey(),
            action: $action,
            url: $url,
            productId: $product->id,
            primary: $primary,
        );
    }
}
