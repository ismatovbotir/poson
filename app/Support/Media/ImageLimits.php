<?php

namespace App\Support\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Upload limits shared by every image form and action (weak shared hosting:
 * decoding and converting a huge picture exhausts memory, so the pixel cap
 * and the gallery batch size are small and defined once).
 */
class ImageLimits
{
    /**
     * Longest allowed side of an uploaded image, in pixels.
     */
    public const MAX_DIMENSION = 4000;

    /**
     * Most gallery images accepted in one upload.
     */
    public const MAX_BATCH = 5;

    /**
     * Largest stored product image, in bytes (owner decision 2026-11-18: 200 KB each).
     * Recommended: square 1200 px, WebP q80 or JPEG, PNG only for transparency.
     */
    public const MAX_PRODUCT_BYTES = 204_800;

    /**
     * The product image cap in kilobytes, for the Laravel "max" rule.
     */
    public static function maxProductKilobytes(): int
    {
        return intdiv(self::MAX_PRODUCT_BYTES, 1024);
    }

    /**
     * Laravel "dimensions" rule with the pixel cap, plus optional minimums ("min_width=1,min_height=1").
     */
    public static function dimensionsRule(string $minimums = ''): string
    {
        return 'dimensions:'.($minimums !== '' ? $minimums.',' : '').'max_width='.self::MAX_DIMENSION.',max_height='.self::MAX_DIMENSION;
    }

    /**
     * Translation replacements for hints and messages that mention the limits.
     *
     * @return array{px: int, count: int}
     */
    public static function replacements(): array
    {
        return ['px' => self::MAX_DIMENSION, 'count' => self::MAX_BATCH];
    }

    /**
     * Refuse a product image above the byte cap (also for callers that skipped form validation).
     *
     * @throws ValidationException
     */
    public static function assertWithinProductBytes(UploadedFile $file, string $field): void
    {
        $path = $file->getRealPath();
        $bytes = $path === false ? false : filesize($path);

        if ($bytes === false || $bytes > self::MAX_PRODUCT_BYTES) {
            throw ValidationException::withMessages([$field => __('admin.image_upload.too_large_product')]);
        }
    }

    /**
     * Refuse an image above the pixel cap (also for callers that skipped form validation).
     *
     * @throws ValidationException
     */
    public static function assertWithinCap(UploadedFile $file, string $field): void
    {
        $size = @getimagesize($file->getRealPath());

        if ($size !== false && ($size[0] > self::MAX_DIMENSION || $size[1] > self::MAX_DIMENSION)) {
            throw ValidationException::withMessages([$field => __('validation.dimensions', ['attribute' => __('admin.image_upload.image')])]);
        }
    }
}
