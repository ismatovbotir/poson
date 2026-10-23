<?php

/*
| SI yordamchi (plan 02): admin panelidagi matn yordamchisi.
| Oʻ/gʻ — ʻ (U+02BB), tutuq belgisi — ʼ (U+02BC).
*/

return [

    'button' => [
        'label' => 'SI',
        'title' => 'SI yordamchi: matn yozish yoki tarjima qilish',
    ],

    'assistant' => [
        'title' => 'SI yordamchi',
        'field' => 'Maydon',
        'mode' => 'Rejim',
        'mode_generate' => 'Yaratish (uz/ru/en)',
        'mode_translate' => 'Tarjima (manba: :locale)',
        'translate_empty' => 'Tarjima uchun avval :locale tilidagi matnni kiriting.',
        'hint' => 'Koʻrsatma',
        'hint_reset' => 'Asl koʻrsatma',
        'hint_help' => 'Koʻrsatmani oʻzgartirishingiz mumkin. Yozuv maʼlumotlari (nomi, toifa, brend, xususiyatlar) avtomatik qoʻshiladi.',
        'generate' => 'Yaratish',
        'translate' => 'Tarjima qilish',
        'remaining' => 'Bugun qoldi: :count / :limit',
        'pending' => 'SI matn yozmoqda…',
        'retry' => 'Qayta urinish',
        'source' => 'Manba',
        'brand_warning' => 'Brend qoidasiga zid boʻlishi mumkin, tekshiring:',
        'review_uz' => 'Nashrdan oldin tekshiring: oʻzbekcha matnni muharrir koʻzdan kechirishi kerak.',
        'apply_locale' => 'Qoʻllash (:locale)',
        'apply_all' => 'Hammasini qoʻllash',
        'apply_help' => 'Matn formaga qoʻyiladi, lekin saqlanmaydi. Tekshirib, formani odatdagidek saqlang.',
        'too_long' => ':max belgidan uzun: qisqartiring yoki qayta yarating.',
    ],

    'applied_title' => 'Matn qoʻllandi',
    'applied_one' => ':locale matni formaga qoʻyildi. Saqlashni unutmang.',
    'applied_all' => 'Matnlar formaga qoʻyildi. Saqlashni unutmang.',

    'errors' => [
        'timeout' => 'SI javobi juda uzoq kutildi. Qayta urinib koʻring.',
        'rate_limited' => 'SI xizmatiga juda koʻp soʻrov yuborildi. Bir daqiqadan soʻng qayta urinib koʻring.',
        'provider_error' => 'SI xizmatida xatolik yuz berdi. Qayta urinib koʻring.',
        'invalid_output' => 'SI javobi toʻliq emas. Qayta urinib koʻring.',
        'not_configured' => 'Kalit sozlanmagan: Sozlamalar → Integratsiyalar boʻlimida Anthropic kalitini kiriting.',
        'disabled' => 'SI oʻchirilgan. Uni Sozlamalar → Integratsiyalar boʻlimida yoqish mumkin.',
        'quota_exceeded' => 'Kunlik limit tugadi. Ertaga yana foydalanishingiz mumkin.',
        'budget_exceeded' => 'Oylik byudjet tugadi. Administratorga murojaat qiling.',
        'unknown_field' => 'Bu maydon uchun SI yordamchi mavjud emas.',
        'legal_body' => 'Huquqiy sahifa matnini SI yozmaydi: uni yurist bilan qoʻlda tahrirlang.',
        'hint_invalid' => 'Koʻrsatmani kiriting (:max belgigacha).',
        'source_empty' => 'Tarjima uchun matn boʻsh.',
        'unknown_locale' => 'Nomaʼlum til. Tillardan birini tanlang: uz, ru yoki en.',
    ],

    'usage' => [
        'title' => 'Foydalanish va limitlar',
        'settings' => 'SI sozlamalari',
        'not_configured' => 'Kalit sozlanmagan: Anthropic kaliti kiritilmagan, SI ishlamaydi.',
        'disabled' => 'SI oʻchirilgan. Tugmalar formalarda koʻrinmaydi.',
        'today' => 'Bugun',
        'daily_limit' => 'Har bir foydalanuvchiga kuniga :limit ta',
        'month' => 'Shu oy',
        'requests' => 'Soʻrovlar soni',
        'month_cost' => 'Shu oy xarajati',
        'budget' => 'Oylik byudjet: :budget',
        'no_budget' => 'Oylik byudjet belgilanmagan',
        'budget_used' => 'Byudjet sarfi',
        'per_user' => 'Foydalanuvchilar boʻyicha',
        'per_user_help' => 'Bugun va shu oy, :timezone vaqti boʻyicha. Rad etilgan soʻrovlar limitga kirmaydi.',
        'no_requests_month' => 'Shu oy hali SI soʻrovlari yoʻq.',
        'log' => 'Soʻrovlar jurnali',
        'columns' => [
            'user' => 'Foydalanuvchi',
            'today' => 'Bugun',
            'month' => 'Shu oy',
            'tokens' => 'Tokenlar',
            'cost' => 'Xarajat',
            'status' => 'Holat',
            'feature' => 'Vazifa',
            'model' => 'Model',
            'duration' => 'Davomiylik',
            'source_locale' => 'Manba tili',
        ],
        'filters' => [
            'all_users' => 'Barcha foydalanuvchilar',
            'all_statuses' => 'Barcha holatlar',
            'all_features' => 'Barcha vazifalar',
            'clear' => 'Filtrlarni tozalash',
        ],
        'no_results' => 'Hech narsa topilmadi',
        'no_results_body' => 'Filtrlarni oʻzgartirib koʻring.',
        'empty_title' => 'Hali SI soʻrovlari yoʻq',
        'empty_body' => 'Formalardagi SI tugmasi orqali yuborilgan soʻrovlar shu yerda koʻrinadi.',
        'deleted_user' => 'Oʻchirilgan foydalanuvchi',
        'brand_warnings' => 'Brend ogohlantirishi',
        'pruned' => 'Koʻrsatma va javob matnlari 90 kundan soʻng oʻchirildi.',
        'prompt' => 'Koʻrsatma',
        'seconds' => ':value s',
    ],

    'languages' => [
        'uz' => 'oʻzbekcha',
        'ru' => 'ruscha',
        'en' => 'inglizcha',
    ],

    /*
    | Har bir maydon uchun standart koʻrsatma (panelda tahrirlanadi).
    | Oʻrinbosarlar: {name} {category} {brand} {specs} {question}; {from} — manba tili.
    */
    'hints' => [
        'translate' => 'Matnni (manba tili: {from}) boshqa tillarga tarjima qiling. Maʼno, atamalar va formatlashni saqlang; har bir tilda tabiiy yozing.',
        'product' => [
            'name' => 'Mahsulotning katalogdagi nomini yozing: mahsulot turi, brend va model, masalan «Yorliq printeri Zebra ZD421». Hozirgi nomi: {name}. Brend: {brand}. Toifa: {category}.',
            'short_description' => '{name} uchun mahsulot kartochkasiga qisqa tavsif yozing (1–2 gap): bu nima va biznesning qaysi vazifalari uchun. Toifa: {category}.',
            'description' => '{name} ({brand}, {category}) mahsulot sahifasi uchun biznes mijozlarga moʻljallangan tavsif yozing: vazifasi va odatiy qoʻllanilishi, xususiyatlarga asoslangan asosiy imkoniyatlari hamda Poson qanday yordam berishi (tanlash, sozlash, rasmiy kafolat, servis markazi). 2–4 ta qisqa xatboshi yoki bitta xatboshi va roʻyxat.',
            'meta_title' => '{name} mahsulot sahifasi uchun SEO sarlavha yozing: avval mahsulot turi va model, keyin «Poson». Narxlarsiz.',
            'meta_description' => '{name} ({category}) uchun SEO tavsif yozing: bu nima va Posondan xarid qilishning bitta servis afzalligi (sozlash, rasmiy kafolat, servis). Narxlarsiz.',
        ],
        'category' => [
            'name' => '«{name}» uchun katalog toifasining tushunarli nomini taklif qiling. Ota toifa: {category}. Qisqa, kerak boʻlsa koʻplikda.',
            'description' => '«{name}» katalog toifasi (ota toifa: {category}) uchun kirish matnini yozing: bu mahsulotlar nima uchun kerak, tanlashda qaysi xususiyatlarga qarash kerak va Poson tanlash, sozlash va servisda qanday yordam beradi. 2–3 ta qisqa xatboshi.',
            'meta_title' => '«{name}» katalog toifasi uchun SEO sarlavha yozing, oxirida «Poson». Narxlarsiz.',
            'meta_description' => '«{name}» katalog toifasi uchun SEO tavsif yozing: mijoz bu yerda nimani topadi va Posonning bitta servis afzalligi (mutaxassis tanlovi, sozlash, rasmiy kafolat). Narxlarsiz.',
        ],
        'brand' => [
            'description' => '{name} brendi sahifasi uchun qisqa kirish matnini yozing: u qanday uskunalar ishlab chiqaradi va Poson ularni tanlash, sozlash va servis qilishda yordam beradi. Tarix, raqamlar va mukofotlarni oʻylab topmang.',
        ],
        'page' => [
            'title' => 'Poson saytidagi «{name}» sahifasi uchun tushunarli sarlavha yozing. Qisqa, narxlarsiz.',
            'body' => 'Biznes mijozlar uchun «{name}» sayt sahifasi matnini joriy matn va kontekstdagi faktlar asosida yozing: H2 sarlavhali boʻlimlar, qisqa xatboshilar va roʻyxatlar. Poson servisini taʼkidlang (mutaxassis tanlovi, sozlash, rasmiy kafolat, oʻz servis markazi). Manzil, telefon, narx va muddatlarni oʻylab topmang.',
            'meta_title' => '«{name}» sayt sahifasi uchun SEO sarlavha yozing, oxirida «Poson». Narxlarsiz.',
            'meta_description' => '«{name}» sayt sahifasi uchun SEO tavsif yozing: tashrif buyuruvchi unda nimani topadi. Narxlarsiz.',
        ],
        'news' => [
            'title' => 'Poson uchun «{name}» haqida tushunarli yangilik sarlavhasini yozing. Qisqa va faktlarga asoslangan, narxlarsiz.',
            'excerpt' => '«{name}» yangiligi uchun yangiliklar roʻyxatiga bir-ikki gaplik qisqacha mazmun yozing, kontekstdagi matnga asoslaning. Narxlarsiz.',
            'body' => 'Biznes mijozlar uchun Posonning «{name}» yangiligi matnini joriy matn va kontekstdagi faktlar asosida yozing: qisqa kirish, soʻng H2 sarlavhali boʻlimlar, qisqa xatboshilar va roʻyxatlar. Poson servisini taʼkidlang (mutaxassis tanlovi, sozlash, rasmiy kafolat, oʻz servis markazi). Sana, raqam, narx va iqtiboslarni oʻylab topmang.',
            'meta_title' => '«{name}» yangiligi uchun SEO sarlavha yozing, oxirida «Poson». Narxlarsiz.',
            'meta_description' => '«{name}» yangiligi uchun SEO tavsif yozing: oʻquvchi nimani bilib oladi. Narxlarsiz.',
        ],
        'faq' => [
            'question' => 'Biznes mijozning {name} haqida tez-tez beradigan bitta savolini taklif qiling. Toifa: {category}.',
            'answer' => '«{question}» savoliga aniq javob yozing (mavzu: {name}). Faqat yozuvdagi maʼlumotlardan foydalaning; javob model yoki buyurtmaga bogʻliq boʻlsa, shuni ayting va Poson bilan bogʻlanishni taklif qiling.',
        ],
    ],

];
