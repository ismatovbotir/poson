<?php

namespace App\Support\Telegram\MiniApp;

use App\Support\Money;

/**
 * The signed-in user's open Mini App cart as the cart bar and the cards
 * need it (plan 45 §3, MA-45): priced live from the database on every
 * request by CartPricer::evaluate() (Retail, valid lines only in the
 * subtotal), never from a cache, the session or the client.
 *
 * count: lines (as the header counter and the cart page).
 * quantities: line key => quantity (the card steppers' "inCartQty").
 */
final readonly class TmaCartSummary
{
    /**
     * @param  array<string, int>  $quantities
     */
    public function __construct(
        public int $count = 0,
        public int $subtotal = 0,
        public array $quantities = [],
    ) {}

    public function qty(?string $lineKey): int
    {
        return $lineKey !== null ? ($this->quantities[$lineKey] ?? 0) : 0;
    }

    public function subtotalText(): string
    {
        return Money::format($this->subtotal);
    }
}
