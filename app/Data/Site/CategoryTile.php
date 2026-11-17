<?php

namespace App\Data\Site;

/**
 * Category tile on the home page. Mirrors the future Category model:
 * name (current locale), slug-based url, icon key from x-site.icon and
 * the number of active products (null hides the count).
 *
 * Cached by HomeCache (plan 34): when toArray()/fromArray() change shape, raise
 * HomeCache::SCHEMA.
 */
final readonly class CategoryTile
{
    public function __construct(
        public string $name,
        public string $url,
        public string $icon,
        public ?int $productsCount = null,
    ) {}

    /**
     * Plain array for the home cache (HC-2).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'url' => $this->url, 'icon' => $this->icon, 'products_count' => $this->productsCount];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            url: (string) $data['url'],
            icon: (string) $data['icon'],
            productsCount: $data['products_count'] === null ? null : (int) $data['products_count'],
        );
    }
}
