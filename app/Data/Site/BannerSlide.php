<?php

namespace App\Data\Site;

/**
 * A banner as the site renders it (home slider slide or category banner):
 * texts in the current locale (falling back to uz), an href that is either
 * an internal path in the current locale ("/ru/catalog") or an external
 * https URL, and the WebP conversions with
 * their fixed sizes (App\Models\Banner constants). The alts never fall
 * back to another locale: empty = decorative (the title is rendered as text).
 *
 * Cached by HomeCache (plan 34): when toArray()/fromArray() change shape, raise
 * HomeCache::SCHEMA.
 */
final readonly class BannerSlide
{
    public function __construct(
        public int $id,
        public string $title,
        public string $text,
        public string $buttonLabel,
        public ?string $href,
        public bool $external,
        public string $image,
        public ?string $imageMedium,
        public string $imageAlt,
        public ?string $mobileImage = null,
        public string $mobileImageAlt = '',
    ) {}

    /**
     * Plain array for the home cache (HC-2).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'text' => $this->text,
            'button_label' => $this->buttonLabel,
            'href' => $this->href,
            'external' => $this->external,
            'image' => $this->image,
            'image_medium' => $this->imageMedium,
            'image_alt' => $this->imageAlt,
            'mobile_image' => $this->mobileImage,
            'mobile_image_alt' => $this->mobileImageAlt,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            title: (string) $data['title'],
            text: (string) $data['text'],
            buttonLabel: (string) $data['button_label'],
            href: $data['href'],
            external: (bool) $data['external'],
            image: (string) $data['image'],
            imageMedium: $data['image_medium'],
            imageAlt: (string) $data['image_alt'],
            mobileImage: $data['mobile_image'],
            mobileImageAlt: (string) $data['mobile_image_alt'],
        );
    }
}
