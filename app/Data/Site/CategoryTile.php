<?php

namespace App\Data\Site;

/**
 * Category tile on the home page. Mirrors the future Category model:
 * name (current locale), slug-based url, icon key from x-site.icon and
 * the number of active products (null hides the count).
 */
final readonly class CategoryTile
{
    public function __construct(
        public string $name,
        public string $url,
        public string $icon,
        public ?int $productsCount = null,
    ) {}
}
