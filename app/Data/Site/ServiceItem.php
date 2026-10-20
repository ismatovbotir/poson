<?php

namespace App\Data\Site;

/**
 * Service teaser on the home page (title and text in the current locale).
 */
final readonly class ServiceItem
{
    public function __construct(
        public string $key,
        public string $title,
        public string $text,
        public string $url,
    ) {}
}
