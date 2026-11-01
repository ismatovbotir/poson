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

    'uzqr' => [
        'unmatched' => ':number buyurtma boʻyicha UzQR toʻlovi olindi, lekin hisoblanmadi',
        'unmatched_hint' => 'Buyurtma oʻzgartirilmadi. Administrator buyurtma sahifasida qaror qabul qilishi kerak.',
        'resolved' => ':number buyurtma boʻyicha hisoblanmagan UzQR toʻlovi hal qilindi',
        'hold_released' => ':number buyurtma boʻyicha UzQR band qilishi olib tashlandi',
        'hold_released_hint' => 'Buyurtmani yana toʻlash mumkin. Bank eski QR boʻyicha toʻlovni hali qabul qilishi mumkin: u avtomatik ravishda buyurtmaga qoʻllanadi yoki mos kelmagan deb belgilanadi.',
        'problem' => 'UzQR integratsiyasida muammo',
        'settings_changed' => 'UzQR sozlamalari oʻzgartirildi',
        'settings_check' => 'Agar bu oʻzgarishni kutmagan boʻlsangiz, darhol Sozlamalar → Integratsiyalarni tekshiring.',
        'labels' => [
            'amount' => 'Summa',
            'reason' => 'Sabab',
            'resolution' => 'Qaror',
            'by' => 'Kim',
            'order' => 'Buyurtma',
            'fields' => 'Maydonlar',
        ],
    ],

    'mcp' => [
        'events' => [
            'granted' => 'MCP kirish berildi',
            'transferred' => 'MCP kirish oʻtkazildi',
            'access_revoked' => 'MCP kirish bekor qilindi',
            'token_generated' => 'Yangi MCP token yaratildi',
            'token_regenerated' => 'MCP token qayta yaratildi',
            'token_revoked' => 'MCP token bekor qilindi',
            'token_settings' => 'MCP token sozlamalari oʻzgardi',
            'first_use' => 'MCP token birinchi marta ishlatildi',
            'new_ip' => 'MCP token yangi IP manzildan ishlatildi',
            'new_client' => 'MCP token yangi mijoz dasturidan ishlatildi',
            'revoked_used' => 'Kimdir bekor qilingan MCP tokenni ishlatdi',
            'ip_blocked' => 'MCP token ruxsat etilmagan manzildan ishlatildi',
            'rotation' => 'MCP tokenni almashtirish vaqti keldi',
        ],
        'labels' => [
            'holder' => 'Egasi',
            'previous' => 'Oldingi egasi',
            'by' => 'Kim',
            'label' => 'Qurilma',
            'reason' => 'Sabab',
            'tokens' => 'Bekor qilingan tokenlar',
            'ip' => 'IP',
            'client' => 'Mijoz dasturi',
            'days' => 'Yoshi, kun',
        ],
        'hint' => 'Agar buni kutmagan boʻlsangiz, darhol Sozlamalar → MCP kirish boʻlimida kirishni bekor qiling.',
        'open' => 'MCP kirishni ochish',
    ],
];
