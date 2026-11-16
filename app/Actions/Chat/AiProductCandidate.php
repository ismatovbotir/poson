<?php

namespace App\Actions\Chat;

use App\Support\Chat\AiPriceCandidate;

/**
 * One product offered to the AI model for a question (plan 30 §6, AI-B4,
 * AI-B5): its id (product button), the button label, the capped
 * <products> line and the price the answer may name (AiPriceCandidate:
 * Retail only, with its SKU, model and name).
 */
final readonly class AiProductCandidate
{
    public function __construct(
        public int $id,
        public string $label,
        public string $line,
        public AiPriceCandidate $price,
    ) {}

    /**
     * Whether an answer names this product (its SKU, model or name), for its product button.
     */
    public function isNamedIn(string $answer): bool
    {
        $text = (string) preg_replace('/\s+/u', ' ', mb_strtolower($answer));

        foreach ($this->price->codes as $code) {
            if (preg_match('/(?<![\pL\pN])'.preg_quote($code, '/').'(?![\pL\pN])/u', $text) === 1) {
                return true;
            }
        }

        foreach ($this->price->names as $name) {
            if (str_contains($text, $name)) {
                return true;
            }
        }

        return false;
    }
}
