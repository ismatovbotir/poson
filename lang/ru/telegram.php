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
        ],
        'hint' => 'Если вы этого не ожидали, сразу отзовите доступ в разделе «Настройки → Доступ MCP».',
        'open' => 'Открыть доступ MCP',
    ],
];
