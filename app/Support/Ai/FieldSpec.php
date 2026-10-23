<?php

namespace App\Support\Ai;

/**
 * One AI-assisted form field (plan 02 §3), declared in FieldRegistry.
 *
 * - key: "product.short_description" (also ai_requests.field_key)
 * - type: what the text is (name, short, long, seo_title, seo_description, question, answer)
 * - maxLength: recommended length shown in the preview (character count vs max)
 * - format: plain text or sanitized HTML (rich-text fields)
 * - hint: lang key of the hint template (ai.hints.*), placeholders {category} {brand} {name} {specs} {question}
 * - label: lang key of the field label
 */
final readonly class FieldSpec
{
    public const PLAIN = 'plain';

    public const HTML = 'html';

    public function __construct(
        public string $key,
        public string $type,
        public int $maxLength,
        public string $format,
        public string $hint,
        public string $label,
    ) {}

    public function isHtml(): bool
    {
        return $this->format === self::HTML;
    }

    public function label(): string
    {
        return __($this->label);
    }

    /**
     * The hint template with the context values filled in.
     */
    public function hintFor(AiContext $context): string
    {
        return FieldRegistry::fill(__($this->hint), $context->placeholders());
    }

    /**
     * Default hint of the translate mode.
     */
    public function translateHintFor(string $sourceLocale): string
    {
        return FieldRegistry::fill(__(FieldRegistry::TRANSLATE_HINT), ['from' => FieldRegistry::localizedLanguageName($sourceLocale)]);
    }

    /**
     * Output rules for the model (appended to the user message, not editable).
     */
    public function outputRules(): string
    {
        $format = $this->isHtml()
            ? 'HTML using only <p>, <h2>, <h3>, <ul>, <ol>, <li>, <strong> and <em> (no links, no images, no inline styles, no <h1>)'
            : 'plain text (no HTML, no Markdown, no surrounding quotes)';

        return "Field: {$this->key} ({$this->type}). Format: {$format}. Length: at most {$this->maxLength} characters per language.";
    }
}
