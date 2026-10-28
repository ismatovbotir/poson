<?php

namespace App\Data\Site;

use App\Enums\ProductCtaKind;

/**
 * One product call-to-action from App\Support\Catalog\ProductCtas.
 *
 * - kind / labelKey: what the button is and its translation key.
 * - action: what the button does, for any client (website, Telegram Mini App):
 *     external  → open url in a new tab (the tracked /{locale}/go/{slug} redirect);
 *     lead_form → open the lead form with productId ("Contact us" / "Request a quote");
 *     cart      → add productId to the cart (a label product: pick a roll on
 *                 the product page first).
 * - url: absolute URL on the website: the external link; the product page
 *   anchored at its lead form (lead_form); the product page (cart: where a
 *   label product's roll is picked; the site adds other products in place
 *   with Site\Cart\AddButton).
 * - primary: the main button (filled); others are secondary (outlined).
 */
final readonly class ProductCta
{
    public const ACTION_EXTERNAL = 'external';

    public const ACTION_LEAD_FORM = 'lead_form';

    public const ACTION_CART = 'cart';

    /**
     * Anchor of the lead form on the product page.
     */
    public const LEAD_FORM_ANCHOR = 'quote';

    /**
     * Anchor of the buy box (price, roll picker, Add to cart) on the product page.
     */
    public const BUY_BOX_ANCHOR = 'buy';

    public function __construct(
        public ProductCtaKind $kind,
        public string $labelKey,
        public string $action,
        public string $url,
        public int $productId,
        public bool $primary,
    ) {}

    public function isExternal(): bool
    {
        return $this->action === self::ACTION_EXTERNAL;
    }

    public function label(?string $locale = null): string
    {
        return __($this->labelKey, [], $locale);
    }

    /**
     * Link target on the product page itself: external links as they are,
     * "Add to cart" scrolls to the buy box (mobile sticky bar), everything
     * else to the lead form.
     */
    public function pageHref(): string
    {
        return match ($this->action) {
            self::ACTION_EXTERNAL => $this->url,
            self::ACTION_CART => '#'.self::BUY_BOX_ANCHOR,
            default => '#'.self::LEAD_FORM_ANCHOR,
        };
    }

    public function isCart(): bool
    {
        return $this->action === self::ACTION_CART;
    }

    /**
     * Plain array for JSON clients (e.g. the Telegram Mini App).
     *
     * @return array{kind: string, label_key: string, action: string, url: string, product_id: int, primary: bool}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'label_key' => $this->labelKey,
            'action' => $this->action,
            'url' => $this->url,
            'product_id' => $this->productId,
            'primary' => $this->primary,
        ];
    }
}
