<?php

/*
| Telegram messages (plan 05c). Language: Settings → Integrations → Telegram
| (telegram.locale, default uz). Values go into parse_mode=HTML after e().
*/

return [

    'lead' => [
        'title' => 'Новая заявка :reference',
        'channel' => 'Источник',
        'products' => 'Товары',
        'more_items' => 'и ещё :count',
        'contact' => 'Клиент',
        'foreign_phone' => 'Иностранный номер',
        'company' => 'Компания',
        'comment' => 'Комментарий',
        'duplicate' => 'Повтор: уже есть заявка :reference',
        'customer' => 'Клиент в CRM',
        'open_in_admin' => 'Открыть в админке',
    ],

    'price_sync' => [
        'title' => 'Проблемы синхронизации цен, пакет #:id',
        'token' => 'Токен',
        'no_token' => 'удалённый токен',
        'rejected' => 'Пакет отклонён целиком: :error',
        'counts' => 'Получено :received · обновлено :updated · без изменений :unchanged · неизвестно :unknown · с ошибкой :invalid',
        'unknown_codes' => 'Неизвестные коды',
        'more_codes' => 'и ещё :count',
        'open_log' => 'Открыть пакет',
    ],

    'order' => [
        'placed' => 'Новый заказ :number',
        'total' => 'Сумма',
        'payment_method' => 'Оплата',
        'delivery' => 'Доставка',
        'delivery_separate' => 'оплачивается отдельно',
        'products' => 'Товары',
        'contact' => 'Клиент',
        'foreign_phone' => 'Иностранный номер',
        'company' => 'Компания',
        'amount' => 'Сумма',
        'method' => 'Способ',
        'reference' => 'Номер платежа',
        'paid_on' => 'Дата оплаты',
        'by' => 'Записал',
        'below_reference' => 'В заказе есть позиции с ценой ниже цены уровня клиента.',
        'open_settings' => 'Открыть настройки',
        'payment' => [
            'paid' => 'Получена оплата по :number',
            'delivery_paid' => 'Оплачена доставка по :number',
            'refunded' => 'Записан возврат по :number',
            'voided' => 'Аннулирован платёж по :number',
        ],
        'uzqr' => [
            'uploaded' => 'Загружен UzQR-код для оплаты',
            'replaced' => 'UzQR-код для оплаты заменён',
            'removed' => 'UzQR-код для оплаты удалён',
            'check' => 'Проверьте, что это изменение ожидалось.',
        ],
    ],

    'uzqr' => [
        'unmatched' => 'Оплата UzQR по заказу :number получена, но не зачтена',
        'unmatched_hint' => 'Заказ не изменён. Администратор должен принять решение на странице заказа.',
        'resolved' => 'Решение по незачтённой оплате UzQR по заказу :number принято',
        'hold_released' => 'Снято удержание UzQR по заказу :number',
        'hold_released_hint' => 'Заказ снова можно оплатить. Банк ещё может принять оплату по старому QR: она будет зачислена или отмечена как несопоставленная автоматически.',
        'problem' => 'Проблема интеграции UzQR',
        'settings_changed' => 'Изменены настройки UzQR',
        'settings_check' => 'Если вы не ожидали этого изменения, сразу проверьте Настройки → Интеграции.',
        'labels' => [
            'amount' => 'Сумма',
            'reason' => 'Причина',
            'resolution' => 'Решение',
            'by' => 'Кто',
            'order' => 'Заказ',
            'fields' => 'Поля',
        ],
    ],

    'mcp' => [
        'events' => [
            'granted' => 'Выдан доступ MCP',
            'transferred' => 'Доступ MCP передан',
            'access_revoked' => 'Доступ MCP отозван',
            'token_generated' => 'Создан новый токен MCP',
            'token_regenerated' => 'Токен MCP перевыпущен',
            'token_revoked' => 'Токен MCP отозван',
            'token_settings' => 'Изменены настройки токена MCP',
            'first_use' => 'Токен MCP использован впервые',
            'new_ip' => 'Токен MCP использован с нового IP-адреса',
            'new_client' => 'Токен MCP использован с нового клиента',
            'revoked_used' => 'Кто-то использовал отозванный токен MCP',
            'ip_blocked' => 'Токен MCP использован с неразрешённого адреса',
            'rotation' => 'Токен MCP пора заменить',
            'post_published' => 'Claude опубликовал материал',
            'post_scheduled' => 'Claude запланировал публикацию материала',
            'post_unpublished' => 'Материал, опубликованный Claude, снят с публикации',
        ],
        'labels' => [
            'holder' => 'Владелец',
            'previous' => 'Прежний владелец',
            'by' => 'Кто',
            'label' => 'Устройство',
            'reason' => 'Причина',
            'tokens' => 'Отозвано токенов',
            'ip' => 'IP',
            'client' => 'Клиент',
            'days' => 'Возраст, дней',
            'title' => 'Заголовок',
            'public_url' => 'Страница на сайте',
            'admin_url' => 'Страница в админке',
        ],
        'hint' => 'Если вы этого не ожидали, сразу отзовите доступ в разделе «Настройки → Доступ MCP».',
        'post_hint' => 'Если вы этого не ожидали, снимите материал с публикации в «Настройки → Доступ MCP → Журнал вызовов».',
        'open' => 'Открыть доступ MCP',
    ],

    // Plan 14 v2 B4: customer bot inbox (header of copied files, new-message alert). Never phone, INN or company.
    'inbox' => [
        'customer' => 'Клиент:',
        'chat' => 'Диалог Telegram #:id',
        'unnamed' => 'без имени',
        'alert_title' => 'Новое сообщение в Telegram-боте',
        'open_in_admin' => 'Открыть в админке',
    ],

    'monitoring' => [
        'events' => [
            'cron_dead' => 'Планировщик не работает',
            'task_failed' => 'Плановая задача завершилась ошибкой',
            'queue_stuck' => 'Очередь застряла',
            'worker_silent' => 'Обработчик очереди молчит',
            'foreign_phone_cap' => 'Превышен часовой лимит иностранных номеров на сайте',
            'telegram_webhook' => 'Проблема с вебхуком Telegram-бота',
            'telegram_token' => 'Telegram не принимает токен бота (401)',
            'telegram_customer_cap' => 'Превышен часовой лимит новых клиентов из Telegram-бота (регистрации не привязаны к CRM)',
            'telegram_forward' => 'Telegram-бот не может переслать файлы клиентов в чат',
        ],
        'recovered' => [
            'cron_dead' => 'Планировщик снова работает',
            'task_failed' => 'Плановая задача снова работает',
            'queue_stuck' => 'Очередь снова идёт',
            'worker_silent' => 'Обработчик очереди вернулся',
            'telegram_webhook' => 'Вебхук Telegram-бота снова работает',
            'telegram_token' => 'Telegram снова принимает токен бота',
        ],
        'labels' => [
            'task' => 'Задача',
            'at' => 'Время',
            'exit_code' => 'Код выхода',
            'error' => 'Ошибка',
            'age' => 'Минут',
            'pending' => 'Задач в очереди',
            'limit' => 'Лимит в час',
            'issue' => 'Проблема',
            'reason' => 'Причина',
        ],
        // Plan 14 v2 B4: why customer files are not copied to the inbox chat.
        'forward_reasons' => [
            'lost_target' => 'бот удалён из чата или не может в нём писать (файлы не пересылаются)',
            'refused_target' => 'чат публичный, это канал или он не найден (принимается только закрытая группа)',
            'conversation_limit' => 'больше 30 файлов в час из одного диалога',
            'daily_limit' => 'больше 300 файлов за день',
        ],
        // Plan 14 v2 TG-38: telegram:check-webhook issue names (never the URL or Telegram's error text).
        'webhook_issues' => [
            'url_mismatch' => 'вебхук указывает на другой адрес или не установлен',
            'url_unexpected' => 'бот выключен, но вебхук указывает на неизвестный адрес (возможна утечка токена)',
            'delivery_errors' => 'ошибки доставки за последние 15 минут',
            'backlog' => 'ожидают больше 100 обновлений',
        ],
        'reminder' => 'Проблема всё ещё не устранена.',
        'open' => 'Открыть мониторинг',
    ],
];
