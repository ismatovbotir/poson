<?php

namespace App\Support\Cart;

/**
 * A cart as CartPricer priced it in this request (Retail, integer tiyin).
 *
 * subtotal: the valid lines only. fingerprint: sha256 of the line keys,
 * quantities, unit prices and validity (11b adds the delivery fee), so
 * checkout can tell that prices or availability changed since the buyer
 * looked (security condition 6).
 */
final readonly class PricedCart
{
    /**
     * @param  list<PricedLine>  $lines
     */
    public function __construct(
        public array $lines,
        public int $subtotal,
        public string $fingerprint,
    ) {}

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function count(): int
    {
        return count($this->lines);
    }

    public function hasInvalidLines(): bool
    {
        foreach ($this->lines as $line) {
            if (! $line->isValid) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<PricedLine>
     */
    public function quotableLines(): array
    {
        return array_values(array_filter($this->lines, fn (PricedLine $line): bool => $line->quotable));
    }

    public function line(string $lineKey): ?PricedLine
    {
        foreach ($this->lines as $line) {
            if ($line->lineKey === $lineKey) {
                return $line;
            }
        }

        return null;
    }
}
