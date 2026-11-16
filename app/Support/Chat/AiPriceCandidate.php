<?php

namespace App\Support\Chat;

/**
 * An amount the AI output filter may let through (plan 30 §18, AI-B1 – AI-B3):
 * a product candidate's Retail price in tiyin (null: "price on request", no
 * amount allowed) with the codes (SKU, model) and names that must stand in
 * the same line or sentence as the amount. The codes also excuse their own
 * digit runs (an SKU "100245" is not a phone or an amount). An "anywhere"
 * amount (Translate with Claude: an amount of the source text) needs no code.
 * Pure value object: lowercase strings, no models.
 */
final readonly class AiPriceCandidate
{
    /**
     * @param  list<string>  $codes  SKU and model, matched as whole tokens
     * @param  list<string>  $names  Product names, matched as substrings
     */
    public function __construct(
        public ?int $tiyin,
        public bool $perLabel = false,
        public array $codes = [],
        public array $names = [],
        public bool $anywhere = false,
    ) {}

    public static function anywhere(int $tiyin, bool $perLabel = false): self
    {
        return new self($tiyin, $perLabel, [], [], true);
    }

    /**
     * @param  list<string|null>  $codes
     * @param  list<string|null>  $names
     */
    public static function product(?int $tiyin, bool $perLabel, array $codes, array $names): self
    {
        $clean = fn (array $values, int $min): array => array_values(array_unique(array_filter(
            array_map(fn (?string $value): string => trim((string) preg_replace('/\s+/u', ' ', mb_strtolower(AiOutputFilter::normalize((string) $value)))), $values),
            fn (string $value): bool => mb_strlen($value) >= $min,
        )));

        return new self($tiyin, $perLabel, $clean($codes, 2), $clean($names, 4));
    }
}
