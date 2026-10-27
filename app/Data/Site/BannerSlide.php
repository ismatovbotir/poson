<?php

namespace App\Data\Site;

/**
 * A banner as the site renders it (home slider slide or category banner):
 * texts in the current locale (falling back to uz), a link that is either
 * an internal path or an external https URL, and the WebP conversions with
 * their fixed sizes (App\Models\Banner constants). The alts never fall
 * back to another locale: empty = decorative (the title is rendered as text).
 */
final readonly class BannerSlide
{
    public function __construct(
        public int $id,
        public string $title,
        public string $text,
        public string $buttonLabel,
        public ?string $link,
        public bool $external,
        public string $image,
        public ?string $imageMedium,
        public string $imageAlt,
        public ?string $mobileImage = null,
        public string $mobileImageAlt = '',
    ) {}
}
