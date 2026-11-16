<?php

namespace App\Actions\Chat\Knowledge;

use Illuminate\Support\Carbon;

/**
 * One publicly visible source in one locale, as the indexer reads it: a plain
 * title, the body (site HTML or plain text) and the display URL.
 */
final readonly class KnowledgeDocument
{
    public function __construct(
        public string $locale,
        public string $title,
        public string $body,
        public string $url,
        public ?Carbon $visibleFrom = null,
    ) {}
}
