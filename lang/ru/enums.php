<?php

return [

    'attribute_type' => [
        'text' => 'Текст',
        'number' => 'Число',
        'boolean' => 'Да / нет',
        'select' => 'Список (одно значение)',
        'multiselect' => 'Список (несколько значений)',
    ],

    'document_type' => [
        'datasheet' => 'Технический паспорт',
        'manual' => 'Руководство',
        'driver' => 'Драйвер',
        'firmware' => 'Прошивка',
        'certificate' => 'Сертификат',
    ],

    'price_level' => [
        'retail' => 'Розничная',
        'dealer' => 'Дилерская',
        'wholesale' => 'Оптовая',
    ],

    'product_type' => [
        'hardware' => 'Оборудование',
        'consumable' => 'Расходный материал',
        'software' => 'Программное обеспечение',
        'service' => 'Услуга',
    ],

    'product_unit' => [
        'piece' => 'шт.',
        'roll' => 'рулон',
        'box' => 'коробка',
        'pack' => 'упаковка',
        'license' => 'лицензия',
        'hour' => 'час',
    ],

    'relation_type' => [
        'accessory' => 'Аксессуар',
        'consumable' => 'Расходный материал',
        'alternative' => 'Аналог',
        'software' => 'Программное обеспечение',
    ],

    'lead_source' => [
        'site' => 'Сайт',
        'telegram' => 'Telegram',
        'phone' => 'Телефон',
        'manual' => 'Вручную',
    ],

    'lead_type' => [
        'callback' => 'Обратный звонок',
        'quote' => 'Запрос КП',
        'service_request' => 'Заявка на сервис',
        'checkout' => 'Оформление заказа',
    ],

    'product_badge' => [
        'hit' => 'Хит',
        'new' => 'Новинка',
    ],

    'role' => [
        'admin' => 'Администратор',
        'content_manager' => 'Контент-менеджер',
        'sales_manager' => 'Менеджер по продажам',
        'service_engineer' => 'Сервисный инженер',
    ],

    'sale_mode' => [
        'cart' => 'Корзина',
        'quote' => 'Запрос КП',
        'both' => 'Корзина и запрос КП',
    ],

    'stock_status' => [
        'in_stock' => 'В наличии',
        'on_order' => 'Под заказ',
        'out_of_stock' => 'Нет в наличии',
    ],

    'phone_label' => [
        'orders' => 'Отдел продаж',
        'service' => 'Сервисный центр',
        'accounting' => 'Бухгалтерия',
        'office' => 'Офис',
    ],

    'social_network' => [
        'telegram' => 'Telegram',
        'instagram' => 'Instagram',
        'facebook' => 'Facebook',
        'youtube' => 'YouTube',
        'linkedin' => 'LinkedIn',
        'tiktok' => 'TikTok',
        'whatsapp' => 'WhatsApp',
    ],

    'settings_area' => [
        'contacts' => 'Контакты',
        'legal' => 'Реквизиты',
        'social' => 'Соцсети',
        'hero' => 'Главный экран',
        'seo' => 'SEO',
        'tracking' => 'Аналитика',
        'integrations' => 'Интеграции',
    ],

    'audit_action' => [
        'updated' => 'Изменено',
        'cleared' => 'Очищено',
        'uploaded' => 'Загружено',
        'removed' => 'Удалено',
    ],

];
