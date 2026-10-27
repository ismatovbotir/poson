<?php

/*
| Telegram messages (plan 05c). Language: Settings → Integrations → Telegram
| (telegram.locale, default uz). Values go into parse_mode=HTML after e().
*/

return [

    'lead' => [
        'title' => 'Yangi murojaat :reference',
        'channel' => 'Manba',
        'products' => 'Mahsulotlar',
        'more_items' => 'va yana :count ta',
        'contact' => 'Mijoz',
        'company' => 'Kompaniya',
        'comment' => 'Izoh',
        'duplicate' => 'Takroriy: avval :reference murojaati bor',
        'customer' => 'CRMdagi mijoz',
        'open_in_admin' => 'Admin panelda ochish',
    ],

    'price_sync' => [
        'title' => 'Narxlar sinxronizatsiyasida muammo, paket #:id',
        'token' => 'Token',
        'no_token' => 'oʻchirilgan token',
        'rejected' => 'Paket butunlay rad etildi: :error',
        'counts' => 'Qabul qilindi :received · yangilandi :updated · oʻzgarmadi :unchanged · nomaʼlum :unknown · xato :invalid',
        'unknown_codes' => 'Nomaʼlum kodlar',
        'more_codes' => 'va yana :count ta',
        'open_log' => 'Paketni ochish',
    ],

];
