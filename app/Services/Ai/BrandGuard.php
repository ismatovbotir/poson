<?php

namespace App\Services\Ai;

use Illuminate\Support\HtmlString;

/**
 * Brand rule check of AI output (CLAUDE.md "Brand positioning", plan 02
 * §1.2). Reuses config('brand.banned_patterns') — the same patterns the
 * BrandSafeText rule blocks on save — plus the softer
 * config('ai-assist.warning_patterns'). It only warns: matches are stored
 * on the request row and highlighted in the preview; the manager decides.
 */
final class BrandGuard
{
    /**
     * Matched phrases per locale (locales without matches left out).
     *
     * @param  array<string, string>  $texts  locale => text (plain or HTML)
     * @return array<string, list<string>>
     */
    public static function check(array $texts): array
    {
        $warnings = [];

        foreach ($texts as $locale => $text) {
            $matches = self::matches((string) $text);

            if ($matches !== []) {
                $warnings[(string) $locale] = $matches;
            }
        }

        return $warnings;
    }

    /**
     * Distinct matched phrases of one text, lowercased, in order of appearance.
     *
     * @return list<string>
     */
    public static function matches(string $text): array
    {
        $plain = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $found = [];

        foreach (self::ranges($plain) as [$offset, $length]) {
            $found[] = mb_strtolower(substr($plain, $offset, $length));
        }

        return array_values(array_unique($found));
    }

    /**
     * Plain text, HTML-escaped, with every match wrapped in <mark>.
     */
    public static function highlight(string $text): HtmlString
    {
        $ranges = self::ranges($text);
        $html = '';
        $position = 0;

        foreach ($ranges as [$offset, $length]) {
            $html .= e(substr($text, $position, $offset - $position));
            $html .= '<mark class="rounded bg-amber-100 px-0.5 text-amber-900" data-brand-warning>'.e(substr($text, $offset, $length)).'</mark>';
            $position = $offset + $length;
        }

        return new HtmlString($html.e(substr($text, $position)));
    }

    /**
     * @return list<string>
     */
    public static function patterns(): array
    {
        return array_values(array_filter([
            ...(array) config('brand.banned_patterns', []),
            ...(array) config('ai-assist.warning_patterns', []),
        ], is_string(...)));
    }

    /**
     * Byte ranges [offset, length] of all matches, sorted and merged.
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function ranges(string $text): array
    {
        $ranges = [];

        foreach (self::patterns() as $pattern) {
            // The shared patterns are written for lowercased text; match case-insensitively instead.
            if (preg_match_all(self::caseInsensitive($pattern), $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($matches[0] as [$match, $offset]) {
                    if ($match !== '') {
                        $ranges[] = [$offset, strlen($match)];
                    }
                }
            }
        }

        usort($ranges, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $merged = [];

        foreach ($ranges as [$offset, $length]) {
            $last = count($merged) - 1;

            if ($last >= 0 && $offset <= $merged[$last][0] + $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $offset + $length - $merged[$last][0]);

                continue;
            }

            $merged[] = [$offset, $length];
        }

        return $merged;
    }

    private static function caseInsensitive(string $pattern): string
    {
        $delimiter = $pattern[0];
        $end = strrpos($pattern, $delimiter);

        if ($end === false || $end === 0) {
            return $pattern;
        }

        $modifiers = substr($pattern, $end + 1);

        return str_contains($modifiers, 'i') ? $pattern : $pattern.'i';
    }
}
