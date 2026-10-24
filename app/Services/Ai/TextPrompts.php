<?php

namespace App\Services\Ai;

use App\Support\Ai\AiContext;
use App\Support\Ai\FieldRegistry;

/**
 * Prompt building and output checks shared by every TextAssistant (plan 10):
 * the user prompt of a generate / translate task, the {uz, ru, en} schema,
 * reading a JSON answer and the "every locale filled" check. The system
 * prompt is SystemPrompt::text() for all providers; BrandGuard and the
 * sanitizer run afterwards in ProcessAiRequest, the same for every provider.
 */
final class TextPrompts
{
    /**
     * Extra instruction for providers without native structured output.
     */
    public const JSON_ONLY = 'Reply only with a JSON object {"uz": "...", "ru": "...", "en": "..."}. No Markdown code fences, no text before or after the JSON.';

    /**
     * User prompt for the task (generate or translate).
     */
    public static function for(TextTask $task): string
    {
        return $task->isTranslation() ? self::translate($task) : self::generate($task);
    }

    public static function generate(TextTask $task): string
    {
        return implode("\n\n", [
            trim($task->hint),
            $task->field()->outputRules()."\nWrite the text in every language: ".self::languages().'.',
            $task->context->toPrompt(),
        ]);
    }

    public static function translate(TextTask $task): string
    {
        $from = (string) $task->sourceLocale;

        return implode("\n\n", [
            trim($task->hint),
            $task->field()->outputRules()."\nSource language: ".FieldRegistry::languageName($from)." ({$from}). Return the source text unchanged under \"{$from}\" and the translations under the other keys (".self::languages().').',
            $task->context->toPrompt(),
            "<source lang=\"{$from}\">\n".AiContext::neutralize($task->sourceText)."\n</source>",
        ]);
    }

    /**
     * JSON schema of the answer: one required string per supported locale.
     *
     * @return array{type: string, properties: array<string, array{type: string}>, required: list<string>, additionalProperties: bool}
     */
    public static function schema(): array
    {
        $locales = array_values(config('app.supported_locales'));

        return [
            'type' => 'object',
            'properties' => array_fill_keys($locales, ['type' => 'string']),
            'required' => $locales,
            'additionalProperties' => false,
        ];
    }

    /**
     * Decode a JSON answer that may be wrapped in ``` fences or surrounded by
     * text: the part from the first "{" to the last "}". Null when that is
     * not a JSON object.
     *
     * @return array<string, mixed>|null
     */
    public static function decodeJson(?string $content): ?array
    {
        $text = trim((string) $content);
        $text = (string) preg_replace('/^```[A-Za-z0-9_-]*\s*|\s*```$/', '', $text);

        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }

    /**
     * One trimmed text per locale; for a translation the source locale is the
     * unchanged source text. Null when a locale is missing or empty.
     *
     * @param  array<array-key, mixed>  $structured
     * @return array<string, string>|null
     */
    public static function texts(TextTask $task, array $structured): ?array
    {
        $texts = [];

        foreach (config('app.supported_locales') as $locale) {
            $value = $structured[$locale] ?? null;
            $texts[$locale] = is_string($value) ? trim($value) : '';
        }

        if ($task->isTranslation() && $task->sourceLocale !== null) {
            $texts[$task->sourceLocale] = $task->sourceText;
        }

        return in_array('', $texts, true) ? null : $texts;
    }

    private static function languages(): string
    {
        return implode(', ', config('app.supported_locales'));
    }
}
