<?php

namespace App\Data\Site;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * News post card (news list, home "Latest news", product "Related news"):
 * texts in the current locale, the date in Tashkent time and the cover
 * thumbnail (null until its WebP conversion exists).
 *
 * Cached by HomeCache (plan 34): when toArray()/fromArray() change shape, raise
 * HomeCache::SCHEMA.
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

    /**
     * Plain array for the home cache (HC-2); the date is an ATOM string.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'slug' => $this->slug,
            'url' => $this->url,
            'excerpt' => $this->excerpt,
            'published_at' => $this->publishedAt?->toAtomString(),
            'image' => $this->image,
            'image_alt' => $this->imageAlt,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            title: (string) $data['title'],
            slug: (string) $data['slug'],
            url: (string) $data['url'],
            excerpt: (string) $data['excerpt'],
            publishedAt: $data['published_at'] === null ? null : Carbon::parse($data['published_at']),
            image: $data['image'],
            imageAlt: (string) $data['image_alt'],
        );
    }
}
