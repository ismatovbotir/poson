<?php

/*
|--------------------------------------------------------------------------
| Company defaults
|--------------------------------------------------------------------------
|
| Defaults for the public site. Rows in the "settings" table (same keys,
| edited in Admin → Settings → Company) override these values; a row
| replaces the whole value of its key. Read them only through
| App\Support\CompanySettings, which re-validates every value on read.
| Translatable values are arrays keyed by locale (uz is required).
|
| Brand rule: no low-price or discount claims anywhere in this file.
| Secrets never go here (see Settings → Integrations, plan 04b).
|
*/

return [

    /*
    | Contacts
    */

    'name' => ['uz' => 'Poson', 'ru' => 'Poson', 'en' => 'Poson'],

    // List of ['label' => PhoneLabel value, 'number' => E.164, 'primary' => bool, 'site' => bool].
    // The primary number is the main one on the site (topbar, header, JSON-LD telephone).
    'phones' => [
        ['label' => 'orders', 'number' => '+998915010757', 'primary' => true, 'site' => true],
        ['label' => 'orders', 'number' => '+998985559393', 'primary' => false, 'site' => true],
    ],

    'email' => 'info@poson.uz',

    'address' => [
        'uz' => 'Qoʻqon sh., Poʻstindoʻz koʻchasi, 14A',
        'ru' => 'г. Коканд, ул. Пустиндуз, 14А',
        'en' => '14A Postindoz street, Kokand',
    ],

    'city' => [
        'uz' => 'Qoʻqon',
        'ru' => 'Коканд',
        'en' => 'Kokand',
    ],

    // ['lat' => float, 'lng' => float] or null. The map link is built from it (Yandex, plan 04 S9).
    'geo' => ['lat' => 40.545429, 'lng' => 70.934588],

    // Map link template; {lat} and {lng} are replaced. No pasted embeds.
    'map_url_template' => 'https://yandex.uz/maps/?pt={lng},{lat}&z=17&l=map',

    // Embedded map of the contacts page (plan 07): loaded only after a "Show map" click.
    'map_embed_template' => 'https://yandex.uz/map-widget/v1/?ll={lng},{lat}&z=16&pt={lng},{lat},pm2rdm',

    'working_hours' => [
        'uz' => 'Du–Sh 9:00–18:00',
        'ru' => 'Пн–Сб 9:00–18:00',
        'en' => 'Mon–Sat 9:00–18:00',
    ],

    // Machine-readable hours for LocalBusiness JSON-LD:
    // list of ['days' => ['mo', 'tu', ...], 'opens' => 'HH:MM', 'closes' => 'HH:MM'].
    'opening_hours' => [
        ['days' => ['mo', 'tu', 'we', 'th', 'fr', 'sa'], 'opens' => '09:00', 'closes' => '18:00'],
    ],

    /*
    | Legal requisites (Settings → Company → Legal). Empty until entered.
    */

    'legal' => [
        'legal_name' => ['uz' => '', 'ru' => '', 'en' => ''],
        'inn' => '',
        'vat_code' => '',
        'bank' => '',
        'mfo' => '',
        'account' => '',
        'oked' => '',
        'director' => '',
        'legal_address' => ['uz' => '', 'ru' => '', 'en' => ''],
    ],

    /*
    | Social links: list of ['network' => SocialNetwork value, 'url' => 'https://...'].
    | The first Telegram link is also the topbar "Telegram" link.
    */

    'social' => [
        ['network' => 'telegram', 'url' => 'https://t.me/poson_uz'],
        ['network' => 'instagram', 'url' => 'https://www.instagram.com/posonuz'],
        ['network' => 'facebook', 'url' => 'https://facebook.com/posonuz'],
        ['network' => 'youtube', 'url' => 'https://www.youtube.com/@posonuz'],
    ],

    /*
    | Home hero: exactly three stats.
    | TODO: the client must confirm these facts before launch (official
    | warranty, own service centre, uz/ru/en support). No unverifiable numbers.
    */

    'hero_stats' => [
        [
            'value' => ['uz' => 'Rasmiy', 'ru' => 'Официальная', 'en' => 'Official'],
            'label' => ['uz' => 'kafolat', 'ru' => 'гарантия', 'en' => 'warranty'],
        ],
        [
            'value' => ['uz' => 'Oʻz', 'ru' => 'Свой', 'en' => 'Own'],
            'label' => ['uz' => 'servis markazi', 'ru' => 'сервисный центр', 'en' => 'service center'],
        ],
        [
            'value' => ['uz' => 'uz/ru/en', 'ru' => 'uz/ru/en', 'en' => 'uz/ru/en'],
            'label' => ['uz' => 'qoʻllab-quvvatlash', 'ru' => 'поддержка', 'en' => 'support'],
        ],
    ],

    /*
    | SEO: search engine verification codes (the content value of the meta tag only).
    */

    'verification' => [
        'google' => '',
        'yandex' => '',
        'bing' => '',
    ],

    /*
    | Tracking (plan 04 S8): injected by resources/js/analytics.js only in
    | production (or ANALYTICS_FORCE), when enabled, and never for staff.
    */

    'tracking' => [
        'enabled' => false,
        'ga4' => '',
        'metrika' => '',
    ],

    /*
    | robots.txt per AI crawler (Settings → Company → AI bots): bot key from
    | config/ai-bots.php => 'allow' | 'deny'. Empty = allow all (decision
    | 2026-10-21). Stored under the setting key "robots.ai_bots".
    */

    'robots' => [
        'ai_bots' => [],
    ],

];
