<?php

/*
| Staff inbox of the Telegram bot (plan 21 A1): Admin → Telegram → Chats.
*/

return [

    'title' => 'Чаты',
    'subtitle' => 'Переписка клиентов с Telegram-ботом',
    'search_placeholder' => 'Имя, username, компания или телефон',
    'unnamed' => 'Клиент без имени',
    'unassigned' => 'Не назначен',
    'open' => 'Открыть диалог',
    'empty_title' => 'Диалогов пока нет',
    'empty_body' => 'Здесь появятся сообщения, которые клиенты напишут боту.',
    'no_results' => 'Ничего не найдено',
    'no_results_body' => 'Измените фильтры или поиск.',
    'unread_count' => ':count новых',
    'scopes' => [
        'all' => 'Все',
        'mine' => 'Мои',
        'unassigned' => 'Без менеджера',
        'unread' => 'Непрочитанные',
    ],
    'filters' => [
        'scope' => 'Показать',
        'status' => 'Статус',
        'status_all' => 'Любой статус',
        'clear' => 'Сбросить фильтры',
    ],
    'columns' => [
        'customer' => 'Клиент',
        'status' => 'Статус',
        'manager' => 'Менеджер',
        'last_message' => 'Последнее сообщение',
    ],
    'badges' => [
        'unregistered' => 'Не зарегистрирован',
        'banned' => 'Заблокирован',
        'blocked' => 'Заблокировал бота',
        'stuck' => 'Доставка зависла',
    ],
    'banners' => [
        'banned' => 'Клиент заблокирован. Его сообщения не принимаются.',
        'blocked' => 'Клиент заблокировал бота, поэтому сообщения не доставляются.',
        'account_changed' => 'Telegram-аккаунт клиента изменился. Убедитесь, кто пишет, прежде чем сообщать данные.',
        'unregistered' => 'Посетитель не завершил регистрацию в боте.',
    ],
    'show' => [
        'title' => 'Диалог',
        'subtitle' => 'Чат №:id',
        'back' => 'Назад к чатам',
        'load_older' => 'Загрузить более ранние сообщения',
        'refresh' => 'Обновить',
        'older_hidden' => 'Более ранние сообщения не показаны (показаны последние :count).',
        'no_messages' => 'Сообщений пока нет.',
        'from_customer' => 'Клиент',
        'from_bot' => 'Бот',
        'forward' => 'Командный чат',
    ],
    'panel' => [
        'title' => 'Клиент',
        'name' => 'Имя',
        'username' => 'Username',
        'phone' => 'Телефон',
        'company' => 'Компания',
        'inn' => 'ИНН',
        'locale' => 'Язык',
        'status' => 'Диалог',
        'manager' => 'Менеджер',
        'registered_at' => 'Дата регистрации',
        'last_inbound_at' => 'Последнее сообщение клиента',
    ],

];
