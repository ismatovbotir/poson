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

];
