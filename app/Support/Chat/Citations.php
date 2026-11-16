<?php

namespace App\Support\Chat;

use Normalizer;

/**
 * The [Sn] citation tags of an AI answer (plan 33 K-5). The model may tag a
 * sentence with the entry of <site> it used: "[S2]", "[S1, S2]", "[ s1 ]",
 * "【S1】", "(S2)", ranges such as "[S1-S3]", full-width forms, any case. extract() removes every such tag from
 * the text (so no tag reaches the output filter, the stored answer, the
 * history or the customer) and returns the cited numbers that exist in this
 * request (1 to $count, first appearance order); anything else is simply
 * stripped. Pure string work.
 */
final class Citations
{
    private const TAG = '/[\[［【(（]\s*[sSｓＳ]\s*\p{Nd}+(?:\s*(?:[,;，、]|[-‐‑‒–—―−－~～])\s*[sSｓＳ]?\s*\p{Nd}+)*\s*[\]］】)）]/u';

    /**
     * @return array{text: string, cited: list<int>}
     */
    public static function extract(string $text, int $count): array
    {
        $cited = [];

        $stripped = (string) preg_replace_callback(self::TAG, function (array $match) use (&$cited, $count): string {
            $normalized = Normalizer::normalize($match[0], Normalizer::FORM_KC);
            $tag = is_string($normalized) ? $normalized : $match[0];
            preg_match_all('/(\d+)\s*[-‐‑‒–—―−~]\s*[sS]?\s*(\d+)|\d+/u', $tag, $parts, PREG_SET_ORDER);

            foreach ($parts as $part) {
                $from = (int) ($part[1] ?? $part[0]);
                $to = isset($part[2]) ? (int) $part[2] : $from;

                for ($n = max(1, $from); $n <= min($to, $count); $n++) {
                    if (! in_array($n, $cited, true)) {
                        $cited[] = $n;
                    }
                }
            }

            return '';
        }, $text);

        $stripped = (string) preg_replace('/[ \t]{2,}/u', ' ', $stripped);
        $stripped = (string) preg_replace('/[ \t]+([.,;:!?…])/u', '$1', $stripped);
        $stripped = (string) preg_replace('/[ \t]+\n/u', "\n", $stripped);

        return ['text' => trim($stripped), 'cited' => $cited];
    }
}
