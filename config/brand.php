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
        // English: whole words, so "wholesale" or "salesforce" don't match.
        '/\bdiscounts?\b/u',
        '/\bcheap(er|est)?\b/u',
        '/\blow prices?\b/u',
        '/\bsales?\b/u',
        '/\bpromos?\b/u',
        // "−12%" / "-12 %" badges, but not ranges such as "10-90%" in specs.
        '/(?<![\pL\pN])[−-]\d+\s?%/u',
    ],

];
