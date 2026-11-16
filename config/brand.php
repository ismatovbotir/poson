<?php

/*
|--------------------------------------------------------------------------
| Brand rule (CLAUDE.md "Brand positioning")
|--------------------------------------------------------------------------
|
| Poson sells on service, never on low price. These patterns are matched
| against lowercased text: by the App\Rules\BrandSafeText validation rule
| (admin input shown on the site) and by the tests' expectNoBannedWording().
|
| Word stems for Cyrillic/Uzbek (they inflect) anchored at a word start,
| whole words for English, plus "−12%" / "-12%" badges.
|
*/

return [

    'banned_patterns' => [
        // Cyrillic / Uzbek stems, matched only at a word start so words that
        // merely contain them pass ("редакция", "транзакция", "реакция");
        // "акционер…" (joint-stock) and "акциз" (excise) are excluded too.
        '/(?<!\pL)скидк/u',
        '/(?<!\pL)акци(?!онер|з)/u',
        '/(?<!\pL)(?:по|у)?дешев/u',
        '/(?<!\pL)(?:по|у)?дешёв/u',
        '/(?<!\pL)цена ниже/u',
        '/(?<!\pL)arzon/u',
        '/(?<!\pL)chegirma/u',
        // Latin transliteration of "скидка" (also what fullwidth "ＳＫＩＤＫＡ" becomes after NFKC).
        '/(?<!\pL)skidk/u',
        // "50% off" / "50% скидка" / "50% chegirma" as a separate sentence (plain "N%" stays allowed for specs).
        '/(?<![\pL\pN])\d+\s?%\s*(?:off|скидк|chegirma)/u',
        // English: whole words, so "wholesale" or "salesforce" don't match.
        '/\bdiscounts?\b/u',
        '/\bcheap(er|est)?\b/u',
        '/\blow prices?\b/u',
        '/\bsales?\b/u',
        '/\bpromos?\b/u',
        // "−12%" / "-12 %" badges, but not ranges such as "10-90%" in specs.
        '/(?<![\pL\pN])[−-]\d+\s?%/u',
    ],

    /*
    | Narrow allowances per context (App\Rules\BrandSafeText $context): matched
    | against the lowercased text and blanked out before banned_patterns run.
    | careers (plan 31): "sales" as part of a job or department name only
    | ("sales manager", "sales department", "sales team", "B2B sales", "head of
    | sales"). Never followed by "!" or "%", so promo usages stay blocked.
    | BrandSafeText applies allowances per sentence (split on . ; newline) and
    | skips them in any sentence containing "!", "%", "‼", "⁉" or "N%", after
    | NFKC normalisation (fullwidth "！" / "％" count too).
    */
    'allowances' => [
        // product: POS equipment and service wording; "sale!" or "sale -20%" stay
        // blocked by the per-sentence "!"/"%" rule in BrandSafeText.
        'product' => [
            '/\bpoint[ \-]of[ \-]sale\b/u',
            '/\bafter[ \-]sales(?:\s+(?:service|support))?\b/u',
            '/\bsales\s+(?:receipt|report|invoice|slip)s?\b/u',
        ],
        'careers' => [
            '/\bsales(?=[ \-]+(?:managers?|department|team|engineers?|representatives?|specialists?|director|lead|assistants?|consultants?|executives?|operations|office)\b)(?![^.\n]*!)/u',
            '/\b(?:b2b|b2c|corporate|key account|head of|director of)\s+sales\b(?!\s*[!%])/u',
        ],
    ],

];
