<?php

namespace App\Data\Site;

/**
 * Brand in the home page strip (name, slug, brand page URL, logo);
 * without a logo the name is shown as text.
 */
final readonly class BrandItem
{
    public function __construct(
        public string $name,
        public string $slug,
        public string $url,
        public ?string $logo = null,
    ) {}
}
