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

    'order' => [
        'placed' => 'Yangi buyurtma :number',
        'total' => 'Summa',
        'payment_method' => 'Toʻlov',
        'delivery' => 'Yetkazib berish',
        'delivery_separate' => 'alohida toʻlanadi',
        'products' => 'Mahsulotlar',
        'contact' => 'Mijoz',
        'company' => 'Kompaniya',
        'amount' => 'Summa',
        'method' => 'Usul',
        'reference' => 'Toʻlov raqami',
        'paid_on' => 'Toʻlov sanasi',
        'by' => 'Kiritgan',
        'below_reference' => 'Buyurtmada narxi mijoz darajasi narxidan past pozitsiyalar bor.',
        'open_settings' => 'Sozlamalarni ochish',
        'payment' => [
            'paid' => ':number boʻyicha toʻlov qabul qilindi',
            'delivery_paid' => ':number boʻyicha yetkazib berish toʻlandi',
            'refunded' => ':number boʻyicha qaytarish yozildi',
            'voided' => ':number boʻyicha toʻlov bekor qilindi',
        ],
        'uzqr' => [
            'uploaded' => 'Toʻlov uchun UzQR kodi yuklandi',
            'replaced' => 'Toʻlov uchun UzQR kodi almashtirildi',
            'removed' => 'Toʻlov uchun UzQR kodi oʻchirildi',
            'check' => 'Bu oʻzgarish kutilganini tekshiring.',
        ],
    ],

];
