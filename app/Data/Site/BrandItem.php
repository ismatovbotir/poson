<?php

namespace App\Data\Site;

/**
 * Brand in the home page strip (name, slug, brand page URL, logo);
 * without a logo the name is shown as text.
 *
 * Cached by HomeCache (plan 34): when toArray()/fromArray() change shape, raise
 * HomeCache::SCHEMA.
 */
final readonly class BrandItem
{
    public function __construct(
        public string $name,
        public string $slug,
        public string $url,
        public ?string $logo = null,
    ) {}

    /**
     * Plain array for the home cache (HC-2).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'slug' => $this->slug, 'url' => $this->url, 'logo' => $this->logo];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(name: (string) $data['name'], slug: (string) $data['slug'], url: (string) $data['url'], logo: $data['logo']);
    }
}
