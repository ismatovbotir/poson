<?php

namespace App\Data\Site;

use Carbon\CarbonInterface;

/**
 * News post card (news list, home "Latest news", product "Related news"):
 * texts in the current locale, the date in Tashkent time and the cover
 * thumbnail (null until its WebP conversion exists).
 */
final readonly class NewsCard
{
    public function __construct(
        public string $title,
        public string $slug,
        public string $url,
        public string $excerpt,
        public ?CarbonInterface $publishedAt,
        public ?string $image = null,
        public string $imageAlt = '',
    ) {}
}
