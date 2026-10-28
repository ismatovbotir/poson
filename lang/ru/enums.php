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
    ],

    'ai_feature' => [
        'text_generate' => 'Написание текста',
        'text_translate' => 'Перевод',
    ],

    'ai_provider' => [
        'anthropic' => 'Anthropic (Claude)',
        'openrouter' => 'OpenRouter',
        'openai' => 'OpenAI',
        'removebg' => 'remove.bg',
    ],

    'ai_request_status' => [
        'pending' => 'В очереди',
        'running' => 'Выполняется',
        'succeeded' => 'Готово',
        'failed' => 'Ошибка',
        'rejected' => 'Отклонён',
    ],

    'ai_error_code' => [
        'timeout' => 'Истекло время',
        'rate_limited' => 'Слишком много запросов',
        'provider_error' => 'Ошибка сервиса',
        'invalid_output' => 'Некорректный ответ',
        'not_configured' => 'Ключ не настроен',
        'disabled' => 'Выключено',
        'quota_exceeded' => 'Дневной лимит',
        'budget_exceeded' => 'Бюджет на месяц',
        'no_credits' => 'Нет кредитов',
        'model_unavailable' => 'Модель недоступна',
        'invalid_key' => 'Ключ отклонён',
        'refused' => 'Отказ провайдера',
        'bad_request' => 'Некорректный запрос',
        'field_retired' => 'Поле отключено',
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
        'ai_prompts' => 'Промпты ИИ',
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
    ],

    'price_sync_status' => [
        'success' => 'Применён',
        'partial' => 'Применён частично',
        'rejected' => 'Отклонён',
    ],

    'payme_mode' => [
        'test' => 'Тест',
        'prod' => 'Боевой',
    ],

    'integration_source' => [
        'env' => 'Файл .env',
        'db' => 'Админка',
        'default' => 'По умолчанию',
        'none' => 'Не задано',
    ],

];
