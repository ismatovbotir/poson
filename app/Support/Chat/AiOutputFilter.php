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
 *  - contact: an @mention, or an email that is not the company email; a
 *    bare handle (a Latin token of 5+ characters with "_" or ending in
 *    "bot", M2) or a handle-like token within 30 characters after a
 *    messenger word (Telegram, WhatsApp, Instagram, Viber, Signal, tg: with
 *    a digit or "_" within 3 characters, after ":" / "—" / a quote / " - ",
 *    followed by a detached suffix "ga" / "ni" / "da" or "deb qidiring",
 *    after a search verb, or the first Latin word in a Cyrillic line) that
 *    is not one of the company's own handles, a catalog brand or a
 *    candidate code / name word; a spelled-out domain ("posonuz dot shop",
 *    "точка ру"); a payment instruction (a money-moving verb: yubor…,
 *    oʻtkaz…, перевед…, отправ…, скин…, send, transfer, wire within 40
 *    characters of a target: karta…, карту, card, menejer, менеджер,
 *    manager, shaxsiy, личн…, personal, raqamiga, на номер, either order;
 *    "Toʻlov: naqd yoki karta" passes: L4);
 *  - card: a card-like run of 13-19 digits (an EAN/GTIN after "EAN",
 *    "barcode", "shtrix", "штрих" is not a card);
 *  - phone: any other run of 9+ digits that is not a company phone;
 *  - brand: BrandSafeText wording, the AI-only list (best price, affordable,
 *    выгодн…, competitor price comparisons, foiz / процент / percent,
 *    skidk…, rasprodaj…, yarim narx, half price, вдвое дешев… (M1),
 *    price drops "narxi tushdi", "цена снижена", "price reduced", yarmiga,
 *    promo kod / промокод) and
 *    ANY "%": phase A blocks
 *    every percentage (AI-12), also technical ones ("99 % uptime"), because
 *    a discount and a spec cannot be told apart reliably;
 *  - human: the model claims to be a person;
 *  - money (phase B, AI-B1 – AI-B3): every number is parsed to an integer
 *    amount in tiyin (thousands groups with a space, NBSP, NNBSP, "." or
 *    ","; up to 2 decimals; scale words ×1,000: ming, минг, тыс., тыщ,
 *    thousand, a standalone k / к; ×1,000,000: mln, млн, million, m before
 *    a currency or a bare m / м after a price word ("Narxi 2M");
 *    ×1,000,000,000: mlrd, млрд) and is money when it has a scale word or a
 *    currency, is in thousands groups not followed by a unit (mm, kg, dpi,
 *    labels, dona, шт …), is a run of 6+ digits, a run of 5+ digits not
 *    followed by a unit, a time word or a letter, or has 3+ digits within
 *    30 characters after a price word (narx…, нарх…, цен…, стои…, price…,
 *    cost(s), turadi, toʻlov…, and fee, delivery, repair, setup, total and
 *    subscription words: yetkaz…, доставк…, haqi, jami, итого, ремонт…,
 *    sozla…, oyiga, в месяц …; not "всего"; H1) or right after "—", "=" or
 *    ":" ("R110 — 9000"), and is not followed by a unit, a time word (yil,
 *    год, kun, daqiqa …, also "-yil") or a letter ("1450g"), is not a plain
 *    year (1980-2049) and does not follow "port" / "порт". Number
 *    words (yuz, ming, million, тысяч…, hundred …) not attached to digits
 *    within 30 characters after a price word are money too ("narxi bir
 *    million ikki yuz ming"). Money passes only when it EXACTLY equals the price of an
 *    allowed candidate (AiPriceCandidate), with a soʻm-family currency
 *    (soʻm, сум, сўм, UZS, sum) or none, with the localized per-label
 *    suffix ("/ yorliq | этикетка | label", within 15 characters) exactly
 *    when that candidate is priced per label, and in the same line or
 *    sentence as that candidate's SKU, model or name. Always money, never
 *    allowed: a foreign currency ($, USD, €, руб, юань, тенге, ₹ …, with or
 *    without a number), a soʻm-family word that follows no numeric amount
 *    (amounts in words: "besh yuz ming soʻm", "yarim million soʻm"), and
 *    an amount that cannot be parsed ("1.500 mln" reads as 1,500 million,
 *    so it never equals a real price). Without candidates every amount is
 *    blocked. A candidate's exact SKU / model token (fewer than 13 digits)
 *    is not read as a phone or an amount, unless it touches a digit group
 *    ("250" inside "1 250 000" stays part of the amount); card-length runs
 *    (13-19 digits) still block. A currency in front ("UZS 1 250 000")
 *    counts only when it is right before the number (one space at most, no
 *    line break) and is not the currency of the amount before it.
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
        // Percentages, discounts and sales in words (M1).
        '/(?<!\pL)foiz/u',
        '/(?<!\pL)процент/u',
        '/\bpercent/u',
        '/\bper\s?cent/u',
        '/(?<!\pL)skidk/u',
        '/(?<!\pL)rasprodaj/u',
        '/yarim\s+narx/u',
        '/\bhalf\s+price/u',
        '/(?<!\pL)вдвое\s+дешев/u',
        // Price drops and promo codes.
        '/(?<!\pL)narx\pL*\s+(?:tush|pasay)/u',
        '/(?<!\pL)(?:цен\pL*\s+(?:сниж|упал)|сниж\pL*\s+цен)/u',
        '/\bprice\s+(?:drop|reduc|cut)|\breduced\s+price/u',
        '/(?<!\pL)yarmiga/u',
        '/(?<!\pL)(?:promo\s?kod|промокод)/u',
    ];

    /**
     * Money-moving verbs of a payment instruction (L4).
     */
    private const PAYMENT_VERB = '(?:yubor\pL*|o[\'ʻʼ‘’`]?tkaz\pL*|перевед\pL*|переве[сд]\pL*|перечисл\pL*|отправ\pL*|скин\pL*|send\pL*|transfer\pL*|wire)';

    /**
     * Targets of a payment instruction (a card, a manager, a personal number; L4).
     */
    private const PAYMENT_TARGET = '(?:kart(?:a|asi|aga|asiga|amga|ochka)\pL*|карт[уеыа]\pL*|card\pL*|menejer\pL*|менеджер\pL*|manager\pL*|shaxsiy|личн\pL*|personal|raqam(?:iga|ga)|на\s+номер|to\s+(?:this|the|my)\s+number)';

    /**
     * Payment instructions (L4): a money-moving verb and a card / manager /
     * personal-number target within 40 characters of each other, either
     * order ("menejer raqamiga yuboring", "переведите на карту"); plain
     * payment methods ("Toʻlov: naqd yoki karta") are not instructions.
     */
    private const PAYMENT_PATTERN = '/(?<!\pL)'.self::PAYMENT_VERB.'[^\n]{0,40}?(?<!\pL)'.self::PAYMENT_TARGET.'|(?<!\pL)'.self::PAYMENT_TARGET.'[^\n]{0,40}?(?<!\pL)'.self::PAYMENT_VERB.'/u';

    /**
     * Messenger names: a handle-like token after one is a contact (M2).
     */
    private const MESSENGER = '(?<!\pL)(?:telegram\pL*|телеграм\pL*|whatsapp\pL*|ватсап\pL*|instagram\pL*|инстаграм\pL*|viber\pL*|вайбер\pL*|signal|tg)(?!\pL)';

    /**
     * Latin words after a messenger name that are not handles.
     */
    private const NOT_HANDLES = ['robot', 'robots', 'chatbot', 'premium', 'business', 'desktop', 'channel', 'messenger', 'online', 'telegram', 'whatsapp', 'instagram', 'viber', 'signal'];

    /**
     * A domain spelled out ("posonuz dot shop", "poson точка уз").
     */
    private const SPELLED_DOMAIN = '/(?<!\pL)[(\[]?\s*(?:dot|нукта|nuqta|точка)\s*[)\]]?\s*(?:uz|com|ru|shop|org|net|уз|ком|ру)(?!\pL)/u';

    /**
     * Price words, also fees, delivery, service, totals and subscriptions:
     * 3+ digits soon after one are an amount (H1).
     */
    private const PRICE_WORD = '(?<!\pL)(?:narx\pL*+|нарх\pL*+|цен\pL*+|стои\pL*+|стоимост\pL*+|price\pL*+|costs?(?!\pL)|turadi|туради|to[\'ʻʼ‘’`]?lov\pL*+|yetkaz\pL*+|dostavk\pL*+|доставк\pL*+|deliver\pL*+|shipping|fees?(?!\pL)|haq(?:i|qi|ini)(?!\pL)|оплат\pL*+|тариф\pL*+|tarif\pL*+|summa\pL*+|сумм[аеуы]\pL*+|jami|итого|total|ремонт\pL*+|ta[\'ʻʼ‘’`]?mir\pL*+|diagnost\pL*+|диагност\pL*+|o[\'ʻʼ‘’`]?rnat\pL*+|установк\pL*+|sozla\pL*+|настройк\pL*+|obuna\pL*+|подписк\pL*+|oyiga|в\s+месяц|per\s+month)';

    /**
     * Number words (an amount in words after a price word, H1).
     */
    private const NUMBER_WORDS = '(?<!\pL)(?:yuz|ming|minglab|million\pL*|mln|milliard\pL*|mlrd|минг\pL*|юз|миллион\pL*|млн|миллиард\pL*|млрд|тысяч\pL*|тыс|тыщ\pL*|сто|двести|триста|четыреста|пятьсот|шестьсот|семьсот|восемьсот|девятьсот|hundred\pL*|thousand\pL*|billion\pL*|grand)(?!\pL)';

    /**
     * Time words after a number in a price context: not money ("2026 yil").
     */
    private const TIME_UNITS = '(?:yil\pL*|год\pL*|лет|years?|oy\pL*|месяц\pL*|months?|kun\pL*|дн\pL*|days?|soat\pL*|час\pL*|hours?|daqiqa\pL*|минут\pL*|minutes?)';

    private const HUMAN_PATTERNS = [
        '/\b(?:i\s+am|i\'m|i’m|im)\s+(?:a\s+)?(?:real\s+|live\s+)?(?:human|person|man|woman|manager|staff\s+member|employee)\b/u',
        '/\bnot\s+an?\s+(?:bot|ai|robot)\b/u',
        '/(?<!\pL)я\s+(?:живой\s+|реальный\s+)?(?:человек|сотрудник|менеджер)/u',
        '/(?<!\pL)я\s+не\s+(?:бот|робот|ии)/u',
        '/(?<!\pL)men\s+(?:haqiqiy\s+|tirik\s+)?(?:odamman|insonman|menejerman|xodimman)/u',
        '/(?<!\pL)(?:bot|robot)\s+emasman/u',
    ];

    /**
     * The soʻm family: the only currency an allowed amount may carry.
     */
    private const SOM = '(?:so[\'ʻʼ‘’`]?m(?:ga|dan|lik|ni|da|li|lar)?|сўм\pL{0,3}|сум(?:ов|ы|а|ам|ах|ми)?|uzs|sum)';

    /**
     * Foreign currencies: always blocked.
     */
    private const FOREIGN = '(?:usd|\$|долл\pL*|dollar\pL*|у\.\s?е\.|y\.\s?e\.|€|eur(?:os?)?|руб(?:л\pL*)?\.?|rub(?:les?)?|₽|£|¥|gbp|rur|юан\pL*|yuan|cny|тенге|tenge|kzt|₸|kgs|₩|₹|лир\pL*|aed|bucks?)';

    /**
     * Scale words that make any number an amount, also without a currency
     * (group 1: thousand, 2: million, 3: billion).
     */
    private const SCALE = '(?:(ming|минг\pL*|тыс\.?\pL*|тыщ\pL*|thousand\pL*)|(mln\.?|million\pL*|млн\.?|миллион\pL*)|(mlrd\.?|milliard\pL*|млрд\.?|миллиард\pL*|billion\pL*))';

    /**
     * A number: thousands groups (space, NBSP, NNBSP, thin space, "." or ","),
     * or plain digits; then up to 2 decimals.
     */
    private const NUMBER = '/(?<![\pL\pN.,])(\d{1,3}(?:[ \x{00A0}\x{202F}\x{2009}.,]\d{3})+|\d+)(?:[.,](\d{1,2}))?(?!\d)/u';

    /**
     * The per-label suffix right after an amount (AI-B2).
     */
    private const PER_LABEL = '~^\s*(?:/|per|за|har(?:\s+bir)?|bir)?\s*(?:yorliq|этикетк|labels?(?!\pL))~u';

    /**
     * Units after a number in thousands groups: a quantity or a spec, not money.
     */
    private const UNITS = '(?:mm|cm|km|m|kg|g|dpi|pcs|шт\pL*\.?|dona\pL*|ta(?:dan|gacha)?|etiket\pL*|yorliq\pL*|labels?|cycles?|rolls?|мм|см|км|м|кг|г|метр\pL*|этикет\pL*|рулон\pL*|mah|мач)';

    private const EAN_LABEL = '(?:ean(?:-?(?:8|13))?|gtin|upc|barcode|bar\s+code|(?:shtrix|штрих)\pL*(?:[\s-]*(?:kod|код)\pL*)?)[\s:#№-]*';

    private const EAN_WORDS = '/'.self::EAN_LABEL.'$/u';

    /**
     * @param  list<string>  $hosts  Lowercased hosts links may point to
     * @param  list<string>  $phones  Company phone numbers (any format)
     * @param  list<string>  $emails  Company emails
     * @param  list<AiPriceCandidate>  $candidates  Prices (and codes) the answer may name
     * @param  list<string>  $handles  The company's own messenger handles (no "@")
     * @param  list<string>  $brands  Lowercased catalog brand names (not handles, M2)
     */
    public function __construct(
        private readonly array $hosts = [],
        private readonly array $phones = [],
        private readonly array $emails = [],
        private readonly array $candidates = [],
        private readonly array $handles = [],
        private readonly array $brands = [],
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
     * The violation of a whole text (cleaned, never cut at answer_max), for
     * texts a person wrote or a translation (taught rules, L3); null when
     * it may be used.
     */
    public function check(string $text): ?string
    {
        $text = $this->clean($text);

        return trim($text) === '' ? 'empty' : $this->violation($text);
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
        // Byte-length preserving: offsets in $scrubbed are offsets in $digits (same line / sentence check).
        $scrubbed = $this->withoutCodes($digits);

        return match (true) {
            $this->hasForeignLink($lower) => 'link',
            $this->hasForeignContact($lower) => 'contact',
            ($long = $this->longNumber($scrubbed)) !== null => $long,
            preg_match(self::PAYMENT_PATTERN, $lower) === 1 => 'contact',

            BrandSafeText::violates($lower) || $this->matchesAny($lower, self::BRAND_PATTERNS) => 'brand',
            $this->matchesAny($lower, self::HUMAN_PATTERNS) => 'human',
            $this->hasForeignAmount($this->withoutAllowedContacts($scrubbed), $digits) => 'money',
            default => null,
        };
    }

    /**
     * The amounts of a text that could be allowed (soʻm family or no
     * currency, parsed to tiyin) as "anywhere" candidates: a translation may
     * repeat the amounts of its source text and nothing else (AI-B14).
     *
     * @return list<AiPriceCandidate>
     */
    public static function amountsIn(string $text): array
    {
        $filter = new self;
        $digits = $filter->asciiDigits(mb_strtolower(self::normalize($text)));
        $found = [];

        foreach ($filter->amounts($digits) as $amount) {
            if ($amount['money'] && ! $amount['foreign'] && $amount['tiyin'] !== null) {
                $found[$amount['tiyin'].':'.(int) $amount['per_label']] = AiPriceCandidate::anywhere($amount['tiyin'], $amount['per_label']);
            }
        }

        return array_values($found);
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

        if (preg_match('/(?<![\pL\d._])@[\pL\d_]{2,}/u', $withoutEmails) === 1) {
            return true;
        }

        $plain = (string) preg_replace('~[a-z][a-z0-9+.-]{1,15}://\S*~u', ' ', $withoutEmails);

        return preg_match(self::SPELLED_DOMAIN, $plain) === 1
            || $this->hasBareHandle($plain)
            || $this->hasMessengerHandle($plain);
    }

    /**
     * A Latin token of 5+ characters with "_" or ending in "bot" (M2).
     */
    private function hasBareHandle(string $text): bool
    {
        preg_match_all('/(?<![\pL\pN_.\/-])[a-z][a-z0-9_]{4,}(?![\pL\pN_])/u', $text, $tokens);

        foreach ($tokens[0] as $token) {
            if ((str_contains($token, '_') || str_ends_with($token, 'bot')) && ! $this->isKnownToken($token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A handle-like Latin token within 30 characters after a messenger name (M2).
     */
    private function hasMessengerHandle(string $text): bool
    {
        preg_match_all('/'.self::MESSENGER.'/u', $text, $messengers, PREG_OFFSET_CAPTURE);

        foreach ($messengers[0] as [$word, $offset]) {
            $after = mb_substr(mb_scrub(substr($text, $offset + strlen($word), 200), 'UTF-8'), 0, 40);
            $line = $this->sentenceAt($text, $offset);
            $cyrillic = preg_match('/\p{Cyrillic}/u', $line) === 1;

            if (preg_match_all('/(?<![\pL\pN_.])([a-z][a-z0-9_.]{3,31})(?![\pL\pN_])/u', $after, $tokens, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($tokens[1] as $index => [$token, $at]) {
                $gap = substr($after, 0, $at);

                if (mb_strlen($gap) > 30 || str_contains($gap, "\n")) {
                    break;
                }

                $token = rtrim($token, '.');

                if (strlen($token) < 5 || $this->isKnownToken($token)) {
                    continue;
                }

                $tail = mb_scrub(substr($after, $at + strlen($tokens[0][$index][0]), 24), 'UTF-8');
                $separator = preg_match('/^(?:\s*[:—–→"«„\'“]\s*|\s+-\s*)$/u', $gap) === 1;

                if ((preg_match('/[\d_]/', $token) === 1 && (mb_strlen($gap) <= 3 || $separator))
                    || $separator
                    || preg_match('/^\s*-?\s*(?:ga|ni|da|dan|ning|га|ни|да)(?!\pL)/u', $tail) === 1
                    || preg_match('/^[^\pL\pN\n]{0,3}deb\s+(?:qidir|izla|top)\pL*/u', $tail) === 1
                    || preg_match('/(?<!\pL)(?:qidir\pL*|izla\pL*|search\pL*|find|найд\pL*|ищ\pL*)/u', $gap) === 1
                    || ($cyrillic && preg_match('/[a-z]/', $gap) === 0)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The company's own handles, catalog brand names, candidate codes and name words, and plain words that are not handles.
     */
    private function isKnownToken(string $token): bool
    {
        if (in_array($token, self::NOT_HANDLES, true)) {
            return true;
        }

        foreach ($this->handles as $handle) {
            if (mb_strtolower(ltrim(trim($handle), '@')) === $token) {
                return true;
            }
        }

        $known = $this->brands;

        foreach ($this->candidates as $candidate) {
            array_push($known, ...$candidate->codes, ...$candidate->names);
        }

        foreach ($known as $word) {
            if (preg_match('/(?<![\pL\pN_])'.preg_quote($token, '/').'(?![\pL\pN_])/u', mb_strtolower(self::normalize($word))) === 1) {
                return true;
            }
        }

        return false;
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
     * The text with the candidates' exact SKU / model tokens (fewer than 13
     * digits) replaced by "x" of the same byte length (AI-B3).
     */
    private function withoutCodes(string $text): string
    {
        foreach ($this->candidates as $candidate) {
            foreach ($candidate->codes as $code) {
                $code = $this->asciiDigits($code);

                if (strlen((string) preg_replace('/\D/', '', $code)) >= 13) {
                    continue;
                }

                $text = (string) preg_replace_callback(
                    $this->codePattern($code),
                    fn (array $match): string => str_repeat('x', strlen($match[0])),
                    $text,
                );
            }
        }

        return $text;
    }

    /**
     * A candidate code as a whole token; a digit-only code also must not
     * touch a digit group ("250" is not a code inside "1 250 000", while
     * "ZD421" stays a code before "1 250 000", CR-1).
     */
    private function codePattern(string $code): string
    {
        if (preg_match('/\pL/u', $code) === 1) {
            return '/(?<![\pL\pN])'.preg_quote($code, '/').'(?![\pL\pN])/u';
        }

        return '/(?<![\pL\pN])(?<!\d[ \x{00A0}\x{202F}\x{2009}.,])'.preg_quote($code, '/').'(?![\pL\pN])(?![ \x{00A0}\x{202F}\x{2009}.,]\d)/u';
    }

    /**
     * The text without own-host URLs and allowed long numbers (company
     * phones, labelled barcodes), so their digits are not read as amounts.
     * Replaced by "x" of the same byte length, so offsets stay valid.
     */
    private function withoutAllowedContacts(string $text): string
    {
        $blank = fn (array $match): string => str_repeat('x', strlen($match[0]));

        $text = (string) preg_replace_callback('~[a-z][a-z0-9+.-]{1,15}://\S+~u', $blank, $text);

        // Runs of 9+ digits got here only as company phones or labelled barcodes (longNumber blocks the rest).
        $text = (string) preg_replace_callback('/'.self::EAN_LABEL.'\d{8,14}(?!\d)/u', $blank, $text);

        return (string) preg_replace_callback('/\+?\d(?:[ \-().\/]{0,2}\d){8,}/u', $blank, $text);
    }

    /**
     * Any amount that is not an allowed candidate price (AI-B1 – AI-B3).
     *
     * @param  string  $text  Lowercase, ASCII digits, allowed contacts and codes blanked
     * @param  string  $original  The same text before blanking (for the line / sentence check)
     */
    private function hasForeignAmount(string $text, string $original): bool
    {
        // Foreign currencies are never allowed, with or without a number.
        if (preg_match('/(?<!\pL)'.self::FOREIGN.'(?!\pL)/u', $text) === 1) {
            return true;
        }

        // An amount in words soon after a price word ("narxi bir million ikki yuz ming"), not a scale of digits.
        if (preg_match('/'.self::PRICE_WORD.'[^\n]{0,30}?(?<!\d)(?<!\d[\s\x{00A0}\x{202F}])'.self::NUMBER_WORDS.'/u', $text) === 1) {
            return true;
        }

        $consumed = [];

        foreach ($this->amounts($text) as $amount) {
            if (! $amount['money']) {
                continue;
            }

            if ($amount['currency_at'] !== null) {
                $consumed[$amount['currency_at']] = true;
            }

            if ($amount['foreign'] || $amount['tiyin'] === null || ! $this->isAllowed($amount, $original)) {
                return true;
            }
        }

        // A soʻm-family word that follows (or precedes) no numeric amount: an amount in words.
        preg_match_all('/(?<!\pL)'.self::SOM.'(?!\pL)/u', $text, $currencies, PREG_OFFSET_CAPTURE);

        foreach ($currencies[0] as [, $offset]) {
            if (! isset($consumed[$offset])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every number of the text with its amount reading.
     *
     * @return list<array{offset: int, money: bool, foreign: bool, tiyin: ?int, per_label: bool, currency_at: ?int}>
     */
    private function amounts(string $text): array
    {
        preg_match_all(self::NUMBER, $text, $numbers, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $amounts = [];
        $claimed = [];

        foreach ($numbers as $number) {
            [$whole, $offset] = $number[0];
            $integer = (string) preg_replace('/\D/', '', $number[1][0]);
            $decimals = isset($number[2]) && $number[2][1] >= 0 ? $number[2][0] : '';
            $grouped = preg_match('/\D/', $number[1][0]) === 1;
            $end = $offset + strlen($whole);
            $rest = substr($text, $end, 80);
            $glued = preg_match('/^\pL/u', $rest) === 1;
            $scale = 1;
            $currencyAt = null;
            $som = false;
            $foreign = false;
            $context = preg_match('/'.self::PRICE_WORD.'[^\n]{0,30}$/u', mb_scrub(substr($text, max(0, $offset - 240), min(240, $offset)), 'UTF-8')) === 1;

            if (preg_match('/^\s*'.self::SCALE.'(?!\pL)/u', $rest, $match) === 1) {
                $scale = match (true) {
                    ($match[3] ?? '') !== '' => 1_000_000_000,
                    ($match[2] ?? '') !== '' => 1_000_000,
                    default => 1_000,
                };
                $rest = substr($rest, strlen($match[0]));
                $end += strlen($match[0]);
            } elseif (preg_match('/^\s*(?:k|к)(?!\pL)/u', $rest, $match) === 1) {
                $scale = 1_000;
                $rest = substr($rest, strlen($match[0]));
                $end += strlen($match[0]);
            } elseif (preg_match('/^\s*m(?=\s*(?:'.self::SOM.'|'.self::FOREIGN.')(?!\pL))/u', $rest, $match) === 1) {
                $scale = 1_000_000;
                $rest = substr($rest, strlen($match[0]));
                $end += strlen($match[0]);
            } elseif ($context && preg_match('/^\s*[mм](?!\pL)/u', $rest, $match) === 1) {
                // "Price: 1.25M", "Narxi 2M": a bare M after a price word is a million.
                $scale = 1_000_000;
                $rest = substr($rest, strlen($match[0]));
                $end += strlen($match[0]);
            }

            if (preg_match('/^(\s*)'.self::SOM.'(?!\pL)/u', $rest, $match) === 1) {
                $som = true;
                $currencyAt = $end + strlen($match[1]);
                $rest = substr($rest, strlen($match[0]));
            } elseif (preg_match('/^\s*'.self::FOREIGN.'(?!\pL)/u', $rest) === 1) {
                $foreign = true;
            }

            $before = mb_scrub(substr($text, max(0, $offset - 16), min(16, $offset)), 'UTF-8');

            if (preg_match('/(?<!\pL)'.self::FOREIGN.'\s*$/u', $before) === 1) {
                $foreign = true;
            } elseif (! $som && preg_match('/(?<!\pL)('.self::SOM.')([ \x{00A0}]?)\z/u', $before, $match) === 1 && ! isset($claimed[$offset - strlen($match[0])])) {
                // "UZS 1 250 000": the currency right in front, not the one of the amount before.
                $som = true;
                $currencyAt = $offset - strlen($match[0]);
            }

            if ($currencyAt !== null) {
                $claimed[$currencyAt] = true;
            }

            $unit = preg_match('/^\s*'.self::UNITS.'(?!\pL)/u', $rest) === 1;
            $time = preg_match('/^\s*-?\s*'.self::TIME_UNITS.'(?!\pL)/u', $rest) === 1;
            // A plain year ("2026-yil", "2026 model") and a port number ("Port: 9100") are not prices.
            $year = ! $grouped && $decimals === '' && preg_match('/^(?:19[89]\d|20[0-4]\d)$/', $integer) === 1
                && preg_match('/^\s*\pL/u', $rest) === 1;
            $port = ! $grouped && strlen($integer) <= 4
                && preg_match('/(?<!\pL)(?:port|порт)\pL*\s*[:—–-]?\s*$/u', $before) === 1;
            // "R110 — 9000.": 3+ digits right after a dash, "=" or ":".
            $dash = preg_match('/[—–=:]\s*$/u', mb_scrub(substr($text, max(0, $offset - 4), min(4, $offset)), 'UTF-8')) === 1;
            $plain = ! $unit && ! $time && ! $glued && ! $port;
            $priced = strlen(ltrim($integer, '0')) >= 3 && $plain && ! $year && ($context || $dash);
            $money = $scale > 1 || $som || $foreign || $priced
                || ($grouped && ! $unit && strlen($integer) >= 4)
                || ($plain && strlen(ltrim($integer, '0')) >= 5)
                || strlen(ltrim($integer, '0')) >= 6;

            $amounts[] = [
                'offset' => $offset,
                'money' => $money,
                'foreign' => $foreign,
                'tiyin' => $money ? $this->tiyin($integer, $decimals, $scale) : null,
                'per_label' => preg_match(self::PER_LABEL, mb_substr($rest, 0, 15)) === 1,
                'currency_at' => $currencyAt,
            ];
        }

        return $amounts;
    }

    /**
     * Integer tiyin of the integer digits, up to 2 decimals and the scale;
     * null when it would overflow (unparseable, so blocked).
     */
    private function tiyin(string $integer, string $decimals, int $scale): ?int
    {
        $digits = ltrim($integer.str_pad($decimals, 2, '0'), '0');

        if ($digits === '') {
            return 0;
        }

        if (strlen($digits) + strlen((string) $scale) - 1 > 17) {
            return null;
        }

        return (int) $digits * $scale;
    }

    /**
     * An amount equal to a candidate's price, with the per-label suffix
     * exactly when that candidate is priced per label, in the same line or
     * sentence as that candidate's code or name (or an "anywhere" amount).
     *
     * @param  array{offset: int, money: bool, foreign: bool, tiyin: ?int, per_label: bool, currency_at: ?int}  $amount
     */
    private function isAllowed(array $amount, string $original): bool
    {
        $sentence = null;

        foreach ($this->candidates as $candidate) {
            if ($candidate->tiyin === null || $candidate->tiyin !== $amount['tiyin'] || $candidate->perLabel !== $amount['per_label']) {
                continue;
            }

            if ($candidate->anywhere) {
                return true;
            }

            $sentence ??= (string) preg_replace('/\s+/u', ' ', $this->sentenceAt($original, $amount['offset']));

            foreach ($candidate->codes as $code) {
                if (preg_match($this->codePattern($this->asciiDigits($code)), $sentence) === 1) {
                    return true;
                }
            }

            foreach ($candidate->names as $name) {
                if (str_contains($sentence, $name)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The line or sentence around a byte offset: split at line breaks and at
     * ". ", "! ", "? ", "… " (not after a scale abbreviation such as "mln.").
     */
    private function sentenceAt(string $text, int $offset): string
    {
        $start = 0;
        $end = strlen($text);

        preg_match_all('/\n+|[.!?…]+[ \t]+/u', $text, $breaks, PREG_OFFSET_CAPTURE);

        foreach ($breaks[0] as [$break, $at]) {
            if (str_starts_with($break, '.') && preg_match('/(?<!\pL)(?:mln|mlrd|тыс|млн|млрд|ming)$/u', mb_scrub(substr($text, max(0, $at - 12), min(12, $at)), 'UTF-8')) === 1) {
                continue;
            }

            if ($at + strlen($break) <= $offset) {
                $start = $at + strlen($break);
            } elseif ($at >= $offset) {
                $end = $at;

                break;
            }
        }

        return substr($text, $start, $end - $start);
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
