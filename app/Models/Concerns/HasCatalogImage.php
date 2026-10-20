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
            ->acceptsMimeTypes(self::IMAGE_MIMES);
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
     * URL of a conversion, or of the original while the queued conversion
     * hasn't run yet. Null without an image.
     */
    public function imageUrl(string $conversion = 'thumb'): ?string
    {
        $media = $this->imageMedia();

        if ($media === null) {
            return null;
        }

        return $media->hasGeneratedConversion($conversion) ? $media->getUrl($conversion) : $media->getUrl();
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
