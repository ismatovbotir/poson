<?php

namespace App\Services\Images;

/**
 * Tier 2 background removal of the AI product wizard (plan 32 §4, later,
 * owner decision and its own security sign-off). Gets the path of a Tier 1
 * re-encoded JPEG and returns the path of a new PNG with the background
 * removed, or null when nothing was done (the Tier 1 result is used).
 * Bound to NullBackgroundRemover until a provider is approved.
 */
interface ImageBackgroundRemover
{
    public function remove(string $jpegPath): ?string;
}
