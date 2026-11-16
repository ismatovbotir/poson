<?php

namespace App\Services\Images;

/**
 * The default remover: no provider, no network, the Tier 1 cleaning stands.
 */
final class NullBackgroundRemover implements ImageBackgroundRemover
{
    public function remove(string $jpegPath): ?string
    {
        return null;
    }
}
