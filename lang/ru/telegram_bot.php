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
        'changed' => 'Язык изменён ✅',
    ],

    // Approved draft (telegram-privacy-text.md §2). Ends with the privacy link.
    'consent' => [
        'text' => 'Нажимая «Поделиться номером телефона», вы соглашаетесь, что Poson обрабатывает данные вашего профиля Telegram, номер телефона, имя, компанию и ИНН (по желанию) и переписку — чтобы отвечать на обращения, готовить предложения и сервис. Согласие можно отозвать. Подробнее: :privacy_url',
        'share_button' => '📞 Поделиться номером телефона',
        'privacy_link_label' => 'Политика конфиденциальности',
    ],

    'contact' => [
        // Shared contact belongs to someone else.
        'not_own' => 'Это не ваш номер. Пожалуйста, поделитесь собственным номером телефона с помощью кнопки ниже.',
        // Phone could not be read.
        'invalid' => 'Не удалось распознать номер телефона. Пожалуйста, воспользуйтесь кнопкой ниже.',
        // User typed text instead of sharing contact.
        'use_button' => 'Чтобы продолжить, поделитесь номером телефона с помощью кнопки ниже.',
    ],

    'name' => [
        'confirm' => 'Можем обращаться к вам :name?',
        'confirm_yes' => 'Да, верно ✅',
        'change' => 'Изменить имя',
        'prompt' => 'Пожалуйста, напишите, как к вам обращаться.',
        'invalid' => 'Имя должно содержать от 2 до 120 символов. Попробуйте ещё раз.',
    ],

    'company' => [
        'prompt' => 'Напишите название вашей компании (необязательно). Этот шаг можно пропустить.',
        'invalid' => 'Название компании слишком длинное или некорректное. Напишите короче или пропустите шаг.',
    ],

    'inn' => [
        'prompt' => 'Напишите ИНН вашей компании (9 цифр, необязательно). Этот шаг можно пропустить.',
        'invalid' => 'ИНН должен состоять из 9 цифр. Попробуйте ещё раз или пропустите шаг.',
    ],

    // Registration finished.
    'welcome' => 'Спасибо, :name! 👋 Регистрация завершена. Команда Poson поможет подобрать оборудование, настроить его, оформить гарантию и обслужить. Напишите свой вопрос прямо здесь — он поступит нашей команде.',

    'menu' => [
        'main' => 'Главное меню. Напишите вопрос или выберите кнопку ниже.',
        'contact_manager' => 'Связаться с менеджером',
        'catalog' => 'Каталог',
        'language' => 'Язык',
        'contact_manager_reply' => 'Напишите свой вопрос здесь — он поступит нашей команде.',
        'catalog_reply' => 'Каталог оборудования: :url',
    ],

    'help' => 'Это официальный бот Poson. Здесь можно написать свой вопрос по сканерам штрих-кода, принтерам, ТСД и POS-оборудованию, открыть каталог и сменить язык. Просто напишите свой вопрос.',

    // Free text/media before registration is done.
    'registration' => [
        'continue' => 'Давайте сначала завершим регистрацию.',
    ],

    'rate_limited' => 'Слишком много сообщений, пожалуйста, подождите немного.',

    'common' => [
        'skip' => 'Пропустить',
        'back' => 'Назад',
    ],

    // Inbox (StoreInboundMessage): auto-ack at most once per 30 min; "unsupported" at most once per 10 min.
    'inbox' => [
        'auto_ack' => 'Спасибо, мы получили ваше сообщение.',
        'unsupported' => 'Пожалуйста, отправьте текст, фото или документ.',
    ],
];
