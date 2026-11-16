<?php

namespace App\Support\Chat;

use App\Support\HtmlSanitizer;
use Normalizer;

/**
 * The text pipeline of the website knowledge index (plan 33 §4, K-2, K-3,
 * K-4): site HTML becomes plain, prompt-safe text that is split into chunks.
 *
 *  - fromHtml(): HtmlSanitizer, block tags to line breaks, strip_tags,
 *    entity decode, NFKC, citation look-alikes ("[S3]", "【S1】", "(S2)") and
 *    prompt tag words ("<site>", "</knowledge>") removed, then
 *    AiPrompt::field (control and format characters, angle brackets);
 *  - maskAmounts(): money amounts replaced by a short "see the page" phrase,
 *    found by the AI output filter's own detector on the string it reads;
 *  - chunks(): paragraphs, then sentences, then words, each at most $max
 *    characters;
 *  - looksLikeInstruction(): text that talks to the model (K-4).
 * Pure functions: no database, no HTTP.
 */
final class KnowledgeText
{
    /**
     * Citation look-alikes: "[S3]", "[ s1 ]", "【S1】", "(S2)", "[S1, S2]".
     */
    private const CITATION = '/[\[【(（]\s*s\s*\d+(?:\s*[,;，、]\s*s?\s*\d+)*\s*[\]】)）]/iu';

    /**
     * Prompt tag words as text: "<site>", "‹/knowledge›", "&lt;products&gt;".
     */
    private const TAG_WORD = '/[<‹＜]\s*\/?\s*(?:site|knowledge|products?|customer_message|context|system|assistant|user)\b[^>›＞\n]{0,40}[>›＞]?/iu';

    /**
     * Instruction-like phrases (uz, ru, en); apostrophes are any of the
     * Uzbek forms. Matched on the lowercase NFKC text.
     */
    private const INSTRUCTIONS = [
        '/(?<!\pL)(?:ignore|disregard|forget|override)\s+(?:all\s+|any\s+|the\s+|your\s+|these\s+|those\s+)*(?:previous|prior|above|earlier|preceding|system|safety)?\s*(?:instructions?|prompts?|rules?|guidelines?|messages?)/u',
        '/(?<!\pL)(?:system|developer)\s+(?:prompt|message|instructions?)/u',
        '/(?<!\pL)you\s+are\s+now(?!\pL)/u',
        '/(?<!\pL)(?:new|updated)\s+instructions?/u',
        '/(?<!\pL)(?:pretend|act)\s+(?:to\s+be|as\s+(?:if|an?|the))/u',
        '/(?<!\pL)jailbreak|(?<!\pL)do\s+anything\s+now/u',
        '/(?<!\pL)(?:reveal|repeat|print|show)\s+(?:your|the)\s+(?:instructions|prompt|rules)/u',
        '/(?<!\pL)(?:игнорируй|игнорируйте|забудь|забудьте|отмени|отмените)\s+\pL*\s*\pL*\s*(?:инструкци|указани|правил|промпт)/u',
        '/(?<!\pL)(?:предыдущ|прежн|вышеуказанн|системн)\pL*\s+(?:инструкци|указани|правил|промпт|сообщени)/u',
        '/(?<!\pL)ты\s+теперь(?!\pL)|(?<!\pL)новые\s+инструкци/u',
        '/(?<!\pL)(?:oldingi|avvalgi|yuqoridagi|tizim)\s+(?:ko[\'ʻʼ‘’`]?rsatma|yo[\'ʻʼ‘’`]?riqnoma|qoida|prompt|xabar)/u',
        '/(?<!\pL)(?:e[\'ʻʼ‘’`]?tibor\s+bermang|unuting|bekor\s+qiling)\s+\pL*\s*(?:ko[\'ʻʼ‘’`]?rsatma|yo[\'ʻʼ‘’`]?riqnoma|qoida|prompt)/u',
        '/(?<!\pL)sen\s+endi(?!\pL)|(?<!\pL)yangi\s+(?:ko[\'ʻʼ‘’`]?rsatma|yo[\'ʻʼ‘’`]?riqnoma)/u',
    ];

    /**
     * Site HTML to prompt-safe plain text (K-2), line breaks kept.
     */
    public static function fromHtml(?string $html, int $max = 8000): string
    {
        $html = HtmlSanitizer::clean($html);
        $html = (string) preg_replace('~</?(?:p|h[1-6]|ul|ol|li|tr|table|thead|tbody|div)\b[^>]*>|<br\s*/?>~i', "\n", $html);
        $html = (string) preg_replace('~</t[dh]>~i', ' ', $html);

        $text = self::clean(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $max);

        return (string) preg_replace('/\n{2,}/', "\n", $text);
    }

    /**
     * Plain text (a title, a short description) to prompt-safe text (K-2).
     */
    public static function clean(?string $text, int $max): string
    {
        $text = mb_scrub((string) $text, 'UTF-8');
        $nfkc = Normalizer::normalize($text, Normalizer::FORM_KC);
        $text = is_string($nfkc) ? $nfkc : $text;
        // Format characters (zero-width, bidi) vanish without a gap, so no word can be split to hide a phrase.
        $text = (string) preg_replace('/\p{Cf}/u', '', $text);
        $text = (string) preg_replace(self::TAG_WORD, ' ', $text);
        $text = (string) preg_replace(self::CITATION, ' ', $text);
        $text = AiPrompt::field($text, $max);

        return trim((string) preg_replace('/[ \t]*\n[ \t]*/u', "\n", $text));
    }

    /**
     * Money amounts replaced by $mask (K-3). The detector reads NFKC, lowercase,
     * ASCII-digit text, so the amounts are found on that string; when its
     * length equals the text's the original letters are kept, otherwise the
     * normalized string is used (offsets never drift).
     */
    public static function maskAmounts(string $text, string $mask): string
    {
        $normalized = AiOutputFilter::normalizeForAmounts($text);
        $spans = AiOutputFilter::moneySpans($normalized);

        if ($spans === []) {
            return $text;
        }

        $chars = mb_str_split($text);
        $ranges = [];
        $offset = 0;
        $rebuilt = '';

        // Where each original character sits in the normalized string (a character may grow or shrink there).
        foreach ($chars as $index => $char) {
            $piece = AiOutputFilter::normalizeForAmounts($char);
            $ranges[$index] = [$offset, $offset + strlen($piece)];
            $offset += strlen($piece);
            $rebuilt .= $piece;
        }

        if ($rebuilt !== $normalized) {
            // The pieces do not add up to the whole (a combining sequence): mask on the normalized string itself, never on drifting offsets.
            return self::replaceBytes($normalized, $spans, $mask);
        }

        $cover = [];

        foreach ($spans as $span) {
            $end = $span['start'] + $span['length'];
            $first = null;
            $last = null;

            foreach ($ranges as $index => [$from, $to]) {
                if ($to > $span['start'] && $from < $end) {
                    $first ??= $index;
                    $last = $index;
                }
            }

            if ($first !== null) {
                $cover[] = [$first, $last];
            }
        }

        usort($cover, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];

        foreach ($cover as [$first, $last]) {
            if ($merged !== [] && $first <= $merged[array_key_last($merged)][1] + 0) {
                $merged[array_key_last($merged)][1] = max($merged[array_key_last($merged)][1], $last);
            } else {
                $merged[] = [$first, $last];
            }
        }

        $result = '';
        $position = 0;

        foreach ($merged as [$first, $last]) {
            $result .= implode('', array_slice($chars, $position, $first - $position)).$mask;
            $position = $last + 1;
        }

        return $result.implode('', array_slice($chars, $position));
    }

    /**
     * @param  list<array{start: int, length: int}>  $spans
     */
    private static function replaceBytes(string $base, array $spans, string $mask): string
    {
        foreach (array_reverse($spans) as $span) {
            $base = substr($base, 0, $span['start']).$mask.substr($base, $span['start'] + $span['length']);
        }

        return $base;
    }

    /**
     * A percentage with digits ("100%", "12 %", "20 foiz", "15 процентов",
     * "5 percent") is an amount like a price: replaced by $mask. A bare "%"
     * or a percent word without a number stays, so the output filter still
     * skips that chunk (fails closed).
     */
    public static function maskPercents(string $text, string $mask): string
    {
        $normalized = (string) Normalizer::normalize($text, Normalizer::FORM_KC);
        $text = $normalized !== '' ? $normalized : $text;

        return (string) preg_replace_callback(
            '/(?<![\pL\pN.,])\d+(?:[.,]\d+)?[ \x{00A0}]?(?:%|(?<!\pL)(?:foiz|процент|percent|per[ ]?cent)\pL*)/iu',
            function (array $match) use ($text, $mask): string {
                $before = mb_substr(substr($text, max(0, $match[0][1] - 24), min(24, $match[0][1])), -12);
                $after = substr($text, $match[0][1] + strlen($match[0][0]), 24);

                // A promotional percentage ("-20%", "up to 30%", "15% off", "20% chegirma") stays so the brand check skips the chunk (L-2).
                $promo = preg_match('/(?:[−–—-]|(?<!\pL)до|up\s+to|(?<!\pL)gacha)\s*$/iu', mb_scrub($before, 'UTF-8')) === 1
                    || preg_match('/^\s*(?:off(?!\pL)|скидк|chegirma|arzon|gacha(?!\pL)|до(?!\pL))/iu', mb_scrub($after, 'UTF-8')) === 1;

                return $promo ? $match[0][0] : $mask;
            },
            $text,
            -1,
            $count,
            PREG_OFFSET_CAPTURE,
        );
    }

    /**
     * Split into chunks of at most $max characters (at most $limit chunks):
     * paragraphs are packed together, a longer one splits at sentences, then
     * at words. Text past the last chunk is dropped.
     *
     * @return list<string>
     */
    public static function chunks(string $text, int $max = 1200, int $limit = 6): array
    {
        $pieces = [];

        foreach (preg_split('/\n+/u', trim($text)) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            if (mb_strlen($paragraph) <= $max) {
                $pieces[] = $paragraph;

                continue;
            }

            foreach (self::sentences($paragraph, $max) as $piece) {
                $pieces[] = $piece;
            }
        }

        $chunks = [];
        $current = '';

        foreach ($pieces as $piece) {
            if ($current !== '' && mb_strlen($current) + 1 + mb_strlen($piece) > $max) {
                $chunks[] = $current;
                $current = '';
            }

            $current = $current === '' ? $piece : $current."\n".$piece;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return array_slice($chunks, 0, max(0, $limit));
    }

    /**
     * Whether the text tries to instruct the model (K-4).
     */
    public static function looksLikeInstruction(string $text): bool
    {
        $lower = mb_strtolower(AiOutputFilter::normalize($text));

        foreach (self::INSTRUCTIONS as $pattern) {
            if (preg_match($pattern, $lower) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * One long paragraph as pieces of at most $max characters: at sentence
     * ends where it can, else at words, else hard.
     *
     * @return list<string>
     */
    private static function sentences(string $paragraph, int $max): array
    {
        $pieces = [];
        $current = '';

        foreach (preg_split('/(?<=[.!?…])\s+/u', $paragraph) ?: [] as $sentence) {
            foreach (self::words($sentence, $max) as $part) {
                if ($current !== '' && mb_strlen($current) + 1 + mb_strlen($part) > $max) {
                    $pieces[] = $current;
                    $current = '';
                }

                $current = $current === '' ? $part : $current.' '.$part;
            }
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /**
     * A sentence cut at spaces (or hard) into parts of at most $max characters.
     *
     * @return list<string>
     */
    private static function words(string $sentence, int $max): array
    {
        $parts = [];

        while (mb_strlen($sentence) > $max) {
            $cut = mb_substr($sentence, 0, $max);
            $space = mb_strrpos($cut, ' ');
            $at = $space !== false && $space > $max / 2 ? $space : $max;
            $parts[] = rtrim(mb_substr($sentence, 0, $at));
            $sentence = ltrim(mb_substr($sentence, $at));
        }

        if ($sentence !== '') {
            $parts[] = $sentence;
        }

        return $parts;
    }
}
