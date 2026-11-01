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
        'retail' => 'Розница',
        'dealer' => 'Дилер',
        'wholesale' => 'Опт',
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

    'product_pricing_unit' => [
        'unit' => 'За единицу',
        'label' => 'За этикетку',
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

    'lead_status' => [
        'new' => 'Новая',
        'in_progress' => 'В работе',
        'qualified' => 'Квалифицирована',
        'converted' => 'Конвертирована',
        'lost' => 'Потеряна',
    ],

    'lead_lost_reason' => [
        'no_response' => 'Не отвечает',
        'not_relevant' => 'Неактуально',
        'bought_elsewhere' => 'Купил в другом месте',
        'out_of_stock' => 'Нет в наличии',
        'budget' => 'Не хватило бюджета',
        'duplicate' => 'Дубль',
        'spam' => 'Спам',
        'other' => 'Другое',
    ],

    'customer_type' => [
        'company' => 'Юрлицо',
        'individual' => 'Физлицо',
    ],

    'activity_type' => [
        'created' => 'Создана',
        'note' => 'Заметка',
        'call' => 'Звонок',
        'status_change' => 'Смена статуса',
        'assignment' => 'Назначение',
        'notification' => 'Уведомление',
        'conversion' => 'Конвертация',
        'price_level_change' => 'Смена уровня цен',
        'quote' => 'Коммерческое предложение',
        'order' => 'Заказ',
        'payment' => 'Оплата',
    ],

    'quote_status' => [
        'draft' => 'Черновик',
        'sent' => 'Отправлено',
        'accepted' => 'Принято',
        'rejected' => 'Отклонено',
        'expired' => 'Срок истёк',
    ],

    'page_template' => [
        'default' => 'Обычная',
        'contacts' => 'Контакты',
        'legal' => 'Юридический документ',
    ],

    'news_kind' => [
        'news' => 'Новость',
        'article' => 'Статья',
    ],

    'news_status' => [
        'draft' => 'Черновик',
        'published' => 'Опубликована',
    ],

    'news_state' => [
        'draft' => 'Черновик',
        'scheduled' => 'Запланирована',
        'published' => 'Опубликована',
    ],

    'banner_placement' => [
        'home_hero' => 'Слайдер на главной',
        'category' => 'Категория',
    ],

    'banner_state' => [
        'active' => 'Показывается',
        'scheduled' => 'Запланирован',
        'expired' => 'Завершён',
        'inactive' => 'Выключен',
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
        'ai_bots' => 'ИИ-боты',
        'faq' => 'Частые вопросы',
        'integrations' => 'Интеграции',
        'api_tokens' => 'API-токены',
        'price_sync' => 'Синхронизация цен',
        'checkout' => 'Оформление заказа',
        'indexing' => 'Индексация сайта',
        'mcp' => 'Доступ MCP',
    ],

    'audit_action' => [
        'updated' => 'Изменено',
        'cleared' => 'Очищено',
        'uploaded' => 'Загружено',
        'removed' => 'Удалено',
        'generated' => 'Создано',
        'created' => 'Создано',
        'revoked' => 'Отозвано',
        'unlinked' => 'Отвязано',
        'opened' => 'Открыто',
        'closed' => 'Закрыто',
        'granted' => 'Выдано',
        'transferred' => 'Передано',
    ],

    'price_sync_status' => [
        'success' => 'Применён',
        'partial' => 'Применён частично',
        'rejected' => 'Отклонён',
    ],

    'uzqr_transaction_status' => [
        'creating' => 'Создаётся',
        'pending' => 'Ожидает оплаты',
        'paid' => 'Оплачен',
        'failed' => 'Закрыт',
        'expired' => 'Истёк',
        'create_failed' => 'Не создан',
        'released' => 'Удержание снято',
    ],

    'uzqr_settlement' => [
        'settled' => 'Зачтён',
        'unmatched' => 'Не зачтён',
    ],

    'payment_mode' => [
        'test' => 'Тест',
        'prod' => 'Боевой',
    ],

    'integration_source' => [
        'env' => 'Файл .env',
        'db' => 'Админка',
        'default' => 'По умолчанию',
        'none' => 'Не задано',
    ],

    'order_status' => [
        'new' => 'Новый',
        'confirmed' => 'Подтверждён',
        'processing' => 'Комплектуется',
        'shipped' => 'Отправлен',
        'completed' => 'Выполнен',
        'cancelled' => 'Отменён',
    ],

    'payment_status' => [
        'unpaid' => 'Не оплачен',
        'pending' => 'Оплата в процессе',
        'paid' => 'Оплачен',
        'refunded' => 'Возвращён',
    ],

    'payment_method' => [
        'payme' => 'Payme',
        'click' => 'Click',
        'uzqr_static' => 'UzQR (статический код)',
        'on_receipt' => 'При получении',
        'bank_transfer' => 'Банковский перевод',
        'uzqr_dynamic' => 'Оплата через UzQR',
    ],

    'receipt_channel' => [
        'cash' => 'Наличные',
        'card' => 'Карта',
        'uzqr' => 'UzQR',
    ],

    'order_source' => [
        'site' => 'Сайт',
        'quote' => 'Коммерческое предложение',
        'manual' => 'Вручную',
    ],

    'buyer_type' => [
        'individual' => 'Физическое лицо',
        'company' => 'Компания',
        'sole_proprietor' => 'ИП (ЯТТ)',
    ],

    'delivery_type' => [
        'pickup' => 'Самовывоз',
        'courier_kokand' => 'Курьер по Коканду',
        'courier_region' => 'Курьер в регион',
    ],

    'delivery_pricing' => [
        'fixed' => 'В сумме заказа',
        'separate' => 'Оплачивается отдельно',
    ],

    'region' => [
        'tashkent_city' => 'г. Ташкент',
        'tashkent' => 'Ташкентская область',
        'andijan' => 'Андижанская область',
        'bukhara' => 'Бухарская область',
        'fergana' => 'Ферганская область',
        'jizzakh' => 'Джизакская область',
        'kashkadarya' => 'Кашкадарьинская область',
        'khorezm' => 'Хорезмская область',
        'namangan' => 'Наманганская область',
        'navoi' => 'Навоийская область',
        'samarkand' => 'Самаркандская область',
        'surkhandarya' => 'Сурхандарьинская область',
        'syrdarya' => 'Сырдарьинская область',
        'karakalpakstan' => 'Республика Каракалпакстан',
    ],

    'order_payment_purpose' => [
        'goods' => 'Товары',
        'delivery' => 'Доставка',
    ],

    'order_payment_status' => [
        'pending' => 'Ожидает оплаты',
        'paid' => 'Оплачен',
        'refunded' => 'Возвращён',
        'cancelled' => 'Отменён',
    ],

    'api_token_type' => [
        'price_api' => 'API цен',
        'mcp' => 'MCP',
    ],

    'mcp_call_status' => [
        'ok' => 'Успешно',
        'invalid' => 'Ошибка данных',
        'denied' => 'Отказано',
        'rate_limited' => 'Превышен лимит',
        'error' => 'Ошибка',
    ],

    'mcp_revoke_reason' => [
        'admin' => 'Отозван администратором',
        'revoke_all' => 'Отозван весь доступ MCP',
        'regenerated' => 'Перевыпущен',
        'transferred' => 'Доступ передан',
        'deactivated' => 'Пользователь отключён',
        'password_changed' => 'Пароль изменён',
        'role_lost' => 'Снята роль Admin',
        'idle' => 'Долго не использовался',
        'never_used' => 'Не использован за 7 дней',
    ],

    'mcp_deny_reason' => [
        'revoked_token' => 'Использован отозванный токен',
        'ip_not_allowed' => 'IP-адрес не разрешён',
        'token' => 'Нет действующего токена MCP',
        'token_user' => 'Токен другого пользователя',
        'inactive' => 'Пользователь отключён',
        'not_admin' => 'Не Admin',
        'no_access' => 'Нет доступа MCP',
        'not_single_holder' => 'Больше одного владельца',
    ],

    'mcp_auth_type' => [
        'token' => 'Токен',
    ],

];
