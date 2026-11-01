<?php

namespace App\Mcp\Support\Presenters;

use App\Mcp\Support\UntrustedText;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;

/**
 * Translatable and long texts of MCP output (plan 13 S20, S27). Everything
 * is treated as untrusted data: control, zero-width and bidi characters are
 * removed, markup is stripped and the length is capped.
 */
final class Texts
{
    /**
     * A short translatable field as {uz, ru, en}; a missing locale is null.
     *
     * @return array<string, string|null>
     */
    public static function translated(Model $model, string $field, int $max = UntrustedText::MAX): array
    {
        $result = [];

        foreach (config('app.supported_locales') as $locale) {
            $result[$locale] = UntrustedText::clean($model->getTranslation($field, $locale, false), $max);
        }

        return $result;
    }

    /**
     * A long translatable field as plain text per locale (newlines kept).
     *
     * @return array{texts: array<string, string|null>, truncated: bool}
     */
    public static function translatedBlocks(Model $model, string $field, int $maxBytes): array
    {
        $texts = [];
        $truncated = false;

        foreach (config('app.supported_locales') as $locale) {
            $block = self::block($model->getTranslation($field, $locale, false), $maxBytes);
            $texts[$locale] = $block['text'];
            $truncated = $truncated || $block['truncated'];
        }

        return ['texts' => $texts, 'truncated' => $truncated];
    }

    /**
     * HTML or plain text to plain text with line breaks, capped in bytes.
     *
     * @return array{text: string|null, truncated: bool}
     */
    public static function block(mixed $value, int $maxBytes): array
    {
        if (! is_scalar($value)) {
            return ['text' => null, 'truncated' => false];
        }

        $text = (string) $value;

        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        $text = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#isu', ' ', $text);
        $text = (string) preg_replace('#<\s*(?:br|/p|/div|/li|/h[1-6]|/tr|/blockquote|/ul|/ol)\b[^>]*>#iu', "\n", $text);
        $text = strip_tags(str_replace('<', ' <', $text));
        $text = strip_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5));
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Control and format characters (zero-width, bidi) except the newline.
        $text = (string) preg_replace('/(?:(?!\n)\p{Cc}|\p{Cf})+/u', ' ', $text);
        $lines = array_map(
            fn (string $line): string => trim((string) preg_replace('/[\p{Zs}\t]+/u', ' ', $line)),
            explode("\n", $text),
        );
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));

        if ($text === '') {
            return ['text' => null, 'truncated' => false];
        }

        if (strlen($text) > $maxBytes) {
            return ['text' => rtrim(mb_strcut($text, 0, max(1, $maxBytes - 3), 'UTF-8')).'…', 'truncated' => true];
        }

        return ['text' => $text, 'truncated' => false];
    }

    /**
     * Output schema of a {uz, ru, en} object.
     */
    public static function schema(JsonSchema $schema): mixed
    {
        $properties = [];

        foreach (config('app.supported_locales') as $locale) {
            $properties[$locale] = $schema->string()->nullable();
        }

        return $schema->object($properties)->required();
    }
}
