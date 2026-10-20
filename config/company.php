<?php

/*
|--------------------------------------------------------------------------
| Company defaults
|--------------------------------------------------------------------------
|
| Defaults for the public site. Rows in the "settings" table (same keys)
| override these values; read them through App\Support\CompanySettings.
| Translatable values are arrays keyed by locale (uz is required).
|
| Brand rule: no low-price or discount claims anywhere in this file.
|
*/

return [

    // TODO: placeholder, replace with the real sales phone (E.164).
    'phone' => '+998000000000',

    'email' => 'info@poson.uz',

    'telegram_url' => 'https://t.me/poson_uz',

    // TODO: real office address.
    'address' => [
        'uz' => 'Toshkent sh., manzil aniqlanmoqda',
        'ru' => 'г. Ташкент, адрес уточняется',
        'en' => 'Tashkent, address TBD',
    ],

    'city' => [
        'uz' => 'Toshkent',
        'ru' => 'Ташкент',
        'en' => 'Tashkent',
    ],

    'working_hours' => [
        'uz' => 'Du–Sh 9:00–18:00',
        'ru' => 'Пн–Сб 9:00–18:00',
        'en' => 'Mon–Sat 9:00–18:00',
    ],

    // TODO: confirm these stats with the client before launch.
    'hero_stats' => [
        [
            'value' => ['uz' => '12 oy', 'ru' => '12 мес.', 'en' => '12 months'],
            'label' => ['uz' => 'kafolat', 'ru' => 'гарантия', 'en' => 'warranty'],
        ],
        [
            'value' => ['uz' => 'Oʻz', 'ru' => 'Свой', 'en' => 'Own'],
            'label' => ['uz' => 'servis markazi', 'ru' => 'сервисный центр', 'en' => 'service centre'],
        ],
        [
            'value' => ['uz' => 'Toshkent va viloyatlar', 'ru' => 'Ташкент и регионы', 'en' => 'Tashkent and regions'],
            'label' => ['uz' => 'yetkazib berish', 'ru' => 'доставка', 'en' => 'delivery'],
        ],
    ],

    // List of ['network' => 'instagram', 'url' => 'https://...'].
    'social' => [],

];
