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
        'changed' => 'Language changed ✅',
    ],

    // Approved draft (telegram-privacy-text.md §2). Ends with the privacy link.
    'consent' => [
        'text' => 'By tapping "Share phone number" you agree that Poson processes your Telegram profile details, phone number, name, company and INN (optional) and your messages to answer enquiries and prepare quotes and service. You can withdraw consent. Details: :privacy_url',
        'share_button' => '📞 Share phone number',
        'privacy_link_label' => 'Privacy policy',
    ],

    'contact' => [
        // Shared contact belongs to someone else.
        'not_own' => 'This is not your own number. Please share your own phone number using the button below.',
        // Phone could not be read.
        'invalid' => 'We could not read the phone number. Please use the button below.',
        // User typed text instead of sharing contact.
        'use_button' => 'To continue, please share your phone number using the button below.',
    ],

    'name' => [
        'confirm' => 'Shall we call you :name?',
        'confirm_yes' => 'Yes, that is right ✅',
        'change' => 'Change name',
        'prompt' => 'Please type your name.',
        'invalid' => 'The name must be 2 to 120 characters long. Please try again.',
    ],

    'company' => [
        'prompt' => 'Type your company name (optional). You can skip this step.',
        'invalid' => 'The company name is too long or not valid. Please shorten it or skip this step.',
    ],

    'inn' => [
        'prompt' => 'Type your company INN (STIR), 9 digits (optional). You can skip this step.',
        'invalid' => 'The INN must be exactly 9 digits. Please try again or skip this step.',
    ],

    // Registration finished.
    'welcome' => 'Thank you, :name! 👋 Registration is complete. The Poson team helps you choose equipment, set it up, and look after warranty and service. Just write your question here and it will reach our team.',

    'menu' => [
        'main' => 'Main menu. Type your question or use the buttons below.',
        'contact_manager' => 'Contact a manager',
        'catalog' => 'Catalog',
        'language' => 'Language',
        'contact_manager_reply' => 'Write your question here and it will reach our team.',
        'catalog_reply' => 'Equipment catalog: :url',
    ],

    'help' => 'This is the official Poson bot. You can send your question about barcode scanners, printers, mobile computers and POS equipment, open the catalog and change the language. Just write your question here.',

    // Free text/media before registration is done.
    'registration' => [
        'continue' => 'Let us finish registration first.',
    ],

    'rate_limited' => 'Too many messages, please wait a moment.',

    'common' => [
        'skip' => 'Skip',
        'back' => 'Back',
    ],

    // Inbox (StoreInboundMessage): auto-ack at most once per 30 min; "unsupported" at most once per 10 min.
    'inbox' => [
        'auto_ack' => 'Thanks, we have received your message.',
        'unsupported' => 'Please send text, a photo or a document.',
    ],
];
