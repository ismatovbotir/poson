<?php

namespace App\Support\Ai;

/**
 * One AI-assisted form field (plan 02 §3, plan 12), declared in FieldRegistry.
 *
 * - key: "product.short_description" (also ai_requests.field_key)
 * - group: screen group of Settings → AI prompts (FieldRegistry::GROUPS)
 * - type: what the text is (short, long, seo_title, seo_description, answer, alt, headline)
 * - maxLength: recommended length shown in the preview (character count vs max)
 * - format: plain text or sanitized HTML (rich-text fields)
 * - hint: lang key of the default hint template (ai.hints.*)
 * - label: lang key of the field label
 * - placeholders: the {placeholders} the hint may use (a subset of
 *   AiContext::placeholders(), never "specs": the specs are in <context>)
 *
 * Hint precedence: admin override (PromptOverrides) → lang default in the
 * UI locale → the manager's edit in the panel (per request, never saved).
 */
final readonly class FieldSpec
{
    public const PLAIN = 'plain';

    public const HTML = 'html';

    /**
     * @param  list<string>  $placeholders
     */
    public function __construct(
        public string $key,
        public string $type,
        public int $maxLength,
        public string $format,
        public string $hint,
        public string $label,
        public string $group,
        public array $placeholders,
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
     * The localized default hint template (lang ai.hints.*), placeholders unfilled.
     */
    public function defaultTemplate(?string $locale = null): string
    {
        return (string) __($this->hint, [], $locale);
    }

    /**
     * The hint template in use: the admin override, else the localized default.
     */
    public function template(?string $locale = null): string
    {
        return PromptOverrides::for($this->key) ?? $this->defaultTemplate($locale);
    }

    /**
     * An admin override replaces the default hint.
     */
    public function isOverridden(): bool
    {
        return PromptOverrides::for($this->key) !== null;
    }

    /**
     * The hint template with the context values of the allowed placeholders
     * filled in (any other {placeholder} is left as it is). $template: a
     * text being edited (preview); by default template().
     */
    public function hintFor(AiContext $context, ?string $template = null): string
    {
        $values = array_intersect_key($context->placeholders(), array_flip($this->placeholders));

        return FieldRegistry::fill($template ?? $this->template(), $values);
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
