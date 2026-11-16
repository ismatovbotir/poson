<?php

namespace App\Support\Content\AiProduct;

use App\Rules\BrandSafeText;
use App\Support\Chat\AiOutputFilter;
use App\Support\HtmlSanitizer;

/**
 * Post-checks of every AI product wizard text (plan 32 PW-15, AI-11-13):
 * names, descriptions, meta, alts, text specs and facts, every locale, plain
 * and HTML. Reuses the chat output filter's patterns (AiOutputFilter).
 *
 *  Removed (warning "link_removed" / "contact_removed"):
 *   - <a> whose host is not the site's own (the label stays), tel:/tg:/mailto: links;
 *   - plain URLs and bare domains of other hosts, t.me links;
 *   - emails, @handles, phone-like runs of 9+ digits (a labelled EAN/GTIN stays).
 *  Error (the value is not used):
 *   - "money": a currency amount (soʻm, сум, UZS, $, USD, €, руб … with a number, or a scale word);
 *   - "discount": discount / low-price wording (BrandSafeText, the chat AI list, −N %, N % off/скидк/chegirma).
 *  Warning only: "percent" for a bare "%" (spec values such as "90 %" humidity);
 *   "promise" for invented commercial promises (L12): free delivery / setup,
 *   a warranty with a number, "official dealer / distributor", certificates —
 *   the user checks them against what Poson really offers.
 * HTML is sanitized BEFORE the link checks (L1: an unclosed or entity-encoded
 * <a> is normalized first, so the checks see what the browser would) and
 * again after them.
 * Messages are keys (admin.ai_wizard.guard.*) and never echo the matched text.
 */
final class ProductTextGuard
{
    private const DISCOUNT = [
        '/(?<![\pL\pN])[−–-]\s?\d+(?:[.,]\d+)?\s?%/u',
        '/\d+(?:[.,]\d+)?\s?%\s*(?:off\b|скидк|chegirma|discount|арзон|arzon)/u',
        '/(?:скидк\pL*|chegirma\pL*|discount\pL*)\s*(?:до\s+|up\s+to\s+|gacha\s+)?\d+(?:[.,]\d+)?\s?%/u',
        '/\bold\s+price\b|(?<!\pL)старая\s+цена|(?<!\pL)eski\s+narx/u',
    ];

    /**
     * Commercial promises the model may invent (warning "promise", L12).
     */
    private const PROMISES = [
        '/(?<!\pL)бесплатн\pL*|(?<!\pL)bepul(?!\pL)|(?<![\pL-])free(?:\s+of\s+charge)?\s+(?:delivery|shipping|installation|setup|set-up|service|support|maintenance)(?!\pL)/u',
        '/(?<!\pL)(?:гаранти\pL*|kafolat\pL*|warrant\pL*|guarantee\pL*)[^.!?\n]{0,30}\d/u',
        '/\d+[\s-]*(?:year|month|лет|год\pL*|месяц\pL*|yil\pL*|oy\pL*)[^.!?\n]{0,20}(?:гаранти|kafolat|warrant|guarantee)/u',
        '/(?<!\pL)(?:официальн\pL*\s+(?:дилер|дистрибьют|дистрибут|партн[её]р|представител|поставщик)|rasmiy\s+(?:diler|distribyutor|hamkor|vakil|yetkazib)|(?:official|authori[sz]ed)\s+(?:dealer|distributor|partner|reseller|representative|supplier))/u',
        '/(?<!\pL)(?:сертифи\pL*|sertifik\pL*|certifi\pL*)/u',
    ];

    /**
     * @return array{text: string, errors: list<string>, warnings: list<string>}
     */
    public function check(string $text, bool $html = false): array
    {
        $warnings = [];
        $errors = [];
        $hosts = HtmlSanitizer::siteHosts();

        if ($html) {
            $text = HtmlSanitizer::clean($text);
            $text = (string) preg_replace_callback('~<a\b[^>]*>(.*?)</a>~isu', function (array $match) use ($hosts, &$warnings): string {
                preg_match('~href="([^"]*)"~i', $match[0], $href);
                $url = html_entity_decode($href[1] ?? '', ENT_QUOTES | ENT_HTML5);
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

                if ($url !== '' && $scheme === 'https' && in_array($host, $hosts, true)) {
                    return $match[0];
                }

                $warnings['link_removed'] = true;

                return $match[1];
            }, $text);
        }

        $text = (string) preg_replace_callback('~(?<![\pL\d])(?:tel|tg|mailto|sms|viber|whatsapp|javascript|data|file):\S*~iu', function () use (&$warnings): string {
            $warnings['link_removed'] = true;

            return '';
        }, $text);

        $text = (string) preg_replace_callback('~\b[a-z][a-z0-9+.-]{1,15}://[^\s<>"\']+~iu', function (array $match) use ($hosts, &$warnings): string {
            $host = strtolower((string) parse_url($match[0], PHP_URL_HOST));

            if (in_array($host, $hosts, true) && ! str_contains((string) parse_url($match[0], PHP_URL_USER), '@')) {
                return $match[0];
            }

            $warnings['link_removed'] = true;

            return '';
        }, $text);

        $text = (string) preg_replace_callback('~(?<![\pL\d])(?:t\.me|telegram\.me)/\S*~iu', function () use (&$warnings): string {
            $warnings['link_removed'] = true;

            return '';
        }, $text);

        $text = (string) preg_replace_callback('/[\pL\d._%+-]+@[\pL\d-]+(?:\.[\pL\d-]+)+/u', function () use (&$warnings): string {
            $warnings['contact_removed'] = true;

            return '';
        }, $text);

        $text = (string) preg_replace_callback('/(?<![\pL\d._\/])@[\pL\d_]{2,}/u', function () use (&$warnings): string {
            $warnings['contact_removed'] = true;

            return '';
        }, $text);

        $text = $this->withoutForeignHosts($text, $hosts, $warnings);
        $text = $this->withoutLongNumbers($text, $warnings);

        if ($html) {
            $text = HtmlSanitizer::clean($text);
        } else {
            $text = trim((string) preg_replace('/[ \t]{2,}/u', ' ', $text));
        }

        $plain = mb_strtolower(AiOutputFilter::normalize(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5)));

        if ($this->hasAmount($plain)) {
            $errors[] = 'money';
        }

        if (BrandSafeText::violates($plain) || $this->discount($plain)) {
            $errors[] = 'discount';
        }

        if (str_contains($plain, '%') && ! in_array('discount', $errors, true)) {
            $warnings['percent'] = true;
        }

        foreach (self::PROMISES as $pattern) {
            if (preg_match($pattern, $plain) === 1) {
                $warnings['promise'] = true;

                break;
            }
        }

        return ['text' => $text, 'errors' => $errors, 'warnings' => array_keys($warnings)];
    }

    /**
     * @param  list<string>  $hosts
     * @param  array<string, bool>  $warnings
     */
    private function withoutForeignHosts(string $text, array $hosts, array &$warnings): string
    {
        $parts = preg_split('/(<[^>]*>)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];

        foreach ($parts as $index => $part) {
            if ($part === '' || str_starts_with($part, '<')) {
                continue;
            }

            $parts[$index] = (string) preg_replace_callback(AiOutputFilter::DOMAIN.'i', function (array $match) use ($hosts, &$warnings): string {
                if (in_array(mb_strtolower(rtrim($match[1], '.')), $hosts, true)) {
                    return $match[0];
                }

                $warnings['link_removed'] = true;

                return '';
            }, $part);
        }

        return implode('', $parts);
    }

    /**
     * @param  array<string, bool>  $warnings
     */
    private function withoutLongNumbers(string $text, array &$warnings): string
    {
        return (string) preg_replace_callback('/(?<![\pL\d])('.AiOutputFilter::EAN_LABEL.')?(\+?\d(?:[ \-().\/]{0,2}\d){8,})/iu', function (array $match) use (&$warnings): string {
            $digits = (string) preg_replace('/\D/', '', $match[2]);

            if (($match[1] ?? '') !== '' && in_array(strlen($digits), [8, 12, 13, 14], true)) {
                return $match[0];
            }

            $warnings['contact_removed'] = true;

            return $match[1] ?? '';
        }, $text);
    }

    private function hasAmount(string $text): bool
    {
        $number = '\d(?:[\d \x{00A0}\x{202F}.,]*\d)?';
        $patterns = [
            '/'.$number.'\s*(?:'.AiOutputFilter::SCALE.'\s*|(?:k|m)\s*)?'.AiOutputFilter::CURRENCY.'(?!\pL)/u',
            '/(?<![\pL])'.AiOutputFilter::CURRENCY.'\s*'.$number.'/u',
            '/(?<!\pL)'.AiOutputFilter::SCALE.'\s*'.AiOutputFilter::CURRENCY.'(?!\pL)/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function discount(string $text): bool
    {
        foreach ([...self::DISCOUNT, ...array_values(array_filter(AiOutputFilter::BRAND_PATTERNS, fn (string $pattern): bool => $pattern !== '/%/u'))] as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
