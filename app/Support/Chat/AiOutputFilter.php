<?php

namespace App\Support\Chat;

use App\Rules\BrandSafeText;
use Normalizer;

/**
 * The hard stop on what the AI model wrote (plan 30 §6, P7, AI-11 – AI-13),
 * applied after truncation and before anything is stored or sent. Pure:
 * string functions on capped text, no database, no network.
 *
 * Cleaning: invalid UTF-8 scrubbed, NFKC-normalized (fullwidth letters and
 * dots become ASCII; "。" and "｡" become "."), control and format characters
 * removed (line breaks kept), Markdown markers removed, a max_tokens answer
 * cut to its last full sentence, capped to chatbot.ai.answer_max.
 *
 * Blocked (the rule reply is sent instead), with the reason:
 *  - empty: nothing left;
 *  - link: any host (label.label…, last label 2-24 Latin letters that are
 *    not a file extension, an xn-- label or a real Cyrillic IDN TLD such as
 *    рф/рус/қаз — so "ул.Навои", "г.Ташкент", "ZD421.pdf" pass;
 *    self::DOMAIN) that is not the site's own host or its www
 *    twin, any URL with user info ("https://poson.uz@evil.com"), t.me links,
 *    tel:/tg:/mailto: … schemes;
 *  - contact: an @mention, or an email that is not the company email;
 *  - card: a card-like run of 13-19 digits (an EAN/GTIN after "EAN",
 *    "barcode", "shtrix", "штрих" is not a card);
 *  - phone: any other run of 9+ digits that is not a company phone;
 *  - brand: BrandSafeText wording, the AI-only list (best price, affordable,
 *    выгодн…, competitor price comparisons …) and ANY "%": phase A blocks
 *    every percentage (AI-12), also technical ones ("99 % uptime"), because
 *    a discount and a spec cannot be told apart reliably;
 *  - human: the model claims to be a person;
 *  - money: an amount with a currency (soʻm, сум, UZS, $ …, with or without
 *    a scale word: ming, mln, тыс., млн …), a number with a scale word, a
 *    number in thousands groups that is not followed by a unit (mm, kg,
 *    dpi, labels, dona …), or any run of 6+ digits — unless it equals one of
 *    the allowed Retail prices (none in phase A, so every amount is blocked).
 *    Amounts in words ("besh yuz ming soʻm", "пятьсот тысяч сумов") are
 *    caught because, with no allowed amounts, ANY currency word blocks, and
 *    a scale word followed by a currency always blocks.
 *    Known phase A cost: 6+ digit part numbers, SKUs and unlabelled EANs also
 *    fall back to the rule reply (counted as "blocked" in Stats); tuned in
 *    phase B together with product retrieval.
 *
 * Detection runs on an NFKC copy (look-alike letters and dots folded); the
 * customer gets the NFC-cleaned original ("№", "…", "m²", ʻ/ʼ unchanged).
 */
final class AiOutputFilter
{
    /**
     * A bare host: one or more labels, then a last label of 2-24 letters (any
     * script) or an xn-- label. Shared with the instructions validator.
     */
    public const DOMAIN = '~(?<![\pL\pN@._-])((?:[\pL\pN](?:[\pL\pN-]{0,62})\.)+(?:(?!(?:pdf|docx?|xlsx?|pptx?|csv|txt|jpe?g|png|gif|webp|svg|zip|rar|exe|bin|hex)(?![\pL\pN-]))[a-z]{2,24}|xn--[a-z0-9-]{1,59}|рф|рус|укр|бел|срб|мкд|қаз|каз|мон|орг|ком|сайт|онлайн|дети|москва|рв|упр))(?![\pL\pN-])~u';

    private const BRAND_PATTERNS = [
        '/%/u',
        '/past\s+narx/u',
        '/qulay\s+narx/u',
        '/eng\s+(?:yaxshi|arzon|past)\s+narx/u',
        '/(?<!\pL)aksiya/u',
        '/(?<!\pL)низк\pL*\s+цен/u',
        '/(?<!\pL)лучш\pL*\s+цен/u',
        '/(?<!\pL)доступн\pL*\s+цен/u',
        '/(?<!\pL)выгодн/u',
        '/(?<!\pL)распродаж/u',
        '/\baffordabl/u',
        '/\bbest\s+prices?\b/u',
        '/\blow(?:est)?[\s-]+(?:cost|price)/u',
        '/\binexpensive\b/u',
        '/\bbargain/u',
        '/\bspecial\s+offers?\b/u',
        // Competitor and price comparisons (M4).
        '/(?<!\pL)past(?:roq)?(?!\pL).{0,30}(?:narx|цен|price)|(?:narx|цен|price)\pL*.{0,30}(?<!\pL)past(?:roq)?(?!\pL)/u',
        '/(?<!\pL)boshqalar\pL*dan/u',
        '/(?<!\pL)конкурент/u',
        '/(?<!\pL)(?:чем|than)\s+(?:у\s+)?(?:других|others|competitors)/u',
        '/cheaper/iu',
    ];

    private const HUMAN_PATTERNS = [
        '/\b(?:i\s+am|i\'m|i’m|im)\s+(?:a\s+)?(?:real\s+|live\s+)?(?:human|person|man|woman|manager|staff\s+member|employee)\b/u',
        '/\bnot\s+an?\s+(?:bot|ai|robot)\b/u',
        '/(?<!\pL)я\s+(?:живой\s+|реальный\s+)?(?:человек|сотрудник|менеджер)/u',
        '/(?<!\pL)я\s+не\s+(?:бот|робот|ии)/u',
        '/(?<!\pL)men\s+(?:haqiqiy\s+|tirik\s+)?(?:odamman|insonman|menejerman|xodimman)/u',
        '/(?<!\pL)(?:bot|robot)\s+emasman/u',
    ];

    private const CURRENCY = '(?:so[\'ʻʼ‘’`]?m(?:ga|dan|lik|ni|da|li)?|сўм\pL{0,3}|сум(?:ов|ы|а|ам|ах)?|uzs|sum|usd|\$|долл\pL*|dollar\pL*|у\.\s?е\.|y\.\s?e\.|€|eur|euro?s?|руб(?:л\pL*)?\.?|rub)';

    /**
     * Scale words that make any number an amount, also without a currency.
     */
    private const SCALE = '(?:ming|mln\.?|million\pL*|mlrd\.?|milliard\pL*|тыс\.?\pL*|млн\.?|миллион\pL*|млрд\.?|миллиард\pL*|thousand\pL*)';

    /**
     * Units after a number in thousands groups: a quantity or a spec, not money.
     */
    private const UNITS = '(?:mm|cm|km|m|kg|g|dpi|pcs|шт\.?|dona|ta|etiket\pL*|yorliq\pL*|labels?|cycles?|rolls?|мм|см|км|м|кг|г|метр\pL*|этикет\pL*|рулон\pL*|mah|мач)';

    private const EAN_LABEL = '(?:ean(?:-?(?:8|13))?|gtin|upc|barcode|bar\s+code|(?:shtrix|штрих)\pL*(?:[\s-]*(?:kod|код)\pL*)?)[\s:#№-]*';

    private const EAN_WORDS = '/'.self::EAN_LABEL.'$/u';

    /**
     * @param  list<string>  $hosts  Lowercased hosts links may point to
     * @param  list<string>  $phones  Company phone numbers (any format)
     * @param  list<string>  $emails  Company emails
     * @param  list<string>  $amounts  Formatted Retail prices of the request's candidates
     */
    public function __construct(
        private readonly array $hosts = [],
        private readonly array $phones = [],
        private readonly array $emails = [],
        private readonly array $amounts = [],
    ) {}

    /**
     * @return array{text: string, blocked: ?string}
     */
    public function apply(string $text, bool $truncated = false): array
    {
        $text = $this->clean($text);

        if ($truncated) {
            $text = $this->lastSentence($text);
        }

        $text = $this->cap($text, (int) config('chatbot.ai.answer_max', 1000));

        if (trim($text) === '') {
            return ['text' => '', 'blocked' => 'empty'];
        }

        return ['text' => $text, 'blocked' => $this->violation($text)];
    }

    /**
     * NFKC plus the ideographic full stops as ".", so look-alike dots and letters cannot hide a host (H2).
     */
    public static function normalize(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $normalized = Normalizer::normalize($text, Normalizer::FORM_KC);

        return str_replace(['。', '．', '｡'], '.', is_string($normalized) ? $normalized : $text);
    }

    private function clean(string $text): string
    {
        $text = mb_substr(mb_scrub($text, 'UTF-8'), 0, 8000);
        $nfc = Normalizer::normalize($text, Normalizer::FORM_C);
        $text = is_string($nfc) ? $nfc : $text;
        $text = str_replace("\r", '', $text);
        $text = (string) preg_replace('/[^\P{C}\n]/u', '', $text);
        $text = (string) preg_replace('/\[([^\]\n]{0,200})\]\(([^)\s]{0,500})\)/u', '$1 $2', $text);
        $text = str_replace(['**', '__', '`'], '', $text);
        $text = (string) preg_replace('/^[ \t]*#{1,6}[ \t]+/mu', '', $text);
        $text = (string) preg_replace('/^[ \t]*>[ \t]?/mu', '', $text);
        $text = (string) preg_replace('/^([ \t]*)[*•][ \t]+/mu', '$1- ', $text);
        $text = (string) preg_replace('/[ \t]+/u', ' ', $text);
        $text = (string) preg_replace('/ *\n */u', "\n", $text);

        return trim((string) preg_replace('/\n{3,}/u', "\n\n", $text));
    }

    /**
     * A max_tokens answer: up to its last full sentence (the whole text when it has none).
     */
    private function lastSentence(string $text): string
    {
        $positions = [];

        foreach (['.', '!', '?', '…'] as $mark) {
            $position = mb_strrpos($text, $mark);

            if ($position !== false) {
                $positions[] = $position;
            }
        }

        if ($positions === []) {
            return $text;
        }

        return trim(mb_substr($text, 0, max($positions) + 1));
    }

    private function cap(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, max(0, $max - 1));
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > $max / 2 ? mb_substr($cut, 0, $space) : $cut).'…';
    }

    private function violation(string $text): ?string
    {
        $lower = mb_strtolower(self::normalize($text));
        $digits = $this->asciiDigits($lower);

        return match (true) {
            $this->hasForeignLink($lower) => 'link',
            $this->hasForeignContact($lower) => 'contact',
            ($long = $this->longNumber($digits)) !== null => $long,
            BrandSafeText::violates($lower) || $this->matchesAny($lower, self::BRAND_PATTERNS) => 'brand',
            $this->matchesAny($lower, self::HUMAN_PATTERNS) => 'human',
            $this->hasForeignAmount($this->withoutAllowedContacts($digits)) => 'money',
            default => null,
        };
    }

    private function hasForeignLink(string $text): bool
    {
        if (preg_match('~(?<![\pL\d])(?:tel|tg|mailto|sms|viber|whatsapp|intent|javascript|data|file):~u', $text) === 1) {
            return true;
        }

        if (preg_match('~(?<![\pL\d])t\.me/|telegram\.me/~u', $text) === 1) {
            return true;
        }

        preg_match_all('~[a-z][a-z0-9+.-]{1,15}://([^\s/?#<>"\']*)~u', $text, $urls);

        foreach ($urls[1] as $authority) {
            if (str_contains($authority, '@') || ! $this->isOwnHost((string) preg_replace('~:\d*$~', '', $authority))) {
                return true;
            }
        }

        // Own-host URLs are fine (their paths may look like hosts); every other host is a link.
        $rest = (string) preg_replace('~[a-z][a-z0-9+.-]{1,15}://\S*~u', ' ', $text);
        preg_match_all(self::DOMAIN, $rest, $hosts);

        foreach ($hosts[1] as $host) {
            if (! $this->isOwnHost($host)) {
                return true;
            }
        }

        return false;
    }

    private function isOwnHost(string $host): bool
    {
        return in_array(rtrim($host, '.'), $this->hosts, true);
    }

    private function hasForeignContact(string $text): bool
    {
        preg_match_all('/[\pL\d._%+-]+@[\pL\d-]+(?:\.[\pL\d-]+)+/u', $text, $emails);

        $known = array_map(fn (string $email): string => mb_strtolower(trim($email)), $this->emails);

        foreach ($emails[0] as $email) {
            if (! in_array(rtrim($email, '.'), $known, true)) {
                return true;
            }
        }

        $withoutEmails = (string) preg_replace('/[\pL\d._%+-]+@[\pL\d-]+(?:\.[\pL\d-]+)+/u', ' ', $text);

        return preg_match('/(?<![\pL\d._])@[\pL\d_]{2,}/u', $withoutEmails) === 1;
    }

    /**
     * card (13-19 digits) or phone (9+ digits that are not a company phone), across separators.
     */
    private function longNumber(string $text): ?string
    {
        preg_match_all('/\+?\d(?:[ \-().\/]{0,2}\d){8,}/u', $text, $matches, PREG_OFFSET_CAPTURE);

        $company = $this->companyPhones();

        foreach ($matches[0] as [$match, $offset]) {
            $digits = (string) preg_replace('/\D/', '', $match);

            if ($this->isLabelledBarcode($text, $offset, $digits)) {
                continue;
            }

            if (strlen($digits) >= 13 && strlen($digits) <= 19) {
                return 'card';
            }

            if (! in_array(substr($digits, -9), $company, true) || strlen($digits) > 12) {
                return 'phone';
            }
        }

        return null;
    }

    /**
     * An 8/12/13/14-digit code right after "EAN", "barcode", "shtrix", "штрих" … (a product code, not a card or phone).
     */
    private function isLabelledBarcode(string $text, int $offset, string $digits): bool
    {
        if (! in_array(strlen($digits), [8, 12, 13, 14], true)) {
            return false;
        }

        $before = mb_scrub(substr($text, max(0, $offset - 40), min(40, $offset)), 'UTF-8');

        return preg_match(self::EAN_WORDS, $before) === 1;
    }

    /**
     * @return list<string> last 9 digits of every company phone
     */
    private function companyPhones(): array
    {
        return array_values(array_filter(
            array_map(fn (string $phone): string => substr((string) preg_replace('/\D/', '', $phone), -9), $this->phones),
            fn (string $digits): bool => strlen($digits) === 9,
        ));
    }

    /**
     * The text without own-host URLs, allowed long numbers (company phones, labelled
     * barcodes), so their digits are not read as amounts.
     */
    private function withoutAllowedContacts(string $text): string
    {
        $text = (string) preg_replace('~[a-z][a-z0-9+.-]{1,15}://\S+~u', ' ', $text);

        // Runs of 9+ digits got here only as company phones or labelled barcodes (longNumber blocks the rest).
        $text = (string) preg_replace('/'.self::EAN_LABEL.'\d{8,14}(?!\d)/u', ' ', $text);

        return (string) preg_replace('/\+?\d(?:[ \-().\/]{0,2}\d){8,}/u', ' ', $text);
    }

    private function hasForeignAmount(string $text): bool
    {
        $number = '\d(?:[\d \x{00A0}\x{202F}.,]*\d)?';
        $patterns = [
            '/'.$number.'\s*(?:'.self::SCALE.'\s*|(?:k|m)\s*)?'.self::CURRENCY.'(?!\pL)/u',
            '/(?<![\pL])'.self::CURRENCY.'\s*'.$number.'/u',
            '/'.$number.'\s*'.self::SCALE.'(?!\pL)/u',
            '/(?<![\d.,])\d{1,3}(?:[ \x{00A0}\x{202F}.,]\d{3})+(?!\d)(?!\s*'.self::UNITS.'(?!\pL))/u',
            '/\d{6,}/u',
            // Always: a scale word right before a currency ("yarim million soʻm"), whatever the digits.
            '/(?<!\pL)'.self::SCALE.'\s*'.self::CURRENCY.'(?!\pL)/u',
        ];

        // Phase A (no allowed amounts): any currency word is money, also for amounts in words.
        if ($this->amounts === []) {
            $patterns[] = '/(?<![\pL])'.self::CURRENCY.'(?!\pL)/u';
        }

        $allowed = array_values(array_filter(array_map(fn (string $amount): string => (string) preg_replace('/\D/', '', $amount), $this->amounts)));

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $text, $matches);

            foreach ($matches[0] as $match) {
                $digits = (string) preg_replace('/\D/', '', $match);

                if ($digits === '' || ! in_array($digits, $allowed, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matchesAny(string $text, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function asciiDigits(string $text): string
    {
        return (string) preg_replace_callback('/\p{Nd}/u', function (array $match): string {
            $value = \IntlChar::charDigitValue(mb_ord($match[0], 'UTF-8'));

            return $value >= 0 ? (string) $value : '0';
        }, $text);
    }
}
