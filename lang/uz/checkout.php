<?php

/*
| Savat va buyurtma rasmiylashtirish (11-reja). Brend qoidasi (CLAUDE.md
| «Brand positioning»): faqat servis haqida; narxlar oddiy koʻrsatiladi.
*/

return [

    'cart' => [
        'title' => 'Savat',
        'checkout' => 'Buyurtmani rasmiylashtirish',
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

    'title' => 'Buyurtmani rasmiylashtirish',
    'seo_description' => 'Posonda buyurtmani rasmiylashtirish.',
    'back_to_cart' => 'Savatga qaytish',
    'place_order' => 'Buyurtma berish',

    'sections' => [
        'buyer' => 'Xaridor',
        'delivery' => 'Yetkazib berish',
        'payment' => 'Toʻlov',
    ],

    'fields' => [
        'buyer_type' => 'Xaridor',
        'name' => 'Ismingiz',
        'phone' => 'Telefon',
        'company_name' => 'Kompaniya nomi',
        'tin' => 'Kompaniya STIRi',
        'tin_or_pinfl' => 'STIR yoki JSHSHIR',
        'delivery_type' => 'Yetkazib berish',
        'region' => 'Viloyat',
        'region_placeholder' => 'Viloyatni tanlang',
        'address' => 'Yetkazib berish manzili',
        'kokand_confirmed' => 'Manzil Qoʻqon shahrida',
        'comment' => 'Izoh',
        'comment_placeholder' => 'Qulay vaqt, kirish yoʻli, qoʻshimcha istaklar',
        'payment_method' => 'Toʻlov usuli',
    ],

    'hints' => [
        'stir' => '9 raqam, 2 yoki 3 bilan boshlanadi.',
        'stir_or_pinfl' => 'STIR: 4–6 bilan boshlanuvchi 9 raqam. JSHSHIR: 14 raqam.',
    ],

    'delivery' => [
        'hints' => [
            'pickup' => 'Qoʻqondagi ofisimiz va servis markazimizdan.',
            'courier_kokand' => 'Qoʻqon boʻylab yetkazib berish: 0 soʻm. Menejer manzilni telefon orqali tasdiqlaydi.',
            'courier_region' => 'Viloyatingizga; viloyatlar boʻyicha narx quyida.',
        ],
        'by_manager' => 'menejer tasdiqlaydi',
        'kokand_line' => 'Qoʻqon boʻylab yetkazib berish',
    ],

    'payment' => [
        'none' => 'Bu buyurtma uchun mos toʻlov usuli yoʻq. Savatdan tijorat taklifini soʻrang — yordam beramiz.',
        'hints' => [
            'payme' => 'Payme orqali karta bilan onlayn.',
            'click' => 'Click orqali karta bilan onlayn.',
            'uzqr_static' => 'Buyurtma sahifasidagi UzQR kodimizni bank ilovasida skanerlang.',
            'on_receipt' => 'Buyurtmani olganda naqd, karta yoki UzQR orqali.',
            'bank_transfer' => 'Kompaniyalar va YaTTlar uchun toʻlov hisobi.',
            'uzqr_dynamic' => 'UzQR.',
        ],
    ],

    'summary' => [
        'goods' => 'Mahsulotlar',
        'delivery' => 'Yetkazib berish',
        'vat' => 'Narxlar QQS :percent% bilan.',
    ],

    'errors' => [
        'empty' => 'Savatingiz boʻsh.',
        'invalid_lines' => 'Baʼzi mahsulotlar endi mavjud emas. Ularni savatdan olib tashlang.',
        'changed' => 'Narxlar yoki mavjudlik yangilandi, buyurtmani tekshirib chiqing.',
        'total_too_high' => 'Bu buyurtma onlayn qabul qilinadigan summadan katta. Savatdan tijorat taklifini soʻrang.',
        'payment_unavailable' => 'Bu toʻlov usuli buyurtma uchun mavjud emas.',
        'kokand_confirm' => 'Manzil Qoʻqon shahrida ekanini tasdiqlang.',
        'try_again' => 'Formani tekshirib, qayta urinib koʻring.',
        'rate_limited' => 'Urinishlar juda koʻp. :seconds soniyadan keyin qayta urinib koʻring.',
    ],

    'settings' => [
        'title' => 'Buyurtma rasmiylashtirish',
        'subtitle' => 'Yetkazib berish narxlari, buyurtma chegaralari, fiskal kodlar va UzQR kodi.',
        'regions_title' => 'Viloyatlarga kuryer',
        'regions_hint' => 'Viloyat boʻyicha narx butun soʻmda. Boʻsh: menejer tasdiqlaydi va alohida toʻlanadi.',
        'by_manager' => 'alohida toʻlanadi',
        'kokand_note' => 'Olib ketish va Qoʻqon boʻylab kuryer: 0 soʻm.',
        'limits_title' => 'Buyurtma chegaralari',
        'max_total' => 'Eng katta buyurtma summasi, soʻm',
        'max_online_total' => 'Eng katta onlayn toʻlov (Payme / Click), soʻm',
        'max_delivery_charge' => 'Eng katta yetkazib berish toʻlovi, soʻm',
        'max_manual_discount_percent' => 'Qoʻlda belgilanadigan narxning quyi chegarasi, %',
        'fiscal_title' => 'Fiskal kodlar',
        'fiscal_hint' => 'Payme va Click cheklari uchun har bir pozitsiyada IKPU kerak.',
        'delivery_ikpu' => 'Yetkazib berish IKPU kodi',
        'delivery_package_code' => 'Yetkazib berish qadoq kodi',
        'require_ikpu_online' => 'Pozitsiyada IKPU boʻlmasa, Payme / Click yashirilsin',
        'uzqr_title' => 'Statik UzQR',
        'uzqr_hint' => 'Kompaniyaning UzQR kodi. Mijoz aniq summani toʻlaydi; xodimlar bank koʻchirmasi bilan solishtiradi. Har bir oʻzgarish moliya va sotuv chatlariga yuboriladi.',
        'uzqr_recipient' => 'Qabul qiluvchi nomi',
        'uzqr' => 'UzQR kodi rasmi',
        'hints' => [
            'max_total' => 'Bundan katta summali buyurtma berib boʻlmaydi.',
            'max_online_total' => 'Bundan yuqori summada Payme va Click taklif qilinmaydi.',
            'max_delivery_charge' => 'Alohida toʻlanadigan yetkazib berishning yuqori chegarasi.',
            'max_manual_discount_percent' => 'Sotuv menejeri qoʻlda yaratilgan buyurtmada narxni mijoz darajasi narxidan qanchaga past belgilay oladi. 0: hech qachon (faqat administrator).',
            'ikpu' => 'tasnif.soliq.uz dagi 17 raqam.',
            'package_code' => 'tasnif.soliq.uz dagi qadoq (birlik) kodi.',
            'require_ikpu_online' => 'Tavsiya etiladi: IKPUsiz cheklarni soliq xizmati qabul qilmaydi.',
            'uzqr_recipient' => 'Kod yonida koʻrsatiladi, masalan kompaniyaning yuridik nomi.',
            'uzqr' => 'PNG, JPG yoki WebP, 1 MB gacha, 100–2000 px. PNG ga qayta kodlanadi; avvalgi kod audit uchun saqlanadi.',
        ],
        'errors' => [
            'animated' => 'Animatsiyali rasmlar qabul qilinmaydi.',
        ],
    ],

];
