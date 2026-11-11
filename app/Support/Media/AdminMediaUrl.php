<?php

namespace App\Support\Media;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * URL of an image for ADMIN previews only: the generated conversion when it
 * exists, otherwise the authorized original route (originals live on the
 * private disk and are never linked directly). Public pages never use this;
 * they keep requiring a generated conversion.
 */
class AdminMediaUrl
{
    public static function preview(?Media $media, string $conversion): ?string
    {
        if ($media === null) {
            return null;
        }

        if ($media->hasGeneratedConversion($conversion)) {
            return $media->getUrl($conversion);
        }

        return route('admin.media.original', $media);
    }
}
