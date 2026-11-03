<?php

/**
 * Customer-facing Telegram bot texts (plain text, no parse_mode).
 */
return [
    // Shown before a language is chosen: same string in uz/ru/en.
    'language' => [
        'prompt' => "👋 Poson rasmiy botiga xush kelibsiz! Tilni tanlang.\nДобро пожаловать в официальный бот Poson! Выберите язык.\nWelcome to the official Poson bot! Choose a language.",
        'buttons' => [
            'uz' => '🇺🇿 Oʻzbekcha',
            'ru' => '🇷🇺 Русский',
            'en' => '🇬🇧 English',
        ],
        'changed' => 'Til oʻzgartirildi ✅',
    ],

    // Approved draft (telegram-privacy-text.md §2). Ends with the privacy link.
    'consent' => [
        'text' => '«Telefon raqamini ulashish» tugmasini bosib, Telegram profilingiz, telefon raqamingiz, ismingiz, kompaniya va STIR (ixtiyoriy) hamda yozishmalaringizni Poson murojaatlarga javob berish, taklif va servis uchun qayta ishlashiga rozilik berasiz. Rozilikni qaytarib olish mumkin. Batafsil: :privacy_url',
        'share_button' => '📞 Telefon raqamini ulashish',
        'privacy_link_label' => 'Maxfiylik siyosati',
    ],

    'contact' => [
        // Shared contact belongs to someone else.
        'not_own' => 'Bu sizning raqamingiz emas. Iltimos, quyidagi tugma orqali oʻz telefon raqamingizni ulashing.',
        // Phone could not be read.
        'invalid' => 'Telefon raqamini aniqlab boʻlmadi. Iltimos, quyidagi tugmadan foydalaning.',
        // User typed text instead of sharing contact.
        'use_button' => 'Davom etish uchun quyidagi tugma orqali telefon raqamingizni ulashing.',
    ],

    'name' => [
        'confirm' => 'Sizga :name deb murojaat qilsak boʻladimi?',
        'confirm_yes' => 'Ha, toʻgʻri ✅',
        'change' => 'Ismni oʻzgartirish',
        'prompt' => 'Iltimos, ismingizni yozing.',
        'invalid' => 'Ism 2 tadan 120 tagacha belgidan iborat boʻlishi kerak. Qayta yozing.',
    ],

    'company' => [
        'prompt' => 'Kompaniyangiz nomini yozing (ixtiyoriy). Oʻtkazib yuborish mumkin.',
        'invalid' => 'Kompaniya nomi juda uzun yoki notoʻgʻri. Qisqaroq yozing yoki oʻtkazib yuboring.',
    ],

    'inn' => [
        'prompt' => 'Kompaniyangiz STIRini yozing (9 ta raqam, ixtiyoriy). Oʻtkazib yuborish mumkin.',
        'invalid' => 'STIR 9 ta raqamdan iborat boʻlishi kerak. Qayta yozing yoki oʻtkazib yuboring.',
    ],

    // Registration finished.
    'welcome' => 'Rahmat, :name! 👋 Roʻyxatdan oʻtish yakunlandi. Poson jamoasi uskunani tanlash, sozlash, kafolat va servis boʻyicha yordam beradi. Savolingizni shu yerda yozing — menejer javob beradi.',

    'menu' => [
        'main' => 'Asosiy menyu. Savolingizni yozing yoki quyidagi tugmalardan foydalaning.',
        'contact_manager' => 'Menejer bilan bogʻlanish',
        'catalog' => 'Katalog',
        'language' => 'Til',
        'contact_manager_reply' => 'Savolingizni shu yerda yozing, menejer ish vaqtida javob beradi.',
        'catalog_reply' => 'Uskunalar katalogi: :url',
    ],

    'help' => 'Bu Poson rasmiy boti. Shtrix-kod skanerlari, printerlar, TSD va POS uskunalari boʻyicha menejerga savol yozishingiz, katalogni ochishingiz va tilni oʻzgartirishingiz mumkin. Savolingizni shu yerda yozing.',

    // Free text/media before registration is done.
    'registration' => [
        'continue' => 'Avval roʻyxatdan oʻtishni yakunlaymiz.',
    ],

    'rate_limited' => 'Xabarlar juda koʻp, iltimos, biroz kuting.',

    'common' => [
        'skip' => 'Oʻtkazib yuborish',
        'back' => 'Orqaga',
    ],

    // Used later by the inbox (auto-ack at most once per 30 min).
    'inbox' => [
        'auto_ack' => 'Rahmat, xabaringiz qabul qilindi. Menejer tez orada javob beradi.',
        'unsupported' => 'Iltimos, matn, rasm yoki hujjat yuboring.',
    ],
];
