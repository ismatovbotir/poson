<?php

namespace App\Models\Concerns;

use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One image per record in a single-file medialibrary collection (plan 03 D10):
 * WebP conversions "thumb" (400 px) and "large" (1200 px), queued. The alt
 * text is stored per locale in the media custom property "alt".
 *
 * The original upload (which may carry EXIF such as GPS) is kept on the
 * private media-library.originals_disk; only the conversions are public.
 * They are re-encoded by GD, which writes no EXIF. Pages render conversions
 * only: imageUrl() is null until the queued conversion exists.
 *
 * Eager-load "media" on lists (strict models forbid lazy loading).
 */
trait HasCatalogImage
{
    use InteractsWithMedia;

    /**
     * Accepted upload types (no SVG: it can carry scripts).
     */
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Medialibrary collection that holds the image ("image", "logo", ...).
     */
    abstract public function imageCollection(): string;

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection($this->imageCollection())
            ->singleFile()
            ->acceptsMimeTypes(self::IMAGE_MIMES)
            ->useDisk(config('media-library.originals_disk', 'local'))
            ->storeConversionsOnDisk(config('media-library.disk_name', 'public'));
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->fit(Fit::Max, 400, 400)
            ->format('webp')
            ->performOnCollections($this->imageCollection())
            ->queued();

        $this->addMediaConversion('large')
            ->fit(Fit::Max, 1200, 1200)
            ->format('webp')
            ->performOnCollections($this->imageCollection())
            ->queued();
    }

    public function imageMedia(): ?Media
    {
        return $this->getFirstMedia($this->imageCollection());
    }

    /**
     * URL of a conversion; null without an image or while the queued
     * conversion hasn't run yet (the original is never public).
     */
    public function imageUrl(string $conversion = 'thumb'): ?string
    {
        $media = $this->imageMedia();

        return $media !== null && $media->hasGeneratedConversion($conversion) ? $media->getUrl($conversion) : null;
    }

    /**
     * An image is saved but its conversions are still queued.
     */
    public function imageProcessing(): bool
    {
        return $this->imageMedia() !== null && $this->imageUrl() === null;
    }

    /**
     * Alt text per locale: {uz, ru, en}.
     *
     * @return array<string, string>
     */
    public function imageAlts(): array
    {
        $alt = (array) ($this->imageMedia()?->getCustomProperty('alt') ?? []);

        return collect(config('app.supported_locales'))
            ->mapWithKeys(fn (string $locale): array => [$locale => (string) ($alt[$locale] ?? '')])
            ->all();
    }

    /**
     * Alt text in the given (or current) locale, falling back to the default locale.
     */
    public function imageAlt(?string $locale = null): string
    {
        $alts = $this->imageAlts();

        return $alts[$locale ?? app()->getLocale()] ?: ($alts[config('app.default_locale', 'uz')] ?? '');
    }
}
