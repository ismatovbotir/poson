<?php

return [

    'filter_match' => [
        'all' => 'Все',
        'any' => 'Любой',
    ],

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
        'tg_business' => 'Telegram Business',
    ],

    'preferred_contact' => [
        'call' => 'Звонок',
        'telegram' => 'Telegram',
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
        'repair' => 'Ремонт',
        'cv_download' => 'Скачивание резюме',
        'ai_create' => 'Создан с ИИ',
        'ai_improve' => 'Улучшен с ИИ',
        'ai_summary' => 'Анализ виртуального офиса',
        'office_mark' => 'Чат отмечен',
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

    'ai_field_kind' => [
        'plain' => 'Обычный текст',
        'html' => 'HTML',
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

    'video_fetch_status' => [
        'pending' => 'Загрузка',
        'ok' => 'Готово',
        'unavailable' => 'Недоступно на YouTube',
        'failed' => 'Не удалось загрузить',
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
        'monitoring' => 'Мониторинг',
        'analytics' => 'Аналитика (реклама)',
        'careers' => 'Вакансии',
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
        'catalog' => 'Каталог',
        'telegram_bot' => 'Telegram-бот',
        'chatbot' => 'Чат-бот',
        'telegram_inbox' => 'Входящие Telegram',
        'users' => 'Сотрудники',
        'office' => 'Виртуальный офис',
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
        'published' => 'Опубликовано',
        'unpublished' => 'Возвращено в черновики',
        'webhook_set' => 'Вебхук установлен',
        'webhook_deleted' => 'Вебхук удалён',
        'enabled' => 'Включено',
        'disabled' => 'Выключено',
        'banned' => 'Заблокирован',
        'unbanned' => 'Блокировка снята',
        'verified' => 'Подтверждено',
        'retry_forward' => 'Повтор копирования файла',
        'linked' => 'Привязано',
        'deleted' => 'Удалено навсегда',
        'password_changed' => 'Пароль изменён',
        'password_reset' => 'Пароль сброшен',
        'exported' => 'Выгружено',
        'marked' => 'Отмечен',
        'sync_requested' => 'Запрошена синхронизация',
        'group_tracked' => 'Группа отслеживается',
        'group_ignored' => 'Группа игнорируется',
        'roles_set' => 'Роли участников заданы',
        'forgotten' => 'Участник забыт',
        'group_untracked' => 'Группа снята с отслеживания',
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
        'telegram' => 'Telegram Mini App',
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

    'task_run_status' => [
        'running' => 'Выполняется',
        'success' => 'Успешно',
        'failed' => 'Ошибка',
        'skipped' => 'Пропущено',
    ],

    'task_run_trigger' => [
        'schedule' => 'По расписанию',
        'manual' => 'Вручную',
    ],

    'monitoring_action' => [
        'run_task' => 'Запуск задачи',
        'retry_job' => 'Повтор упавшей задачи',
        'forget_job' => 'Удаление упавшей задачи',
        'trash_purge' => 'Автоочистка корзины',
    ],

    'telegram_user_state' => [
        'language' => 'Выбор языка',
        'contact' => 'Контакт',
        'name' => 'Имя',
        'company' => 'Компания',
        'inn' => 'ИНН',
        'registered' => 'Зарегистрирован',
    ],

    'telegram_direction' => [
        'in' => 'Входящее',
        'out' => 'Исходящее',
    ],

    'telegram_message_status' => [
        'received' => 'Получено',
        'pending' => 'В очереди',
        'sending' => 'Отправляется',
        'sent' => 'Отправлено',
        'failed' => 'Ошибка',
        'skipped' => 'Пропущено',
    ],

    'telegram_message_kind' => [
        'text' => 'Текст',
        'photo' => 'Фото',
        'document' => 'Документ',
        'voice' => 'Голосовое',
        'audio' => 'Аудио',
        'video' => 'Видео',
        'video_note' => 'Видеосообщение',
        'animation' => 'Анимация',
        'sticker' => 'Стикер',
        'location' => 'Геопозиция',
        'contact' => 'Контакт',
        'other' => 'Другое',
    ],

    'telegram_forward_status' => [
        'pending' => 'Ожидает',
        'sending' => 'Отправляется',
        'forwarded' => 'Переслано',
        'not_configured' => 'Не настроено',
        'limited' => 'Лимит',
        'failed' => 'Ошибка',
    ],

    'conversation_status' => [
        'open' => 'Открыт',
        'closed' => 'Закрыт',
    ],

    'chat_action' => [
        'answer' => 'Ответ',
        'faq' => 'Частые вопросы',
        'company_info' => 'Данные о компании',
        'product_search' => 'Поиск товара',
        'order_status' => 'Статус заказа',
        'repair_status' => 'Статус ремонта',
        'lead_dialog' => 'Диалог заявки',
        'handoff' => 'Связь с менеджером',
        'link' => 'Ссылка',
        'menu' => 'Меню',
    ],

    'chat_channel' => [
        'web' => 'Сайт',
        'telegram' => 'Telegram',
    ],

    'chat_author' => [
        'visitor' => 'Посетитель',
        'bot' => 'Бот',
        'staff' => 'Сотрудник',
        'system' => 'Система',
    ],

    'chat_mode' => [
        'bot' => 'Бот',
        'manager' => 'Менеджер',
    ],

    'keyword_match' => [
        'word' => 'Слово',
        'phrase' => 'Фраза',
        'prefix' => 'Начало слова',
        'exact' => 'Сообщение целиком',
    ],

    'lead_flow' => [
        'callback' => 'Обратный звонок',
        'quote' => 'Запрос коммерческого предложения',
        'service' => 'Заявка на сервис',
    ],

    'chat_dialog_step' => [
        'details' => 'Подробности',
        'confirm' => 'Подтверждение',
    ],

    'ai_usage_channel' => [
        'web' => 'Сайт',
        'telegram' => 'Telegram',
        'adm_vision' => 'Админ: чтение фото',
        'adm_write' => 'Админ: написание текста',
        'adm_trans' => 'Админ: перевод',
        'vo_route' => 'Виртуальный офис: маршрутизатор',
        'vo_sales' => 'Виртуальный офис: продажи',
        'vo_serv' => 'Виртуальный офис: сервис',
        'vo_support' => 'Виртуальный офис: поддержка',
    ],

    'chat_ai_role' => [
        'user' => 'Клиент',
        'assistant' => 'ИИ-помощник',
    ],

    'ai_knowledge_source' => [
        'page' => 'Страница',
        'news' => 'Новость',
        'article' => 'Статья',
        'brand' => 'Бренд',
        'service' => 'Услуга',
        'software' => 'Программа',
        'faq' => 'Частые вопросы',
    ],

    'chat_ai_status' => [
        'answered' => 'Отвечено',
        'truncated' => 'Отвечено (сокращено)',
        'failed' => 'Ошибка',
        'refused' => 'Отказ',
        'blocked' => 'Остановлено фильтром',
        'capped' => 'Лимит',
        'superseded' => 'Не отправлено',
        'disabled' => 'Не использован: ИИ выключен',
    ],

    'chat_metric' => [
        'hit' => 'Отвечено',
        'unmatched' => 'Не понято',
        'handoff' => 'Передано менеджеру',
        'lead' => 'Создано заявок',
        'lookup_ok' => 'Статус найден',
        'lookup_fail' => 'Статус не найден',
        'search_empty' => 'Пустой поиск',
        'ai' => 'Ответы ИИ',
    ],

    'repair_status' => [
        'received' => 'Принято',
        'diagnosing' => 'Диагностика',
        'awaiting_approval' => 'Ожидает согласования',
        'in_repair' => 'В ремонте',
        'awaiting_parts' => 'Ожидает запчасти',
        'ready' => 'Готово к выдаче',
        'delivered' => 'Выдано',
        'cancelled' => 'Отменено',
    ],

    'repair_coverage' => [
        'paid' => 'Платный',
        'warranty' => 'Гарантия',
    ],

    'repair_device_type' => [
        'scanner' => 'Сканер штрих-кода',
        'label_printer' => 'Принтер этикеток',
        'receipt_printer' => 'Чековый принтер',
        'mobile_computer' => 'Терминал сбора данных (ТСД)',
        'rfid' => 'RFID-оборудование',
        'pos' => 'POS-оборудование',
        'other' => 'Другое устройство',
    ],

    'vacancy_department' => [
        'service' => 'Сервис',
        'sales' => 'Продажи',
        'software' => 'Программное обеспечение',
        'operations' => 'Операции и склад',
        'management' => 'Управление',
        'marketing' => 'Маркетинг',
    ],

    'employment_type' => [
        'full_time' => 'Полная занятость',
        'part_time' => 'Частичная занятость',
        'contract' => 'По договору',
        'internship' => 'Стажировка',
    ],

    'job_application_status' => [
        'new' => 'Новый',
        'reviewing' => 'На рассмотрении',
        'interview' => 'Собеседование',
        'rejected' => 'Отказ',
        'hired' => 'Принят',
    ],

    'job_application_cv_status' => [
        'none' => 'Нет',
        'pending' => 'Отправляется',
        'sending' => 'Отправляется',
        'forwarded' => 'Отправлено',
        'failed' => 'Не отправлено',
        'expired' => 'Истёк срок',
    ],

    // Plan 32: AI product wizard drafts (the AI usage channels are above).
    'ai_draft_status' => [
        'open' => 'Открыт',
        'seeing' => 'Смотрит фото',
        'facts_ready' => 'Факты готовы',
        'writing' => 'Пишет',
        'ready' => 'Готово',
        'truncated' => 'Оборвано',
        'failed' => 'Ошибка',
        'refused' => 'Отклонено',
        'capped' => 'Дневной лимит',
        'applying' => 'Сохраняется',
        'applied' => 'Сохранено',
        'expired' => 'Истёк',
    ],

    'disk_target' => [
        'project_total' => 'Весь проект',
        'storage' => 'Storage',
        'public' => 'Public',
        'other' => 'Прочее (app, resources, config, database…)',
        'vendor' => 'Vendor',
        'node_modules' => 'Node modules',
        'git' => 'История Git',
        'storage_logs' => 'Логи',
        'storage_app_public' => 'Публичные файлы',
        'storage_app_private' => 'Приватные файлы',
        'storage_media_library' => 'Временные файлы медиатеки',
        'storage_framework_cache' => 'Кэш фреймворка',
        'storage_framework_views' => 'Скомпилированные шаблоны',
        'storage_framework_sessions' => 'Сессии',
    ],

    'disk_measure_status' => [
        'ok' => 'Измерено',
        'partial' => 'Неполно',
        'missing' => 'Не найдено',
        'unreadable' => 'Нет доступа',
    ],

    'disk_run_status' => [
        'idle' => 'Не запущено',
        'queued' => 'Ожидание обработчика очереди',
        'running' => 'Измерение',
        'done' => 'Готово',
        'failed' => 'Ошибка',
    ],

    'disk_run_error' => [
        'budget' => 'Остановлено по лимиту времени или файлов: размеры указаны не менее реальных.',
        'unreadable_root' => 'Папка проекта недоступна для чтения.',
        'worker_lost' => 'Измерение не удалось: обработчик очереди остановился.',
        'worker_missing' => 'Обработчик очереди не запущен: измерение не началось.',
        'exception' => 'Измерение не удалось. См. лог сервера.',
    ],
    'analytics_goal' => [
        'lead_callback' => 'Заявка: обратный звонок',
        'lead_product_quote' => 'Заявка: запрос по товару',
        'lead_product_service' => 'Заявка: запрос услуги (страница товара)',
        'lead_cart_quote' => 'Заявка: КП по корзине',
        'lead_service_request' => 'Заявка: сервисная заявка',
        'lead_chat' => 'Заявка: из чата на сайте',
        'order_placed' => 'Заказ оформлен',
        'click_phone' => 'Клик по телефону',
        'click_telegram' => 'Клик по ссылке Telegram',
        'chat_started' => 'Чат начат',
        'chat_handoff' => 'В чате позвали менеджера',
        'careers_application' => 'Отклик на вакансию',
        'add_to_cart' => 'Добавление в корзину',
        'order_paid' => 'Заказ оплачен (офлайн)',
        'quote_accepted' => 'КП принято (офлайн)',
    ],

    'business_chat_kind' => [
        'unmarked' => 'Не отмечен',
        'client' => 'Клиент',
        'employee' => 'Сотрудник',
        'ignored' => 'Игнорируется',
    ],

    'group_status' => [
        'untracked' => 'Не отслеживается',
        'tracked' => 'Отслеживается',
        'ignored' => 'Игнорируется',
    ],

    'group_member_role' => [
        'employee' => 'Сотрудник',
        'client' => 'Клиент',
        'bot' => 'Бот',
        'unknown' => 'Неизвестный',
    ],

    'business_author' => [
        'client' => 'Клиент',
        'owner' => 'Менеджер',
        'bot' => 'Бот',
    ],

    'agent_role' => [
        'router' => 'Маршрутизатор',
        'sales' => 'Продажи',
        'service' => 'Сервис',
        'support' => 'Поддержка',
        'other' => 'Другое',
    ],

    'analysis_status' => [
        'queued' => 'В очереди',
        'running' => 'Выполняется',
        'done' => 'Готово',
        'failed' => 'Ошибка',
        'skipped' => 'Пропущено',
        'capped' => 'Достигнут дневной лимит',
    ],

    'telegram_party' => [
        'client' => 'Клиент',
        'employee' => 'Сотрудник',
    ],

];
