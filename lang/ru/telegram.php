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

];
