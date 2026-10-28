<?php

/*
| Savat va buyurtma rasmiylashtirish (11-reja). Brend qoidasi (CLAUDE.md
| «Brand positioning»): faqat servis haqida; narxlar oddiy koʻrsatiladi.
*/

return [

    'cart' => [
        'title' => 'Savat',
        'seo_description' => 'Poson savatingizdagi mahsulotlar.',
        'lines' => ':count ta mahsulot|:count ta mahsulot',
        'quantity' => 'Miqdor',
        'decrease' => 'Miqdorni kamaytirish',
        'increase' => 'Miqdorni koʻpaytirish',
        'min_hint' => 'Kamida :count :unit',
        'added' => 'Savatga qoʻshildi',
        'go_to_cart' => 'Savatga oʻtish',
        'empty_title' => 'Savat boʻsh',
        'empty_text' => 'Katalogdan uskunani tanlang: mutaxassislarimiz uni sozlash va ishga tushirishda yordam beradi.',
        'to_catalog' => 'Katalogga oʻtish',
        'unavailable' => 'Endi mavjud emas',
        'unavailable_product' => 'Mahsulot endi mavjud emas',
        'unavailable_hint' => 'Bu mahsulotni savatdan olib tashlang yoki u boʻyicha tijorat taklifini soʻrang.',
        'on_order_note' => 'Yetkazib berish muddatini menejer tasdiqlaydi',
        'per_roll' => ':price / rulon',
        'per_unit' => ':price / :unit',
        'remove' => 'Olib tashlash',
        'summary' => 'Jami',
        'total' => 'Jami',
        'vat_included' => 'Narxlar QQS bilan koʻrsatilgan.',
        'remove_unavailable' => 'Endi mavjud boʻlmagan mahsulotlarni savatdan olib tashlang.',
        'benefits' => [
            'warranty' => 'Rasmiy kafolat va oʻz servis markazimiz',
            'setup' => 'Uskunani sozlash va integratsiya',
            'delivery' => 'Ishonchli ombor zaxirasi va yetkazib berish',
        ],
        'quote' => [
            'button' => 'Savat boʻyicha taklif soʻrash',
            'title' => 'Savat boʻyicha tijorat taklifi',
            'text' => 'Kontaktlaringizni qoldiring: menejer savatdagi mahsulotlar boʻyicha taklif tayyorlaydi, mavjudligi va yetkazib berish muddatlarini aniqlaydi.',
            'success_text' => 'Menejer savatdagi mahsulotlar boʻyicha tez orada siz bilan bogʻlanadi.',
            'message_placeholder' => 'Izoh: yetkazib berish manzili, muddatlar, qoʻshimcha talablar',
            'submit' => 'Taklif soʻrash',
        ],
        'errors' => [
            'disabled' => 'Savat hozircha ishlamayapti. Tijorat taklifini soʻrang — biz yordam beramiz.',
            'unavailable' => 'Bu mahsulotni savatga qoʻshib boʻlmaydi. Tijorat taklifini soʻrang — biz yordam beramiz.',
            'quantity' => 'Miqdorni :min dan :max gacha tanlang.',
            'too_many_lines' => 'Savatga koʻpi bilan :max ta mahsulot sigʻadi. Katta buyurtma uchun tijorat taklifini soʻrang.',
            'line_missing' => 'Bu mahsulot endi savatda yoʻq.',
            'closed' => 'Bu savat boʻyicha buyurtma allaqachon rasmiylashtirilgan.',
            'rate_limited' => 'Urinishlar juda koʻp. :seconds soniyadan keyin qayta urinib koʻring.',
            'nothing_to_quote' => 'Savatda tijorat taklifi uchun mahsulot yoʻq.',
        ],
    ],

];
