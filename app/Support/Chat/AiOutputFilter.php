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
 *    "точка ру"); a payment instruction (L4, plan 33 A2-S1/S2: a send verb
 *    — yubor…, отправ…, send — within 40 characters of a card or number
 *    target or within 20 of a money object — pul…, mablagʻ…, деньги,
 *    средства, money, funds, soʻm…; a transfer verb — oʻtkaz…, перевед…,
 *    скин…, transfer, wire, юбор…, ўтказ… — also within 40 of a person in
 *    the dative — menejerga, менеджеру, to the manager, menga …; a pay
 *    verb within 30 of a directional target — kartaga, на карту, to the …
 *    card, hisobiga, на счёт; either order; "Toʻlov: naqd yoki karta",
 *    "Мы отправим счёт на оплату" and "Send us an enquiry and a manager
 *    will prepare the quote" pass); a payment service other than UzQR
 *    (Payme, Paycom, Click in a payment context; A2-S9); a claim that a
 *    request was sent or accepted, or a request for the customer's phone,
 *    name or contact details (A2-S3). Payment and brand checks also run
 *    on an obfuscation-folded copy (fold(), A2-S14);
 *  - card: a card-like run of 13-19 digits, separators and emoji between
 *    the digits included (an EAN/GTIN after "EAN", "barcode", "shtrix",
 *    "штрих" is not a card);
 *  - phone: any other run of 9+ digits that is not a company phone;
 *  - brand: BrandSafeText wording, the AI-only list (best price, affordable,
 *    выгодн…, competitor price comparisons, foiz / процент / percent,
 *    skidk…, rasprodaj…, yarim narx, half price, вдвое дешев… (M1),
 *    price drops "narxi tushdi", "цена снижена", "price reduced", yarmiga,
 *    promo kod / промокод, cheap / budget / special-price wording and
 *    dealer or wholesale price levels, A2-4, A2-S15) and
 *    ANY "%": every percentage is blocked (AI-12), also technical ones
 *    ("99 % uptime"), because a discount and a spec cannot be told apart
 *    reliably; the one exception is the configured VAT rate right next to
 *    a VAT word ("12% QQS", vatRateSpans(), A2-S4/S5);
 *  - human: the model claims to be a person;
 *  - money (phase B, AI-B1 – AI-B3): every number is parsed to an integer
 *    amount in tiyin (thousands groups with a space, NBSP, NNBSP, "." or
 *    ","; up to 2 decimals; scale words ×1,000: ming, минг, тыс., тыщ,
 *    thousand, a standalone k / к; ×1,000,000: mln, млн, million, m before
 *    a currency or a bare m / м after a price word ("Narxi 2M");
 *    ×1,000,000,000: mlrd, млрд) and is money when it has a scale word or a
 *    currency, is in thousands groups not followed by a unit (mm, kg, dpi,
 *    labels, dona, шт, MHz, °C, dBm, ms …), is a run of 6+ digits, a run
 *    of 5+ digits not followed by a unit, a time word or a letter and not
 *    a standard number right after ISO / IEC / IEEE / ANSI / ASTM / ГОСТ /
 *    Oʻz DSt (outside a price context, A2-S13), or has 3+ digits within
 *    30 characters after a price word (narx…, нарх…, цен…, стои…, price…,
 *    cost(s), turadi, toʻlov…, and fee, delivery, repair, setup, total and
 *    subscription words: yetkaz…, доставк…, haqi, jami, итого, ремонт…,
 *    sozla…, oyiga, в месяц …; not "всего"; H1) or right after "—", "=" or
 *    ":" ("R110 — 9000"; not the second number of a range such as
 *    "860–960"), and is not followed by a unit, a time word (yil,
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
 *    without a number; "rub" only next to a number), a soʻm-family word that follows no numeric amount
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

    public const BRAND_PATTERNS = [
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
        // Cheap and budget wording, special prices (plan 33 A2-4); "бюджетные организации", "energiya tejamkor" pass.
        '/(?<!\pL)недорог/u',
        '/(?<!\pL)бюджетн\pL*+(?!\s+(?:организац|учрежден|сектор|сфер))/u',
        '/(?<!\pL)(?:ниже|дешевле)\s+рынк/u',
        '/(?<!\pL)(?:лучш\pL*\s+)?предложени\pL*\s+(?:месяц|недел|дня)/u',
        '/(?<!\pL)специальн\pL*\s+цен/u',
        '/(?<!\pL)hamyonbop/u',
        '/(?<!energiya\s)(?<!elektr\s)(?<!\pL)tejamkor/u',
        '/(?<!\pL)maxsus\s+(?:narx|taklif)/u',
        '/\bspecial\s+(?:prices?|deals?)\b/u',
        '/\bsav(?:e|es|ing)\s+(?:money|costs?)\b/u',
        '/\bcost[\s-]+effective/u',
        '/\bbudget[\s-]+(?:friendly|printers?|scanners?|models?|options?|solutions?|devices?|equipment|choice)\b/u',
        // Price levels are never discussed (CLAUDE.md, AI-10, A2-S15).
        '/(?<!\pL)(?:dealer|diler|дилер|wholesale|ulgurji|оптов)\pL*\s+(?:uchun\s+|для\s+|по\s+)?(?:prices?|pricing|price\s+list|narx|цен|прайс)/u',
        '/(?<!\pL)(?:цен\pL*|prices?|pricing|price\s+lists?|narx\pL*|прайс\pL*(?:[\s-]+лист\pL*)?)\s+(?:for|для)\s+(?:dealers?|дилер|wholesale|оптов)/u',
        '/(?<!\pL)(?:optom|оптом|opt)\s+(?:\pL+\s+){0,2}?(?:narx|цен|прайс|price)/u',
        '/(?<!\pL)дилерск\pL*/u',
    ];

    /**
     * "Send" verbs of a payment instruction: they need a card / number
     * target or a money object (plan 33 A2-S1).
     */
    private const PAYMENT_SEND = '(?:yubor\pL*+|отправ\pL*+|send\pL*+)';

    /**
     * Money-moving verbs: a card / number target, a money object or a person
     * in the dative is enough (A2-S1); Uzbek Cyrillic and Latin
     * transliterations included.
     */
    private const PAYMENT_TRANSFER = '(?:o[\'ʻʼ‘’`]?tkaz\pL*+|перевед\pL*+|переве[сд]\pL*+|перевод\pL*+|перечисл\pL*+|скин\pL*+|transfer\pL*+|wire[sd]?(?!\pL)|юбор\pL*+|[ўу]тказ\pL*+|perev[eo]d\pL*+|perechisl\pL*+)';

    /**
     * Card and number targets of a payment instruction (a warranty card is not one; L4, A2-S1).
     */
    private const PAYMENT_TARGET = '(?:(?<!kafolat\s)(?<!garantiya\s)(?<!garantiy\s)kart(?:a|asi|aga|asiga|amga|ochka)\pL*|(?<!гарантийн\pL\pL\s)(?<!гарантии\s)карт[уеыа]\pL*|(?<!warranty\s)(?<!guarantee\s)card\pL*|shaxsiy|личн\pL*|personal|raqam(?:iga|ga)|рақам(?:ига|га)|на\s+номер|to\s+(?:this|the|my)\s+number)';

    /**
     * Money objects: within 20 characters of a send or transfer verb they
     * make a payment instruction ("Pulni menejerga yuboring", "wire the
     * funds"); an invoice "на оплату" / "for payment" is not one (A2-S1).
     */
    private const PAYMENT_MONEY = '(?:pul(?![ts])\pL*+|пул(?!ьт|ьс|я)\pL*+|to[\'ʻʼ‘’`]?lov(?:ni|ingizni)|тўлов(?:ни|ингизни)|mablag[\'ʻʼ‘’`]?\pL*+|маблағ\pL*+|summa(?!r)\pL*+|сум(?!к)\pL*+|so[\'ʻʼ‘’`]m\pL*+|сўм\pL*+|uzs|cash|деньг\pL*+|(?<!на\s)оплат[уы]|средств[аы]|money|funds|(?<!for\s)(?<!of\s)payments?)';

    /**
     * A person in the dative after a transfer verb ("Menejerga oʻtkazing",
     * "Переведите менеджеру", "Transfer it to the manager"; A2-S1).
     */
    private const PAYMENT_PERSON = '(?:menejer(?:ga|imizga|ingizga|larga)|menedzher(?:u|am)|менеджер(?:у|ам)|менежер(?:га|имизга|ингизга)|to\s+(?:the\s+|our\s+|a\s+|your\s+)?(?:manager|staff|employee|courier)s?|xodim\pL*ga|ходим\pL*га|сотрудник(?:у|ам)|kuryerga|курьеру|курьерга|menga|менга|мне|to\s+me)';

    /**
     * Pay verbs and the directional targets they must not point at (A2-S2).
     */
    private const PAY_VERB = '(?:pay(?:s|ing)?(?!\pL)|to[\'ʻʼ‘’`]?la\pL*+|оплат(?:и|ите)(?!\pL)|заплат\pL*+|тўла\pL*+)';

    private const PAY_TARGET = '(?:kartaga|kartasiga|картага|на\s+карт\pL*|to\s+(?:[\pL\'’]+\s+){0,3}card\pL*|raqamiga|рақамига|на\s+номер|hisobiga|ҳисобига|на\s+сч[её]т)';

    /**
     * Hand-over verbs ("bering", "tashlang", "qiling", "передайте", "hand",
     * "give"): an instruction only with a directional card target, or with a
     * money object and a person in the dative on the same line (plan 33 §19 M-2).
     */
    private const PAYMENT_GIVE = '(?:ber(?:ing|ib|asiz|ingiz|sangiz)\pL*+|tashla\pL*+|qil(?:ing|ib|asiz|ingiz|sangiz)\pL*+|переда(?:й|йте|ть|дите)(?!\pL)|отда(?:й|йте|ть)(?!\pL)|hand(?:s|ed|ing)?(?!\pL)|giv(?:e|es|ing)(?!\pL))';

    /**
     * The company's own payment-method nouns ("bank oʻtkazmasi", "bank
     * transfer", "банковский перевод", "перечисление по счёту"): blanked
     * before the payment-instruction check, so the seed FAQ, rule answers,
     * delivery page and prompt rule 11 are delivered (plan 33 §19 H-1).
     *
     * @var list<string>
     */
    private const PAYMENT_METHODS = [
        '/(?<!\pL)bank\s+(?:orqali\s+)?o[\'ʻʼ‘’`]?tkazma\pL*/u',
        '/(?<!\pL)bank\s+transfers?(?!\pL)/u',
        '/(?<!\pL)by\s+transfer(?!\pL)/u',
        '/(?<!\pL)(?:банковск|безналичн)\pL*\s+(?:перевод|перечислени)\pL*/u',
        '/(?<!\pL)перечислени\pL*\s+по\s+сч[её]т\pL*/u',
        '/(?<!\pL)pul\s+o[\'ʻʼ‘’`]?tkazish\s+yo[\'ʻʼ‘’`]?li(?!\pL)/u',
    ];

    /**
     * A payment-method noun pointed at a card or a person ("bank transfer
     * to the manager's card", "menejerga bank oʻtkazmasi"): checked on the
     * text before PAYMENT_METHODS are blanked, so blanking hides nothing (H-1).
     */
    /**
     * Card, number and personal-account targets of a payment-method noun
     * (§20 M-5): the manager's / staff / courier card in uz, ru and en,
     * Uzcard, Humo, shaxsiy / личн… / personal. No bare "kartasi"
     * ("terminal orqali bank kartasi" is a payment method).
     */
    private const METHOD_CARD_TARGET = '(?:kart(?:aga|asiga)|(?:menejer|menedzher|xodim|kuryer)\pL*\s+kartas\pL*|картага|на\s+карт\pL*|карт[уеы]\s+(?:менеджер|сотрудник|курьер)\pL*|to\s+(?:[\pL\'’]+\s+){0,3}(?:uz)?card\pL*|(?:manager|staff|employee|courier)(?:[\'’]s)?\s+card\pL*|humo|shaxsiy|личн\pL*|personal|raqamiga|рақамига|на\s+номер)';

    /**
     * Cash on delivery to the courier (§20 M-7): blanked like a payment
     * method, but only on a line with no card or number word, so "Pay the
     * courier in cash to his card" still blocks. Never for a manager, staff
     * or "me".
     *
     * @var list<string>
     */
    private const PAYMENT_COURIER_CASH = [
        '/(?<!\pL)kuryerga\s+(?:naqd(?:\s+pul)?(?:\s+(?:bilan|orqali))?|naqd\s+pulda)\s+to[\'ʻʼ‘’`]?la(?:ysiz|nadi|sh\pL*|ng)(?!\pL)/u',
        '/(?<!\pL)naqd(?:\s+pul)?(?:\s+bilan)?\s+kuryerga\s+to[\'ʻʼ‘’`]?la(?:ysiz|nadi|sh\pL*|ng)(?!\pL)/u',
        '/(?<!\pL)pay(?:ing)?\s+(?:the\s+)?courier\s+(?:in|with)\s+cash(?!\pL)/u',
        '/(?<!\pL)pay(?:ing)?\s+(?:in\s+)?cash\s+to\s+the\s+courier(?!\pL)/u',
    ];

    /**
     * A card or number word on a line: cash to the courier is not blanked there (§20 M-7).
     */
    private const CARD_WORD = '/(?<!\pL)(?:'.self::PAYMENT_TARGET.'|'.self::PAY_TARGET.'|kartas\pL*|\pL*card\pL*|карт\pL*)/u';

    private const PAYMENT_METHOD_DIRECTED = '/(?:'
        .'(?<!\pL)bank\s+(?:orqali\s+)?o[\'ʻʼ‘’`]?tkazma\pL*|(?<!\pL)bank\s+transfers?|(?<!\pL)by\s+transfer|(?<!\pL)(?:банковск|безналичн)\pL*\s+(?:перевод|перечислени)\pL*|(?<!\pL)перечислени\pL*\s+по\s+сч[её]т\pL*|(?<!\pL)pul\s+o[\'ʻʼ‘’`]?tkazish\s+yo[\'ʻʼ‘’`]?li'
        .')(?!\pL)(?:[^\n]{0,40}?(?:\n[^\n]{0,40}?)?(?<!\pL)'.self::METHOD_CARD_TARGET.'|[^\n]{0,40}?(?<!\pL)'.self::PAYMENT_PERSON.')(?!\pL)'
        .'|(?<!\pL)(?:kartaga|kartasiga|картага|на\s+карт\pL*|raqamiga|рақамига|на\s+номер|'.self::PAYMENT_PERSON.')(?!\pL)[^\n]{0,40}?(?<!\pL)(?:bank\s+(?:orqali\s+)?o[\'ʻʼ‘’`]?tkazma|bank\s+transfer|(?:банковск|безналичн)\pL*\s+(?:перевод|перечислени))/u';

    /**
     * Payment instructions (L4, A2-S1, A2-S2), each either order on one line:
     * a send / transfer verb within 40 characters of a card or number
     * target; a send / transfer verb within 20 characters of a money object;
     * a transfer verb within 40 characters of a person in the dative; a pay
     * verb within 30 characters of a directional card / number / account
     * target. Plain payment methods ("Toʻlov: naqd yoki karta", "Pay by card
     * or cash on delivery") and manager calls to action ("Send us an enquiry
     * and a manager will prepare the quote") are not instructions.
     *
     * @var list<string>
     */
    private const PAYMENT_PATTERNS = [
        '/(?<!\pL)(?:'.self::PAYMENT_SEND.'|'.self::PAYMENT_TRANSFER.')[^\n]{0,40}?(?<!\pL)'.self::PAYMENT_TARGET.'|(?<!\pL)'.self::PAYMENT_TARGET.'[^\n]{0,40}?(?<!\pL)(?:'.self::PAYMENT_SEND.'|'.self::PAYMENT_TRANSFER.')/u',
        '/(?<!\pL)(?:'.self::PAYMENT_SEND.'|'.self::PAYMENT_TRANSFER.')[^\n]{0,20}?(?<!\pL)'.self::PAYMENT_MONEY.'(?!\pL)|(?<!\pL)'.self::PAYMENT_MONEY.'(?!\pL)[^\n]{0,20}?(?<!\pL)(?:'.self::PAYMENT_SEND.'|'.self::PAYMENT_TRANSFER.')/u',
        '/(?<!\pL)'.self::PAYMENT_TRANSFER.'[^\n]{0,40}?(?<!\pL)'.self::PAYMENT_PERSON.'(?!\pL)|(?<!\pL)'.self::PAYMENT_PERSON.'(?!\pL)[^\n]{0,40}?(?<!\pL)'.self::PAYMENT_TRANSFER.'/u',
        '/(?<!\pL)'.self::PAY_VERB.'[^\n]{0,30}?(?<!\pL)'.self::PAY_TARGET.'|(?<!\pL)'.self::PAY_TARGET.'[^\n]{0,30}?(?<!\pL)'.self::PAY_VERB.'/u',
        // §19 M-2: a pay verb aimed at a person ("Menejerga toʻlang", "Заплатите менеджеру", "Pay the manager directly").
        '/(?<!\pL)'.self::PAY_VERB.'[^\n]{0,30}?(?<!\pL)'.self::PAYMENT_PERSON.'(?!\pL)|(?<!\pL)'.self::PAYMENT_PERSON.'(?!\pL)[^\n]{0,30}?(?<!\pL)'.self::PAY_VERB.'/u',
        '/(?<!\pL)pay(?:ing)?\s+(?:(?:the|our|a|your|my)\s+)?(?:manager|staff|employee|courier|driver|agent)s?(?!\pL)/u',
        // §19 M-2: a hand-over verb with a card / account target ("…kartasiga tashlang", "…shaxsiy kartasiga qiling").
        '/(?<!\pL)'.self::PAYMENT_GIVE.'[^\n]{0,40}?(?<!\pL)'.self::PAY_TARGET.'|(?<!\pL)'.self::PAY_TARGET.'[^\n]{0,40}?(?<!\pL)'.self::PAYMENT_GIVE.'/u',
        // §19 M-2: a hand-over verb, a person in the dative and a money object on one line ("Pulni menejerimizga bering", "Hand the cash to our manager").
        '/(?m)^(?=[^\n]*?(?<!\pL)(?:'.self::PAYMENT_MONEY.'|наличн\pL*+|naqd)(?!\pL))[^\n]*?(?:(?<!\pL)'.self::PAYMENT_GIVE.'[^\n]{0,40}?(?<!\pL)'.self::PAYMENT_PERSON.'(?!\pL)|(?<!\pL)'.self::PAYMENT_PERSON.'(?!\pL)[^\n]{0,40}?(?<!\pL)'.self::PAYMENT_GIVE.')/u',
    ];

    /**
     * Online payment is UzQR only (owner fact, A2-S9): other payment
     * services are never named; "click" counts only in a payment context
     * ("Click the button below" passes).
     *
     * @var list<string>
     */
    private const PAYMENT_SERVICES = [
        '/(?<!\pL)(?:payme(?!nt|r)|пейми|пэйми|paycom|paynet|пейнет)/u',
        '/(?<!\pL)(?:click|klik|клик)\s+(?:orqali|bilan|ilova\pL*|орқали|билан|илова\pL*)|(?<!\pL)через\s+(?:click|klik|клик)(?!\pL)/u',
        // §19 M-3: Uzbek case suffixes, also after an apostrophe ("Clickda", "Click'da", "Klikdan", "Clickni").
        '/(?<!\pL)(?:click|klik|клик)[\'ʻʼ‘’`-]?(?:da|dan|ga|ni|ning|orqali|ilova\pL*|да|дан|га|ни|нинг)(?!\pL)/u',
        '/(?<!\pL)(?:pay\pL*|to[\'ʻʼ‘’`]?la\pL*|оплат\pL*|тўла\pL*)[^\n]{0,20}?(?<!\pL)(?:click|klik|клик)(?!\pL)|(?<!\pL)(?:click|klik|клик)(?!\pL)[^\n]{0,20}?(?<!\pL)(?:to[\'ʻʼ‘’`]?lov|оплат|payment|тўлов)/u',
    ];

    /**
     * Claims that a request was sent or accepted (the model cannot create
     * one) and requests for the customer's contact details (A2-S3). Only
     * personal forms count, so "send the model name" or "seriya raqamini
     * yuboring" stay possible.
     *
     * @var list<string>
     */
    private const SUBMISSION_PATTERNS = [
        '/(?<!\pL)(?:so[\'ʻʼ‘’`]?rov|ariza|buyurtma|сўров|ариза|буюртма)\pL*[^\n]{0,40}?(?<!\pL)(?:yuborildi|yetkazildi|topshirildi|uzatildi|qabul\s+qilindi|qayd\s+etildi|ro[\'ʻʼ‘’`]?yxatga\s+olindi|rasmiylashtirildi|юборилди|етказилди|қабул\s+қилинди)(?!\pL)/u',
        '/(?<!\pL)(?:заявк|запрос)\pL*[^\n]{0,40}?(?<!\pL)(?:принят|отправлен|оформлен|зарегистрир|передан|направлен)\pL*|(?<!\pL)(?:принял\pL*|отправил\pL*|оформил\pL*|зарегистрировал\pL*|передал\pL*)[^\n]{0,20}?(?<!\pL)(?:заявк|запрос)\pL*/u',
        '/(?<!\pL)zayavk\pL*[^\n]{0,40}?(?<!\pL)(?:prinyat|otpravlen|oformlen|peredan)\pL*/u',
        // "is placed" is left out: "Order is placed on the website via the cart" describes the flow (§19 M-1).
        '/(?<!\pL)(?:request|enquiry|inquiry|order|quote)s?\s+(?:(?:has|have)\s+been|was|were)\s+(?:sent|submitted|received|registered|forwarded|accepted|placed|created|passed)(?!\pL)|(?<!\pL)(?:request|enquiry|inquiry|order|quote)s?\s+is\s+(?:sent|submitted|received|registered|forwarded|accepted|created)(?!\pL)|(?<!\pL)(?:request|enquiry|inquiry|order|quote)s?\s+is\s+now\s+with(?!\pL)/u',
        '/(?<!\pL)i(?:\'ve|’ve|\s+have)?\s+(?:passed|forwarded|sent|submitted|notified|informed|alerted)\s+(?:it|your|this|the|a|our)(?!\pL)/u',
        '/(?<!\pL)(?:telefon\pL*|ism(?:ingiz\pL*|ni)?|ism-sharif\pL*|raqamingiz\pL*|kontakt\pL*|телефон\pL*|рақамингиз\pL*)(?!\pL)[^\n]{0,20}?(?<!\pL)(?:yozing|qoldiring|yuboring|kiriting|ёзинг|қолдиринг|юборинг)(?!\pL)/u',
        // §19 M-1: a bare number after a leave / write verb ("Raqamni qoldiring", "Оставьте номер"); "Model raqamini yozing", "номер модели" pass.
        '/(?<!model\s)(?<!seriya\s)(?<!\pL)(?:raqam|рақам)(?:ni|ни)?(?!\pL)[^\n]{0,20}?(?<!\pL)(?:yozing|qoldiring|yuboring|ёзинг|қолдиринг|юборинг)(?!\pL)/u',
        '/(?<!\pL)(?:оставьте|напишите|пришлите|отправьте|укажите|сообщите)\s+(?:(?:свой|ваш)\s+)?номер(?!\pL)(?!\s+(?:модел|сер|заказ|сч|издел|артикул|парти|договор|накладн|счет|счёт))/u',
        '/(?<!\pL)(?:what\s+is|what\'s|what’s|may\s+i\s+(?:have|get)|can\s+i\s+(?:have|get)|could\s+i\s+(?:have|get))\s+your\s+(?:phone|number|name|contact|mobile)/u',
        '/(?<!\pL)ваш\pL*\s+(?:номер|телефон|контакт|имя)\pL*(?:\s+телефона)?\s*(?:,\s*)?(?:пожалуйста|\?)/u',
        '/(?<!\pL)(?:raqamingiz|telefoningiz|ismingiz|рақамингиз|телефонингиз|исмингиз)\pL*\s+(?:qanday|nima|қандай|нима)(?!\pL)/u',
        '/(?<!\pL)(?:оставьте|напишите|пришлите|отправьте|укажите|сообщите)(?!\pL)[^\n]{0,20}?(?<!\pL)(?:телефон\pL*|имя|ваш\pL*\s+(?:номер|имя|контакт)\pL*|контакт\pL*|номер\s+телефон\pL*)|(?<!\pL)(?:телефон\pL*|имя|ваш\pL*\s+(?:номер|имя|контакт)\pL*|номер\s+телефон\pL*)[^\n]{0,20}?(?<!\pL)(?:оставьте|напишите|пришлите)(?!\pL)/u',
        '/(?<!\pL)(?:send|share|leave|give|provide|tell|type|write)(?!\pL)[^\n]{0,20}?(?<!\pL)(?:(?:your|a)\s+(?:phone|number|name|contact|details)|phone\s+number|contact\s+(?:details|number|info)\pL*)|(?<!\pL)your\s+(?:phone|number|name|contact)\pL*[^\n]{0,20}?(?<!\pL)(?:send|share|leave)(?!\pL)/u',
    ];

    /**
     * Look-alike letters inside a word written in two scripts (A2-S14):
     * folded to the script most of the word is in.
     *
     * @var array<string, string>
     */
    private const TO_LATIN = ['а' => 'a', 'в' => 'b', 'е' => 'e', 'ё' => 'e', 'к' => 'k', 'м' => 'm', 'н' => 'h', 'о' => 'o', 'р' => 'p', 'с' => 'c', 'т' => 't', 'у' => 'y', 'х' => 'x', 'і' => 'i', 'ј' => 'j', 'ѕ' => 's', 'ԁ' => 'd', 'һ' => 'h', 'ӏ' => 'l', 'α' => 'a', 'ε' => 'e', 'ο' => 'o', 'ρ' => 'p', 'κ' => 'k', 'ι' => 'i', 'ν' => 'v', 'τ' => 't', 'υ' => 'u', 'χ' => 'x'];

    /**
     * @var array<string, string>
     */
    private const TO_CYRILLIC = ['a' => 'а', 'b' => 'в', 'c' => 'с', 'e' => 'е', 'h' => 'н', 'k' => 'к', 'm' => 'м', 'o' => 'о', 'p' => 'р', 't' => 'т', 'x' => 'х', 'y' => 'у'];

    /**
     * VAT words: only the configured rate right next to one is not a
     * percentage (A2-S4).
     */
    public const VAT_WORD = '(?:qqs|ққс|ндс|nds|vat)';

    /**
     * Saving framing: a sentence with one of these words gets no VAT exemption (A2-S5).
     */
    private const VAT_SAVING = '/(?<!\pL)(?:sav(?:e|es|ed|ing)|off|less|lower\pL*|refund\pL*|qaytar\pL*|teja\pL*|kamroq|эконом\pL*|меньш\pL*|верн\pL*|возврат\pL*|вычет\pL*|'.self::VAT_PROMO.'|to[\'ʻʼ‘’`]?laymiz|we\s+(?:pay|cover)|оплатим|за\s+нас)(?!\pL)/iu';

    /**
     * Promo words next to a VAT rate or in its sentence: no VAT exemption (plan 33 §19 M-4).
     */
    public const VAT_PROMO = '(?:bonus\pL*|cashback\pL*|кэшб\pL*|ke[sš]h?bek\pL*|sovg[\'ʻʼ‘’`]?a\pL*|подар\pL*|aksiya\pL*|акци\pL*)';

    /**
     * Standard prefixes: the number right after one is a standard, not an
     * amount ("ISO/IEC 18000-63", "ГОСТ 7.32"; A2-S13).
     */
    private const STANDARD_BEFORE = '/(?<!\pL)(?:iso|iec|ieee|ansi|astm|gost|гост|uz\s?dst|o[\'ʻʼ‘’`]?z\s?dst|din\s+en)(?:\s?\/\s?(?:iec|ieee|en|astm))?\s*[:-]?\s*$/u';

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
     * FOREIGN without a number: a bare "rub" ("a rub test") is not a
     * currency, "rubl…" / "rubles" still are (plan 33 A2-4). Next to a
     * number FOREIGN applies, so "500 rub" and "rub 500" stay money.
     */
    private const FOREIGN_STANDALONE = '(?:usd|\$|долл\pL*|dollar\pL*|у\.\s?е\.|y\.\s?e\.|€|eur(?:os?)?|руб(?:л\pL*)?\.?|rubl\pL*|rubles?|₽|£|¥|gbp|rur|юан\pL*|yuan|cny|тенге|tenge|kzt|₸|kgs|₩|₹|лир\pL*|aed|bucks?)';

    /**
     * Scale words that make any number an amount, also without a currency
     * (group 1: thousand, 2: million, 3: billion). Public for the AI product
     * text guard (plan 32, ProductTextGuard::hasAmount, match/no match only).
     */
    public const SCALE = '(?:(ming|минг\pL*|тыс\.?\pL*|тыщ\pL*|thousand\pL*)|(mln\.?|million\pL*|млн\.?|миллион\pL*)|(mlrd\.?|milliard\pL*|млрд\.?|миллиард\pL*|billion\pL*))';

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
     * Any currency (soʻm family or foreign), for the AI product text guard
     * (plan 32, ProductTextGuard): product texts never carry an amount. "eurs"
     * keeps the plan 32 pattern's spelling.
     */
    public const CURRENCY = '(?:'.self::SOM.'|'.self::FOREIGN.'|eurs)';

    /**
     * Units after a number in thousands groups: a quantity or a spec, not money.
     */
    private const UNITS = '(?:mm|cm|km|m|kg|g|dpi|pcs|шт\pL*\.?|dona\pL*|ta(?:dan|gacha)?|etiket\pL*|yorliq\pL*|labels?|cycles?|rolls?|мм|см|км|м|кг|г|метр\pL*|этикет\pL*|рулон\pL*|mah|мач|[kmg]?hz|кгц|мгц|ггц|гц|°c|°с|dbm|ms|мс|ips)';

    /**
     * A run of 9+ digits across up to 2 separators each: spaces, dashes,
     * brackets, dots, slashes and symbols or emoji ("8600💳1234…", A2-S14).
     */
    private const LONG_NUMBER = '/\+?\d(?:[ \-().\/\p{So}\p{Sk}\x{FE0F}]{0,2}\d){8,}/u';

    public const EAN_LABEL = '(?:ean(?:-?(?:8|13))?|gtin|upc|barcode|bar\s+code|(?:shtrix|штрих)\pL*(?:[\s-]*(?:kod|код)\pL*)?)[\s:#№-]*';

    private const EAN_WORDS = '/'.self::EAN_LABEL.'$/u';

    /**
     * @param  list<string>  $hosts  Lowercased hosts links may point to
     * @param  list<string>  $phones  Company phone numbers (any format)
     * @param  list<string>  $emails  Company emails
     * @param  list<AiPriceCandidate>  $candidates  Prices (and codes) the answer may name
     * @param  list<string>  $handles  The company's own messenger handles (no "@")
     * @param  list<string>  $brands  Lowercased catalog brand names (not handles, M2)
     * @param  list<string>|null  $allowedUrls  Own-host URLs of this request (normalizeUrl()): an own-host link with a path passes only when listed; null = any (taught rules, translations). Paths such as /go, /admin and any query string are blocked either way (plan 33 K-6)
     */
    public function __construct(
        private readonly array $hosts = [],
        private readonly array $phones = [],
        private readonly array $emails = [],
        private readonly array $candidates = [],
        private readonly array $handles = [],
        private readonly array $brands = [],
        private readonly ?array $allowedUrls = null,
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
     * check() without the money rules: for site text whose amounts are about
     * to be masked, so contacts, long numbers (phones, cards), links, payment
     * instructions, brand-unsafe wording and human claims are judged on the
     * unmasked text (plan 33 K-3). Null when it may be used.
     */
    public function checkWithoutMoney(string $text): ?string
    {
        $text = $this->clean($text);

        return trim($text) === '' ? 'empty' : $this->violation($text, false);
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

    private function violation(string $text, bool $checkMoney = true): ?string
    {
        $lower = mb_strtolower(self::normalize($text));
        $digits = $this->asciiDigits($lower);
        // Byte-length preserving: offsets in $scrubbed are offsets in $digits (same line / sentence check).
        $scrubbed = $this->withoutCodes($digits);
        // A2-S4: only the configured VAT rate next to a VAT word is blanked, and only for the brand step.
        $brand = self::withoutSpans($lower, self::vatRateSpans($lower));

        return match (true) {
            $this->hasForeignLink($lower) || $this->hasUnlistedOwnLink($lower) => 'link',
            $this->hasForeignContact($lower) => 'contact',
            // §19 Low: symbols and emoji are no separators ("8600 💳 1234 💳 5678 💳 9012").
            ($long = $this->longNumber((string) preg_replace('/[\p{So}\p{Sk}\x{FE0F}]/u', '', $scrubbed))) !== null => $long,
            $this->isPaymentOrSubmission($lower) || $this->isPaymentOrSubmission(self::fold($lower)) => 'contact',
            $this->isBrandUnsafe($brand) || $this->isBrandUnsafe(self::fold($brand)) => 'brand',
            $this->matchesAny($lower, self::HUMAN_PATTERNS) => 'human',
            $checkMoney && $this->hasForeignAmount($this->withoutAllowedContacts($scrubbed), $digits) => 'money',
            default => null,
        };
    }

    /**
     * A payment instruction (L4, A2-S1, A2-S2), another payment service than
     * UzQR (A2-S9), a claimed submission or a request for contact details (A2-S3).
     */
    private function isPaymentOrSubmission(string $text): bool
    {
        // §19 H-1: the company's own method nouns ("bank oʻtkazmasi", "bank transfer") are no instruction verb.
        $withoutMethods = (string) preg_replace(self::PAYMENT_METHODS, ' ', $text);
        $withoutMethods = implode("\n", array_map(
            fn (string $line): string => preg_match(self::CARD_WORD, $line) === 1 ? $line : (string) preg_replace(self::PAYMENT_COURIER_CASH, ' ', $line),
            explode("\n", $withoutMethods),
        ));

        return preg_match(self::PAYMENT_METHOD_DIRECTED, $text) === 1
            || $this->matchesAny($withoutMethods, self::PAYMENT_PATTERNS)
            || $this->matchesAny($text, self::PAYMENT_SERVICES)
            || $this->matchesAny($text, self::SUBMISSION_PATTERNS);
    }

    /**
     * The brand step of the output filter for a short text a person wrote
     * (a soft rule button label, §19 Low): BrandSafeText plus the AI-only
     * BRAND_PATTERNS, also on the obfuscation-folded copy. No VAT exemption.
     */
    public static function violatesBrand(string $text): bool
    {
        $lower = mb_strtolower(self::normalize($text));
        $filter = new self;

        return $filter->isBrandUnsafe($lower) || $filter->isBrandUnsafe(self::fold($lower));
    }

    private function isBrandUnsafe(string $text): bool
    {
        return BrandSafeText::violates($text) || $this->matchesAny($text, self::BRAND_PATTERNS);
    }

    /**
     * A copy of lowercase text with obfuscation undone (A2-S14), for the
     * payment and brand checks only: symbols and emoji removed, look-alike
     * letters inside a two-script word folded to its main script, ".", "-",
     * "_" between single letters dropped ("ch.e.g.i.r.m.a"), runs of 4+
     * single letters joined ("m o n e y").
     */
    public static function fold(string $lower): string
    {
        $text = (string) preg_replace('/[\p{So}\p{Sk}]/u', '', $lower);

        $text = (string) preg_replace_callback('/\pL+/u', function (array $match): string {
            $word = $match[0];
            $latin = preg_match_all('/\p{Latin}/u', $word);
            $other = preg_match_all('/[\p{Cyrillic}\p{Greek}]/u', $word);

            if ($latin === 0 || $other === 0) {
                return $word;
            }

            return strtr($word, $latin >= $other ? self::TO_LATIN : self::TO_CYRILLIC);
        }, $text);

        $text = (string) preg_replace_callback(
            '/(?<![\pL\pN])\pL{1,3}(?:[._-]\pL){2,}(?![\pL\pN])/u',
            fn (array $match): string => str_replace(['.', '-', '_'], '', $match[0]),
            $text,
        );

        return (string) preg_replace_callback(
            '/(?<![\pL\pN])\pL(?:\h+\pL(?![\pL\pN])){3,}/u',
            fn (array $match): string => (string) preg_replace('/\h+/u', '', $match[0]),
            $text,
        );
    }

    /**
     * Where the configured VAT rate stands right next to a VAT word ("12%
     * QQS", "НДС 12 %", "QQS (12%)"), as byte spans of the digits, the
     * optional space and "%" only (never the word or the separators), on
     * the string given (A2-S4, A2-S5). Shared by the brand step here and
     * KnowledgeText::maskPercents, so masking and checking agree. Empty unless
     * chatbot.ai.vat_percent is an int from 1 to 99. No span with a sign
     * ("-12%", "−12%", "+12%"), a promo word after it ("12% VAT off",
     * "12% chegirma"), or in a sentence with saving framing ("save",
     * "вернём", "qaytariladi" …, also bonus / cashback / aksiya words, §19 M-4).
     * Callers may pass mixed case: the patterns are case-insensitive and the
     * spans are byte offsets into exactly the string given.
     *
     * @return list<array{start: int, length: int}>
     */
    public static function vatRateSpans(string $text): array
    {
        $rate = config('chatbot.ai.vat_percent');

        if (! is_int($rate) || $rate < 1 || $rate > 99) {
            return [];
        }

        $promo = '(?!\s*(?:off(?!\pL)|скидк|chegirma|'.self::VAT_PROMO.'))';
        $pattern = '/(?<![\pN.,−–—+\-])('.$rate.'\s?%)\s?[(:]?\s?(?<!\pL)'.self::VAT_WORD.'(?!\pL)'.$promo
            .'|(?<!\pL)'.self::VAT_WORD.'\s?[(:]?\s?('.$rate.'\s?%)'.$promo.'/iu';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        $spans = [];

        foreach ($matches as $match) {
            [$rateText, $start] = isset($match[2]) && $match[2][1] >= 0 ? $match[2] : $match[1];

            if (preg_match(self::VAT_SAVING, self::sentenceAround($text, $start)) === 1) {
                continue;
            }

            $spans[] = ['start' => $start, 'length' => strlen($rateText)];
        }

        return $spans;
    }

    /**
     * The sentence (split at . ! ? … ; and line breaks) around a byte offset;
     * a "." before a digit is a decimal point, not a sentence end ("3.5 mm. 12% QQS").
     */
    private static function sentenceAround(string $text, int $offset): string
    {
        $before = substr($text, 0, $offset);
        $start = 0;

        if (preg_match_all('/\.(?!\d)|[!?…;\n]/u', $before, $marks, PREG_OFFSET_CAPTURE) > 0) {
            $last = end($marks[0]);
            $start = $last[1] + strlen($last[0]);
        }

        $end = preg_match('/\.(?!\d)|[!?…;\n]/u', $text, $next, PREG_OFFSET_CAPTURE, $offset) === 1 ? $next[0][1] : strlen($text);

        return substr($text, $start, $end - $start);
    }

    /**
     * The text with the byte spans replaced by spaces of the same byte length.
     *
     * @param  list<array{start: int, length: int}>  $spans
     */
    private static function withoutSpans(string $text, array $spans): string
    {
        foreach ($spans as $span) {
            $text = substr_replace($text, str_repeat(' ', $span['length']), $span['start'], $span['length']);
        }

        return $text;
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

    /**
     * Where the money amounts of a text are (byte start and length of the
     * number with its scale word and soʻm currency), for masking them in
     * knowledge text (plan 33 K-3). The text must be the exact string the
     * detector reads: NFKC, lowercase, ASCII digits (see normalizeForAmounts()),
     * and the spans are only valid on that string. Foreign-currency amounts
     * are left to check(), which blocks them.
     *
     * @return list<array{start: int, length: int}>
     */
    public static function moneySpans(string $normalized): array
    {
        $spans = [];

        foreach ((new self)->amounts($normalized) as $amount) {
            if ($amount['money'] && ! $amount['foreign']) {
                $spans[] = ['start' => $amount['start'], 'length' => max(1, $amount['end'] - $amount['start'])];
            }
        }

        return $spans;
    }

    /**
     * The string the amount detector reads: NFKC, lowercase, ASCII digits.
     */
    public static function normalizeForAmounts(string $text): string
    {
        return (new self)->asciiDigits(mb_strtolower(self::normalize($text)));
    }

    /**
     * Paths on the site's own host the model may never link to (redirects,
     * admin, Livewire, webhooks, MCP, OAuth, storage), with or without the
     * locale prefix (plan 33 K-6).
     */
    private const BLOCKED_PATHS = ['/go', '/admin', '/livewire', '/webhooks', '/mcp', '/oauth', '/storage'];

    /**
     * The lowercase key of an own-host URL for comparing: no scheme, no
     * "www.", no trailing punctuation or slash.
     */
    public static function normalizeUrl(string $url): string
    {
        $url = mb_strtolower(trim($url));
        $url = (string) preg_replace('~^[a-z][a-z0-9+.-]*://~', '', $url);
        $url = rtrim($url, '.,;:!?)]}»"\'/');

        return str_starts_with($url, 'www.') ? substr($url, 4) : $url;
    }

    /**
     * The path of an own-host URL as a browser and the server would read it:
     * percent-decoded until stable, backslashes as slashes, repeated slashes
     * collapsed, "." and ".." resolved, lowercase, no trailing slash. Null
     * when it carries a query string or fragment or a control character.
     */
    private static function normalizePath(string $rest): ?string
    {
        for ($i = 0; $i < 5; $i++) {
            $decoded = rawurldecode($rest);

            if ($decoded === $rest) {
                break;
            }

            $rest = $decoded;
        }

        if (preg_match('/[?#\x00-\x1f\x7f]/', $rest) === 1) {
            return null;
        }

        $segments = [];

        foreach (explode('/', str_replace('\\', '/', mb_strtolower($rest))) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return $segments === [] ? '' : '/'.implode('/', $segments);
    }

    /**
     * An own-host link with a path that is blocked, carries a query string or
     * fragment, or (when a list is given) is not one of this request's URLs.
     * A bare host or a locale root is harmless.
     */
    private function hasUnlistedOwnLink(string $text): bool
    {
        if ($this->hosts === []) {
            return false;
        }

        $hosts = implode('|', array_map(fn (string $host): string => preg_quote($host, '~'), $this->hosts));
        preg_match_all('~(?<![\pL\pN@._-])(?:[a-z][a-z0-9+.-]{1,15}://)?(?:'.$hosts.')(?::\d+)?([/\\\\?#][^\s<>"\']*)?~u', $text, $found, PREG_SET_ORDER);

        foreach ($found as $match) {
            $rest = rtrim($match[1] ?? '', '.,;:!?)]}»');

            if ($rest === '') {
                continue;
            }

            $path = self::normalizePath($rest);

            // A query, a fragment (also percent-encoded) or a control character: never.
            if ($path === null) {
                return true;
            }

            $bare = (string) preg_replace('~^/(?:uz|ru|en)(?=/|$)~', '', $path);

            foreach (self::BLOCKED_PATHS as $blocked) {
                if ($path === $blocked || $bare === $blocked || str_starts_with($path, $blocked.'/') || str_starts_with($bare, $blocked.'/')) {
                    return true;
                }
            }

            if ($bare === '' || $path === '') {
                continue;
            }

            if ($this->allowedUrls !== null) {
                $host = self::normalizeUrl(substr($match[0], 0, strlen($match[0]) - strlen($match[1] ?? '')));

                if (! in_array($host.$path, $this->allowedUrls, true)) {
                    return true;
                }
            }
        }

        return false;
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
        preg_match_all(self::LONG_NUMBER, $text, $matches, PREG_OFFSET_CAPTURE);

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

        return (string) preg_replace_callback(self::LONG_NUMBER, $blank, $text);
    }

    /**
     * Any amount that is not an allowed candidate price (AI-B1 – AI-B3).
     *
     * @param  string  $text  Lowercase, ASCII digits, allowed contacts and codes blanked
     * @param  string  $original  The same text before blanking (for the line / sentence check)
     */
    private function hasForeignAmount(string $text, string $original): bool
    {
        // Foreign currencies are never allowed, with or without a number (a bare "rub" is not one: "a rub test").
        if (preg_match('/(?<!\pL)'.self::FOREIGN_STANDALONE.'(?!\pL)/u', $text) === 1) {
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
     * @return list<array{offset: int, start: int, end: int, money: bool, foreign: bool, tiyin: ?int, per_label: bool, currency_at: ?int}>
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
                $end += strlen($match[0]);
                $rest = substr($rest, strlen($match[0]));
            } elseif (preg_match('/^\s*'.self::FOREIGN.'(?!\pL)/u', $rest) === 1) {
                $foreign = true;
            }

            $before = mb_scrub(substr($text, max(0, $offset - 16), min(16, $offset)), 'UTF-8');
            $start = $offset;

            if (preg_match('/(?<!\pL)'.self::FOREIGN.'\s*$/u', $before) === 1) {
                $foreign = true;
            } elseif (! $som && preg_match('/(?<!\pL)('.self::SOM.')([ \x{00A0}]?)\z/u', $before, $match) === 1 && ! isset($claimed[$offset - strlen($match[0])])) {
                // "UZS 1 250 000": the currency right in front, not the one of the amount before.
                $som = true;
                $currencyAt = $offset - strlen($match[0]);
                $start = $currencyAt;
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
            // The second number of a range ("860–960 MHz", "125-134"): the dash is not a price dash (A2-4).
            $range = preg_match('/(?<![\pL\d])\d{1,6}[–-]$/u', $before) === 1;
            // "R110 — 9000.": 3+ digits right after a dash, "=" or ":".
            $dash = ! $range && preg_match('/[—–=:]\s*$/u', mb_scrub(substr($text, max(0, $offset - 4), min(4, $offset)), 'UTF-8')) === 1;
            // A standard number right after its prefix ("ISO/IEC 18000-63"), never in a price context (A2-S13).
            $standard = ! $context && ! $grouped && preg_match(self::STANDARD_BEFORE, $before) === 1;
            $plain = ! $unit && ! $time && ! $glued && ! $port && ! $standard;
            $priced = strlen(ltrim($integer, '0')) >= 3 && $plain && ! $year && ($context || $dash);
            $money = $scale > 1 || $som || $foreign || $priced
                || ($grouped && ! $unit && strlen($integer) >= 4)
                || ($plain && strlen(ltrim($integer, '0')) >= 5)
                || strlen(ltrim($integer, '0')) >= 6;

            $amounts[] = [
                'offset' => $offset,
                'start' => $start,
                'end' => $end,
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
     * @param  array{offset: int, start: int, end: int, money: bool, foreign: bool, tiyin: ?int, per_label: bool, currency_at: ?int}  $amount
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
