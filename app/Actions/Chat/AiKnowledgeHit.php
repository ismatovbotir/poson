<?php

namespace App\Actions\Chat;

use App\Enums\AiKnowledgeSource;

/**
 * One website passage offered to the AI model for a question (plan 33 §6):
 * the chunk text and title as indexed (already prompt-safe), the source it
 * came from and the URL rebuilt from (type, slug, locale) at retrieval time.
 * Kept in job memory for the request; its position in the list is its [Sn]
 * tag (K-5).
 */
final readonly class AiKnowledgeHit
{
    public function __construct(
        public AiKnowledgeSource $type,
        public int $sourceId,
        public string $locale,
        public string $title,
        public string $text,
        public string $url,
    ) {}

    /**
     * The `<site>` entry: "[S1] Page | Title | URL" and the text below.
     */
    public function entry(int $number, string $text): string
    {
        $label = (string) __('enums.ai_knowledge_source.'.$this->type->value, [], $this->locale);

        return "[S{$number}] {$label} | {$this->title} | {$this->url}\n{$text}";
    }
}
