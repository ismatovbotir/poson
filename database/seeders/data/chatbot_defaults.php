<?php

/*
|--------------------------------------------------------------------------
| Chat bot: built-in (system) rules, plan 28 C1
|--------------------------------------------------------------------------
|
| Read by App\Actions\Chat\InstallChatbotDefaults (command
| chatbot:install-defaults and LaunchSeeder). Final copy (phase C1c): the
| answers, menu labels and synonyms can be edited in this file; rules
| that already exist are never overwritten, so after launch the owner edits
| them in Admin → Chat bot.
|
| Per rule:
|   key, name, action, params (see SaveChatRule), priority, menu (sort, or
|   null = not in the menu), label {uz,ru,en} (menu button text),
|   answer {uz,ru,en} (empty for faq / company_info / menu rules that
|   build their text), keywords.
|
| Keywords are any-language (matched after Cyrillic and apostrophe
| normalization, so "oʻrnatish", "o'rnatish" and "ўрнатиш" are one word):
|   'word'      a whole word, 'two words' a phrase, 'stem*' a word start,
|   ['text', 20] the same with a weight (default 10; min score is 10).
| No low-price or discount wording in any answer or label (brand rule).
|
*/

return [

    [
        'key' => 'greeting',
        'name' => 'Greeting and menu',
        'action' => 'menu',
        'params' => [],
        'priority' => 0,
        'menu' => null,
        'label' => [],
        'answer' => [
            'uz' => 'Assalomu alaykum! Men Poson yordamchisiman. Quyidagi mavzulardan birini tanlang yoki savolingizni yozing.',
            'ru' => 'Здравствуйте! Я помощник Poson. Выберите тему ниже или напишите свой вопрос.',
            'en' => 'Hello! I am the Poson assistant. Choose a topic below or type your question.',
        ],
        'keywords' => [
            'salom*', 'assalomu alaykum', 'assalom*', 'hayrli*', 'xayrli*', 'privet', 'привет*', 'здравствуй*', 'добрый день', 'добрый вечер', 'доброе утро',
            'hello', 'hi', 'hey', 'good morning', 'good afternoon', 'menu', 'menyu', 'меню', 'start', 'boshlash', 'начать',
        ],
    ],

    [
        'key' => 'faq',
        'name' => 'Frequently asked questions',
        'action' => 'faq',
        'params' => [],
        'priority' => 0,
        'menu' => 1,
        'label' => ['uz' => 'Savol-javob', 'ru' => 'Частые вопросы', 'en' => 'FAQ'],
        'answer' => [],
        'keywords' => [
            'faq', 'savol javob', 'savol*', 'tez-tez beriladigan', 'вопрос*', 'частые вопросы', 'частые', 'questions', 'question', 'help', 'yordam', 'помощь',
        ],
    ],

    [
        'key' => 'delivery',
        'name' => 'Delivery',
        'action' => 'answer',
        'params' => [],
        'priority' => 0,
        'menu' => 2,
        'label' => ['uz' => 'Yetkazib berish', 'ru' => 'Доставка', 'en' => 'Delivery'],
        'answer' => [
            'uz' => 'Bosh ofisimiz Qoʻqonda. Qoʻqon ichida va butun Oʻzbekiston boʻylab kuryer orqali yetkazib beramiz; ofisdan olib ketish ham mumkin. Shartlarni menejer buyurtmangiz uchun tasdiqlaydi.',
            'ru' => 'Главный офис находится в Коканде. Доставляем по Коканду и курьером по всему Узбекистану; можно также забрать заказ в офисе. Условия менеджер подтвердит для вашего заказа.',
            'en' => 'Our main office is in Kokand. We deliver within Kokand and by courier across Uzbekistan; pickup from the office is also possible. A manager confirms the terms for your order.',
        ],
        'keywords' => [
            'yetkaz*', 'etkazib*', 'dostav*', 'доставк*', 'достав*', 'delivery', 'deliver*', 'shipping', 'kuryer*', 'курьер*',
            'olib ketish', 'olib ketaman', 'самовывоз*', 'забрать', 'pickup',
        ],
    ],

    [
        'key' => 'payment',
        'name' => 'Payment',
        'action' => 'answer',
        'params' => [],
        'priority' => 0,
        'menu' => 3,
        'label' => ['uz' => 'Toʻlov', 'ru' => 'Оплата', 'en' => 'Payment'],
        'answer' => [
            'uz' => 'Toʻlov usullari: hisob-faktura asosida bank orqali oʻtkazma, naqd pul, karta terminali va UzQR. Tashkilotlar uchun hisob-faktura beramiz.',
            'ru' => 'Способы оплаты: банковский перевод по счёту, наличные, карточный терминал и UzQR. Для организаций выставляем счёт.',
            'en' => 'Payment methods: bank transfer on an invoice, cash, card terminal and UzQR. We issue invoices for companies.',
        ],
        'keywords' => [
            'tolov*', 'oplat*', 'оплат*', 'payment', 'pay', 'uzqr', 'naqd*', 'pul', 'наличн*', 'перевод*', 'hisob raqam',
            'hisob faktura', 'faktura*', 'счет*', 'счёт*', 'rekvizit*', 'реквизит*', 'invoice', 'karta orqali', 'картой', 'bank orqali',
        ],
    ],

    [
        'key' => 'warranty_service',
        'name' => 'Warranty and service',
        'action' => 'answer',
        'params' => [],
        'priority' => 0,
        'menu' => 4,
        'label' => ['uz' => 'Kafolat va servis', 'ru' => 'Гарантия и сервис', 'en' => 'Warranty and service'],
        'answer' => [
            'uz' => 'Uskunalar ishlab chiqaruvchining rasmiy kafolati bilan beriladi; muddati brend va modelga bogʻliq va taklif hamda kafolat kartasida koʻrsatiladi. Oʻz servis markazimizda sozlash, 1C bilan integratsiya, taʼmirlash va qoʻllab-quvvatlash xizmatlari bor, masofadan ham, joyiga chiqib ham. Taʼmirdan oldin tashxis qilamiz va yozma smeta (narx va muddat) beramiz.',
            'ru' => 'Оборудование поставляется с официальной гарантией производителя; срок зависит от бренда и модели и указан в коммерческом предложении и гарантийном талоне. В собственном сервисном центре выполняем настройку, интеграцию с 1С, ремонт и поддержку, удалённо и с выездом. Перед ремонтом проводим диагностику и даём письменную смету со стоимостью и сроками.',
            'en' => 'Equipment comes with the manufacturer\'s official warranty; its length depends on the brand and model and is stated in the quote and the warranty card. Our own service centre handles setup, 1C integration, repair and support, remotely or on site. Before any repair we run diagnostics and give a written estimate with cost and timeline.',
        ],
        'keywords' => [
            'kafolat*', 'garantiy*', 'гаранти*', 'warranty', 'guarantee', 'servis*', 'сервис*', 'service', 'remont*', 'ремонт*', 'tamir*', 'repair',
            'sozlash*', 'sozlab*', 'настройк*', 'настроить', 'ornatish*', 'установк*', 'setup', 'integratsiya*', 'интеграц*', 'buzildi', 'сломал*', 'ishlamayapti', 'не работает',
        ],
    ],

    [
        'key' => 'address_hours',
        'name' => 'Address and working hours',
        'action' => 'company_info',
        'params' => ['topic' => 'address_hours'],
        'priority' => 0,
        'menu' => 5,
        'label' => ['uz' => 'Manzil va ish vaqti', 'ru' => 'Адрес и часы работы', 'en' => 'Address and hours'],
        'answer' => [],
        'keywords' => [
            'manzil*', 'adres*', 'адрес*', 'address', 'qayerda', 'qayerdasiz', 'где находитесь', 'где вы находитесь', 'ish vaqti', 'ish tartibi', 'ish kunlari',
            'график*', 'часы работы', 'режим работы', 'во сколько', 'hours', 'working hours', 'opening hours', 'location', 'ofis*', 'офис*', 'koqon', 'коканд', 'kokand',
        ],
    ],

    [
        'key' => 'contacts',
        'name' => 'Contacts',
        'action' => 'company_info',
        'params' => ['topic' => 'contacts'],
        'priority' => 0,
        'menu' => 6,
        'label' => ['uz' => 'Kontaktlar', 'ru' => 'Контакты', 'en' => 'Contacts'],
        'answer' => [],
        'keywords' => [
            'telefon*', 'telefon raqam*', 'номер телефона', 'телефон*', 'kontakt*', 'контакт*', 'phone', 'phone number', 'contact', 'contacts',
            'email', 'e-mail', 'pochta', 'почта', 'aloqa', 'aloqa uchun', 'nomeringiz', 'ваш номер',
        ],
    ],

    [
        'key' => 'manager',
        'name' => 'Talk to a manager',
        'action' => 'handoff',
        'params' => [],
        'priority' => 10,
        'menu' => 7,
        'label' => ['uz' => 'Menejer bilan bogʻlanish', 'ru' => 'Связаться с менеджером', 'en' => 'Talk to a manager'],
        'answer' => [
            'uz' => 'Savolingizni shu yerga yozing. Menejer imkon qadar tezroq javob beradi.',
            'ru' => 'Напишите свой вопрос здесь. Менеджер ответит как можно скорее.',
            'en' => 'Please write your question here. A manager will reply as soon as possible.',
        ],
        'keywords' => [
            'menejer*', 'menedjer*', 'operator*', 'менеджер*', 'оператор*', 'человек*', 'живой', 'сотрудник*', 'специалист*', 'odam', 'jonli odam',
            'xodim*', 'mutaxassis*', 'gaplash*', 'manager', 'human', 'agent', 'real person', 'boglan*', 'связ*', 'поговорить',
        ],
    ],

    [
        'key' => 'order_status',
        'name' => 'Order status',
        'action' => 'order_status',
        'params' => [],
        'priority' => 0,
        'menu' => 8,
        'label' => ['uz' => 'Buyurtma holati', 'ru' => 'Статус заказа', 'en' => 'Order status'],
        'answer' => [
            'uz' => 'Buyurtma holatini menejer aytib beradi. Iltimos, buyurtma raqamingiz bilan menejerga murojaat qiling.',
            'ru' => 'Статус заказа сообщает менеджер. Пожалуйста, обратитесь к менеджеру, указав номер заказа.',
            'en' => 'A manager will tell you the status of your order. Please contact a manager and give your order number.',
        ],
        'keywords' => [
            ['buyurtma holati', 20], ['buyurtmam holati', 20], ['статус заказа', 20], ['order status', 20], ['мой заказ', 20], ['where is my order', 20],
            ['buyurtmam qayerda', 20], 'buyurtma*', 'zakaz*', 'заказ*', 'order', 'tracking',
        ],
    ],

    [
        'key' => 'product_search',
        'name' => 'Choose equipment',
        'action' => 'product_search',
        'params' => [],
        'priority' => 0,
        'menu' => 9,
        'label' => ['uz' => 'Uskuna tanlash', 'ru' => 'Подбор оборудования', 'en' => 'Choose equipment'],
        'answer' => [
            'uz' => 'Uskuna tanlashda yordam beramiz: skanerlar, printerlar, TSD, RFID va kassa uskunalari. Vazifangizni yoki modelni yozing, menejer mos yechimni tavsiya qiladi. Katalog saytimizda mavjud.',
            'ru' => 'Поможем подобрать оборудование: сканеры, принтеры, ТСД, RFID и кассовое оборудование. Напишите задачу или модель, менеджер предложит подходящее решение. Каталог доступен на сайте.',
            'en' => 'We will help you choose equipment: scanners, printers, mobile computers, RFID and POS hardware. Write the task or the model and a manager will suggest a fitting solution. The catalog is on our website.',
        ],
        'keywords' => [
            'mahsulot*', 'tovar*', 'товар*', 'product*', 'qidir*', 'поиск*', 'подобр*', 'tanlash*', 'uskuna*', 'оборудован*', 'equipment', 'katalog*', 'каталог*', 'catalog',
            'skaner*', 'сканер*', 'scanner*', 'printer*', 'принтер*', 'terminal*', 'терминал*', 'tsd', 'тсд', 'rfid', 'ribbon*', 'риббон*', 'shtrix*', 'штрих*', 'barcode',
            'etiketka*', 'этикетк*', 'label*', 'kassa*', 'касс*', 'pos',
        ],
    ],

    [
        'key' => 'quote',
        'name' => 'Request a quote',
        'action' => 'lead_dialog',
        'params' => ['flow' => 'quote'],
        'priority' => 0,
        'menu' => 10,
        'label' => ['uz' => 'Tijoriy taklif', 'ru' => 'Коммерческое предложение', 'en' => 'Request a quote'],
        'answer' => [
            'uz' => 'Tijoriy taklif uchun menejerga yozing: kerakli uskuna, miqdor va kompaniya nomini koʻrsating. Taklifni tayyorlab, tanlash va sozlash masalalarida ham yordam beramiz.',
            'ru' => 'Для коммерческого предложения напишите менеджеру: укажите нужное оборудование, количество и название компании. Подготовим предложение и поможем с подбором и настройкой.',
            'en' => 'For a quote, write to a manager: name the equipment you need, the quantity and your company. We will prepare the proposal and help with selection and setup.',
        ],
        'keywords' => [
            'taklif*', 'tijorat*', 'tijoriy', 'коммерческ*', 'кп', 'narx*', 'цен*', 'прайс*', 'стоимост*', 'smeta', 'смета', 'price', 'pricing', 'quote', 'quotation', 'offer', 'proposal',
        ],
    ],

    [
        'key' => 'callback',
        'name' => 'Request a callback',
        'action' => 'lead_dialog',
        'params' => ['flow' => 'callback'],
        'priority' => 0,
        'menu' => 11,
        'label' => ['uz' => 'Qayta qoʻngʻiroq', 'ru' => 'Обратный звонок', 'en' => 'Request a callback'],
        'answer' => [
            'uz' => 'Qayta qoʻngʻiroq uchun menejerga yozing: telefon raqamingiz va qulay vaqtni koʻrsating.',
            'ru' => 'Для обратного звонка напишите менеджеру: укажите ваш номер телефона и удобное время.',
            'en' => 'For a callback, write to a manager: give your phone number and a convenient time.',
        ],
        'keywords' => [
            'qayta qongiroq', 'qongiroq*', 'perezvon*', 'перезвон*', 'обратный звонок', 'позвон*', 'звон*', 'zvonok', 'callback', 'call back', 'call me', 'ring me',
        ],
    ],

];
