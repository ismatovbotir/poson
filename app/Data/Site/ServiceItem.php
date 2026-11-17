<?php

namespace App\Data\Site;

/**
 * Service teaser on the home page (title and text in the current locale).
 *
 * Cached by HomeCache (plan 34): when toArray()/fromArray() change shape, raise
 * HomeCache::SCHEMA.
 */
final readonly class ServiceItem
{
    public function __construct(
        public string $key,
        public string $title,
        public string $text,
        public string $url,
    ) {}

    /**
     * Plain array for the home cache (HC-2).
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'title' => $this->title, 'text' => $this->text, 'url' => $this->url];
    }

    /**
     * @param  array<string, string>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(key: $data['key'], title: $data['title'], text: $data['text'], url: $data['url']);
    }
}
