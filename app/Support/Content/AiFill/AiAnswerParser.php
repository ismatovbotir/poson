<?php

namespace App\Support\Content\AiFill;

use App\Enums\AiFieldKind;
use App\Rules\BrandSafeText;
use App\Support\HtmlSanitizer;
use JsonException;

/**
 * Turns the text an admin pasted from an external AI into validated field
 * values (plan 29, conditions AI-3, AI-5, AI-6). The pasted text is untrusted:
 * size-capped, decoded with a depth limit, only whitelisted keys and locales
 * are read, only strings are accepted, control and bidi characters are
 * removed, HTML goes through HtmlSanitizer and every value must pass the
 * brand rule and its length limit. Nothing is truncated: a bad value is
 * reported and not applied. Messages are lang keys and never echo the input.
 */
class AiAnswerParser
{
    /**
     * Largest answer accepted, in bytes.
     */
    public const MAX_BYTES = 262144;

    /**
     * Most messages returned per kind.
     */
    public const MAX_MESSAGES = 10;

    private const JSON_DEPTH = 4;

    /**
     * @param  list<AiField>  $fields
     */
    public function parse(string $raw, array $fields): AiAnswerResult
    {
        if (strlen($raw) > self::MAX_BYTES) {
            return $this->failed('admin.ai_fill.errors.too_large', ['max' => self::MAX_BYTES / 1024]);
        }

        $data = $this->decode($raw);

        if ($data === null) {
            return $this->failed('admin.ai_fill.errors.invalid_json');
        }

        if ($data === [] || array_is_list($data)) {
            return $this->failed('admin.ai_fill.errors.not_object');
        }

        return $this->read($data, $fields);
    }

    /**
     * Strip control characters (keeps "\n" and "\t") and bidi overrides, and
     * normalise line breaks.
     */
    public static function cleanCharacters(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\u{2028}", "\u{2029}"], "\n", $text);

        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text);
    }

    /**
     * @return array<mixed>|null
     */
    private function decode(string $raw): ?array
    {
        if (! mb_check_encoding($raw, 'UTF-8')) {
            return null;
        }

        $raw = trim(preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw);

        if (preg_match('/\A```[A-Za-z0-9_-]*[ \t]*\n(.*?)\n?```\z/s', $raw, $match) === 1) {
            $raw = trim($match[1]);
        }

        try {
            $data = json_decode($raw, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * @param  array<mixed>  $data
     * @param  list<AiField>  $fields
     */
    private function read(array $data, array $fields): AiAnswerResult
    {
        $locales = config('app.supported_locales');
        $values = [];
        $errors = [];
        $warnings = [];
        $unknown = 0;
        $known = [];

        foreach ($fields as $field) {
            $known[$field->key] = $field;
        }

        foreach ($data as $key => $value) {
            if (! is_string($key) || ! isset($known[$key])) {
                $unknown++;

                continue;
            }

            $field = $known[$key];

            if ($value === null || $value === '') {
                continue;
            }

            if (! is_array($value) || array_is_list($value)) {
                $errors[] = $this->message($key, null, 'admin.ai_fill.errors.invalid_type');

                continue;
            }

            foreach ($value as $locale => $text) {
                if (! is_string($locale) || ! in_array($locale, $locales, true)) {
                    $unknown++;
                }
            }

            foreach ($locales as $locale) {
                if (! array_key_exists($locale, $value) || $value[$locale] === null) {
                    continue;
                }

                if (! is_string($value[$locale])) {
                    $errors[] = $this->message($key, $locale, 'admin.ai_fill.errors.invalid_type');

                    continue;
                }

                $this->readText($field, $locale, $value[$locale], $values, $errors, $warnings);
            }
        }

        if ($unknown > 0) {
            $warnings[] = $this->message(null, null, 'admin.ai_fill.warnings.unknown_keys', ['count' => $unknown]);
        }

        if ($values === [] && $errors === [] && $unknown === 0) {
            $errors[] = $this->message(null, null, 'admin.ai_fill.errors.nothing');
        }

        return new AiAnswerResult(
            $values,
            array_slice($errors, 0, self::MAX_MESSAGES),
            array_slice($warnings, 0, self::MAX_MESSAGES),
        );
    }

    /**
     * @param  array<string, array<string, string>>  $values
     * @param  list<array<string, mixed>>  $errors
     * @param  list<array<string, mixed>>  $warnings
     */
    private function readText(AiField $field, string $locale, string $raw, array &$values, array &$errors, array &$warnings): void
    {
        $text = trim(self::cleanCharacters($raw));

        if ($text === '') {
            return;
        }

        if ($field->kind === AiFieldKind::Plain) {
            if (preg_match('/<[A-Za-z\/!]/', $text) === 1) {
                $errors[] = $this->message($field->key, $locale, 'admin.ai_fill.errors.tags_not_allowed');

                return;
            }

            if (! $field->multiline) {
                $text = trim((string) preg_replace('/\s+/u', ' ', $text));
            }
        } else {
            if (preg_match('/(^|\n)\s{0,3}(#{1,6}\s|[-*+]\s|\d+\.\s)|\*\*\S|__\S/', $text) === 1) {
                $warnings[] = $this->message($field->key, $locale, 'admin.ai_fill.warnings.markdown');
            }

            $text = $this->html($text);

            if ($text === '') {
                $errors[] = $this->message($field->key, $locale, 'admin.ai_fill.errors.empty_after_clean');

                return;
            }
        }

        if ($locale === 'uz') {
            $text = UzApostrophes::normalize($text);
        }

        if (mb_strlen($text) > $field->max) {
            $errors[] = $this->message($field->key, $locale, 'admin.ai_fill.errors.too_long', ['max' => $field->max]);

            return;
        }

        if (BrandSafeText::violates(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5))) {
            $errors[] = $this->message($field->key, $locale, 'admin.ai_fill.errors.brand_unsafe');

            return;
        }

        $values[$field->key][$locale] = $text;
    }

    /**
     * Sanitized HTML; text without block tags becomes paragraphs.
     */
    private function html(string $text): string
    {
        $clean = HtmlSanitizer::clean($text);

        if ($clean === '' || trim(strip_tags($clean)) === '') {
            return '';
        }

        if (preg_match('/<(p|h2|h3|ul|ol|table)\b/i', $clean) === 1) {
            return $clean;
        }

        $paragraphs = preg_split('/\n{2,}/', $clean) ?: [$clean];

        return implode('', array_map(fn (string $paragraph): string => '<p>'.trim($paragraph).'</p>', array_filter($paragraphs, fn (string $paragraph): bool => trim($paragraph) !== '')));
    }

    /**
     * @param  array<string, int|string>  $params
     */
    private function failed(string $message, array $params = []): AiAnswerResult
    {
        return new AiAnswerResult([], [$this->message(null, null, $message, $params)]);
    }

    /**
     * @param  array<string, int|string>  $params
     * @return array{key: ?string, locale: ?string, message: string, params: array<string, int|string>}
     */
    private function message(?string $key, ?string $locale, string $message, array $params = []): array
    {
        return ['key' => $key, 'locale' => $locale, 'message' => $message, 'params' => $params];
    }
}
