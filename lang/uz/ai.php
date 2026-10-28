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
        'not_configured' => 'Kalit sozlanmagan: Sozlamalar → Integratsiyalar boʻlimida tanlangan SI provayderining (Anthropic yoki OpenRouter) kalitini kiriting.',
        'disabled' => 'SI oʻchirilgan. Uni Sozlamalar → Integratsiyalar boʻlimida yoqish mumkin.',
        'quota_exceeded' => 'Kunlik limit tugadi. Ertaga yana foydalanishingiz mumkin.',
        'budget_exceeded' => 'Oylik byudjet tugadi. Administratorga murojaat qiling.',
        'no_credits' => 'OpenRouter hisobida kreditlar tugagan. Administratordan hisobni toʻldirishni yoki bepul model tanlashni soʻrang.',
        'model_unavailable' => 'Tanlangan SI modeli hozir mavjud emas. Administratordan Sozlamalar → Integratsiyalar boʻlimida boshqa model tanlashni soʻrang.',
        'invalid_key' => 'SI provayderi kalitni rad etdi. Administratordan uni Sozlamalar → Integratsiyalar boʻlimida tekshirishni soʻrang.',
        'refused' => 'SI provayderi soʻrovni bajarishni rad etdi (masalan, moderatsiya tufayli). Koʻrsatmalarni oʻzgartiring yoki administratordan boshqa model tanlashni soʻrang.',
        'bad_request' => 'SI provayderi soʻrovni notoʻgʻri deb rad etdi. Administratordan Sozlamalar → Integratsiyalar boʻlimida SI modelini tekshirishni soʻrang.',
        'field_retired' => 'Bu maydon uchun SI yordamchi endi mavjud emas.',
        'unknown_field' => 'Bu maydon uchun SI yordamchi mavjud emas.',
        'legal_body' => 'Huquqiy sahifa matnini SI yozmaydi: uni yurist bilan qoʻlda tahrirlang.',
        'hint_invalid' => 'Koʻrsatmani kiriting (:max belgigacha).',
        'source_empty' => 'Tarjima uchun matn boʻsh.',
        'unknown_locale' => 'Nomaʼlum til. Tillardan birini tanlang: uz, ru yoki en.',
    ],

    'usage' => [
        'title' => 'Foydalanish va limitlar',
        'settings' => 'SI sozlamalari',
        'not_configured' => 'Kalit sozlanmagan: :provider kaliti kiritilmagan, SI ishlamaydi.',
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
            'provider' => 'Provayder',
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
    | Har bir maydon guruhi uchun namuna yozuv: «Sozlamalar → SI promptlari»
    | boʻlimida promptni oldindan koʻrish (12-reja).
    */
    'samples' => [
        'products' => ['name' => 'Zebra ZD421 yorliq printeri', 'brand' => 'Zebra', 'category' => 'Printerlar › Yorliq printerlari'],
        'categories' => ['name' => 'Yorliq printerlari', 'category' => 'Printerlar'],
        'brands' => ['name' => 'Zebra'],
        'pages' => ['name' => 'Yetkazib berish va toʻlov'],
        'news' => ['name' => 'Zebra ZD421 printerlari yana omborda'],
        'articles' => ['name' => 'Shtrix-kod skanerini qanday tanlash kerak'],
        'faq' => ['name' => 'Yorliq printerlari', 'category' => 'Printerlar', 'question' => 'Bu printerga qaysi yorliqlar mos keladi?'],
        'banners' => ['name' => 'Shtrix-kod skanerlari', 'category' => 'Skanerlar'],
    ],

    /*
    | Har bir maydon uchun standart koʻrsatma (panelda tahrirlanadi).
    | Administrator oʻzgartirgan matn (Sozlamalar → SI promptlari, 12-reja) ularni almashtiradi.
    | Maydon oʻrinbosarlari: FieldSpec::$placeholders ({specs} emas); {from} — manba tili.
    */
    'hints' => [
        'translate' => 'Matnni (manba tili: {from}) boshqa tillarga tarjima qiling. Maʼno, atamalar va formatlashni saqlang; har bir tilda tabiiy yozing.',
        'product' => [
            'short_description' => '{name} uchun mahsulot kartochkasiga qisqa tavsif yozing (1–2 gap): bu nima va biznesning qaysi vazifalari uchun. Toifa: {category}.',
            'description' => '{name} ({brand}, {category}) mahsulot sahifasi uchun biznes mijozlarga moʻljallangan tavsif yozing: vazifasi va odatiy qoʻllanilishi, xususiyatlarga asoslangan asosiy imkoniyatlari hamda Poson qanday yordam berishi (tanlash, sozlash, rasmiy kafolat, servis markazi). 2–4 ta qisqa xatboshi yoki bitta xatboshi va roʻyxat.',
            'meta_title' => '{name} mahsulot sahifasi uchun SEO sarlavha yozing: avval mahsulot turi va model, keyin «Poson». Narxlarsiz.',
            'meta_description' => '{name} ({category}) uchun SEO tavsif yozing: bu nima va Posondan xarid qilishning bitta servis afzalligi (sozlash, rasmiy kafolat, servis). Narxlarsiz.',
            'image_alt' => '{name} ({brand}, {category}) fotosi uchun qisqa alt matn yozing: mahsulot turi, brend va model koʻrsatilgan bitta tavsiflovchi ibora, «… fotosi» yoki «… rasmi» soʻzlarisiz va reklama soʻzlarisiz. Fotoning oʻrni (asosiy yoki N tadan n-chi) kontekstda bor: uni faqat fotolarni farqlashga yordam bersa koʻrsating. Narxlarsiz.',
        ],
        'category' => [
            'description' => '«{name}» katalog toifasi (ota toifa: {category}) uchun kirish matnini yozing: bu mahsulotlar nima uchun kerak, tanlashda qaysi xususiyatlarga qarash kerak va Poson tanlash, sozlash va servisda qanday yordam beradi. 2–3 ta qisqa xatboshi.',
            'meta_title' => '«{name}» katalog toifasi uchun SEO sarlavha yozing, oxirida «Poson». Narxlarsiz.',
            'meta_description' => '«{name}» katalog toifasi uchun SEO tavsif yozing: mijoz bu yerda nimani topadi va Posonning bitta servis afzalligi (mutaxassis tanlovi, sozlash, rasmiy kafolat). Narxlarsiz.',
        ],
        'brand' => [
            'description' => '{name} brendi sahifasi uchun qisqa kirish matnini yozing: u qanday uskunalar ishlab chiqaradi va Poson ularni tanlash, sozlash va servis qilishda yordam beradi. Tarix, raqamlar va mukofotlarni oʻylab topmang.',
            'meta_title' => '{name} brendi sahifasi uchun SEO sarlavha yozing: brend va u ishlab chiqaradigan asosiy uskuna turlari, oxirida «Poson». Narxlarsiz.',
            'meta_description' => '{name} brendi sahifasi uchun SEO tavsif yozing: brend qanday uskunalar ishlab chiqaradi va Poson ularni mutaxassis tanlovi, sozlash, rasmiy kafolat va servis bilan yetkazib beradi. Narxlarsiz.',
        ],
        'page' => [
            'body' => 'Biznes mijozlar uchun «{name}» sayt sahifasi matnini joriy matn va kontekstdagi faktlar asosida yozing: H2 sarlavhali boʻlimlar, qisqa xatboshilar va roʻyxatlar. Poson servisini taʼkidlang (mutaxassis tanlovi, sozlash, rasmiy kafolat, oʻz servis markazi). Manzil, telefon, narx va muddatlarni oʻylab topmang.',
            'meta_title' => '«{name}» sayt sahifasi uchun SEO sarlavha yozing, oxirida «Poson». Narxlarsiz.',
            'meta_description' => '«{name}» sayt sahifasi uchun SEO tavsif yozing: tashrif buyuruvchi unda nimani topadi. Narxlarsiz.',
        ],
        'news' => [
            'excerpt' => '«{name}» yangiligi uchun yangiliklar roʻyxatiga bir-ikki gaplik qisqacha mazmun yozing, kontekstdagi matnga asoslaning. Narxlarsiz.',
            'body' => 'Biznes mijozlar uchun Posonning «{name}» yangiligi matnini joriy matn va kontekstdagi faktlar asosida yozing: qisqa kirish, soʻng H2 sarlavhali boʻlimlar, qisqa xatboshilar va roʻyxatlar. Poson servisini taʼkidlang (mutaxassis tanlovi, sozlash, rasmiy kafolat, oʻz servis markazi). Sana, raqam, narx va iqtiboslarni oʻylab topmang.',
            'meta_title' => '«{name}» yangiligi uchun SEO sarlavha yozing, oxirida «Poson». Narxlarsiz.',
            'meta_description' => '«{name}» yangiligi uchun SEO tavsif yozing: oʻquvchi nimani bilib oladi. Narxlarsiz.',
        ],
        'article' => [
            'excerpt' => '«{name}» qoʻllanmasi uchun maqolalar roʻyxatiga bir-ikki gaplik qisqacha mazmun yozing: u qaysi amaliy vazifada yordam beradi va kimlar uchun. Narxlarsiz.',
            'body' => 'Biznes mijozlar uchun «{name}» amaliy qoʻllanmasini joriy matn va kontekstdagi faktlar asosida yozing. Bu yangilik emas, yoʻriqnoma: H2 sarlavhali boʻlimlar (qadamlar, tanlash mezonlari, keng tarqalgan xatolar, qisqa nazorat roʻyxati), qisqa xatboshilar va roʻyxatlar. Texnik atamalarni qisqacha tushuntiring. Faqat kontekstdagi faktlardan foydalaning; raqam, sana va xususiyatlarni oʻylab topmang. Oxirida Poson qanday yordam berishi haqida bitta xatboshi (mutaxassis tanlovi, sozlash va integratsiya, rasmiy kafolat, oʻz servis markazi). Narxlarsiz.',
            'meta_title' => '«{name}» qoʻllanmasi uchun qidiruv soʻroviga mos SEO sarlavha yozing (masalan, «… qanday tanlanadi» yoki «… boʻyicha qoʻllanma»), oxirida «Poson». Narxlarsiz.',
            'meta_description' => '«{name}» qoʻllanmasi uchun SEO tavsif yozing: oʻquvchi nimani bilib oladi va qaysi vazifa uchun. Narxlarsiz.',
        ],
        'faq' => [
            'answer' => '«{question}» savoliga aniq javob yozing (mavzu: {name}). Faqat yozuvdagi maʼlumotlardan foydalaning; javob model yoki buyurtmaga bogʻliq boʻlsa, shuni ayting va Poson bilan bogʻlanishni taklif qiling.',
        ],
        'banner' => [
            'title' => '«{name}» banneri uchun sarlavha yozing (90 belgigacha; bosh sahifa slayderi yoki {category} toifasi banneri): Posonning bitta aniq servis afzalligi (mutaxassis tanlovi, sozlash va integratsiya, rasmiy kafolat, oʻz servis markazi, mavjudlik va yetkazib berish). Narxlarsiz, shoshiltirishsiz va muddat cheklovlarisiz.',
            'text' => '«{name}» banneri uchun kichik sarlavha yozing (200 belgigacha, bir-ikki gap; bosh sahifa slayderi yoki {category} toifasi banneri): u sarlavhani Posonning aniq servis afzalligi bilan quvvatlasin. Narxlarsiz, shoshiltirishsiz va muddat cheklovlarisiz.',
        ],
    ],

];
