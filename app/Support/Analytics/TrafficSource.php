<?php

namespace App\Support\Analytics;

use App\Enums\TrafficChannel;

/**
 * Result of TrafficClassifier: the channel and its source (host or
 * utm_source), or "internal" for navigation within the site.
 */
final readonly class TrafficSource
{
    public function __construct(
        public ?TrafficChannel $channel,
        public ?string $source = null,
    ) {}

    public static function internal(): self
    {
        return new self(null);
    }

    /**
     * Navigation from one of the site's own pages (no channel, not an entry).
     */
    public function isInternal(): bool
    {
        return $this->channel === null;
    }
}
