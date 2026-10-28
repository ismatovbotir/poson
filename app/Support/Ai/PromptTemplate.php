<?php

namespace App\Support\Ai;

use App\Services\Ai\BrandGuard;

/**
 * Checks and preview of a hint template edited in Settings → AI prompts
 * (plan 12). Unknown or malformed placeholders block saving; a missing
 * allowed placeholder and a BrandGuard match are warnings only.
 */
final class PromptTemplate
{
    public const MIN_LENGTH = 10;

    public const MAX_LENGTH = 1200;

    /**
     * LF line endings, trimmed.
     */
    public static function normalize(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    /**
     * Placeholders the field doesn't allow ("{specs}", "{Name}", "{ name }"),
     * plus a stray "{" or "}", in order of appearance, without duplicates.
     *
     * @return list<string>
     */
    public static function unknownPlaceholders(FieldSpec $field, string $text): array
    {
        $found = [];

        preg_match_all('/\{[^{}]*\}/u', $text, $matches);

        foreach ($matches[0] as $token) {
            if (! in_array(substr($token, 1, -1), $field->placeholders, true)) {
                $found[] = $token;
            }
        }

        $rest = (string) preg_replace('/\{[^{}]*\}/u', '', $text);

        foreach (['{', '}'] as $brace) {
            if (str_contains($rest, $brace)) {
                $found[] = $brace;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Allowed placeholders the text doesn't use (names, without braces).
     *
     * @return list<string>
     */
    public static function missingPlaceholders(FieldSpec $field, string $text): array
    {
        return array_values(array_filter(
            $field->placeholders,
            fn (string $placeholder): bool => ! str_contains($text, '{'.$placeholder.'}'),
        ));
    }

    /**
     * Phrases that may break the brand rule (BrandGuard patterns).
     *
     * @return list<string>
     */
    public static function brandWarnings(string $text): array
    {
        return BrandGuard::matches($text);
    }

    /**
     * The text filled with the sample record of the field's group (lang ai.samples.*).
     */
    public static function preview(FieldSpec $field, string $text): string
    {
        return $field->hintFor(self::sampleContext($field->group), $text);
    }

    /**
     * Fixed sample context of a field group, in the admin UI locale.
     */
    public static function sampleContext(string $group): AiContext
    {
        $sample = __("ai.samples.{$group}");

        return AiContext::fromArray(is_array($sample) ? $sample : []);
    }
}
