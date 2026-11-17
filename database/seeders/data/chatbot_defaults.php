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
            'uz' => 'Oʻzbekistonning barcha viloyatlariga kuryer yoki transport kompaniyasi orqali odatda 1-3 ish kunida yetkazamiz; aniq muddatni menejer buyurtmangiz uchun tasdiqlaydi. Viloyatlarga yetkazib berish narxi buyurtma bilan birga hisoblanadi va mijoz tomonidan toʻlanadi. Qoʻqon boʻylab yetkazib berish bepul. Qoʻqondagi ofisdan olib ketish ham mumkin.',
            'ru' => 'Во все регионы Узбекистана доставляем курьером или транспортной компанией, обычно за 1-3 рабочих дня; точный срок менеджер подтвердит для вашего заказа. Стоимость доставки в регионы рассчитывается вместе с заказом и оплачивается заказчиком. Доставка по Коканду бесплатная. Можно забрать заказ в офисе в Коканде.',
            'en' => 'We deliver to all regions of Uzbekistan by courier or transport company, usually in 1-3 working days; a manager confirms the exact timeframe for your order. The cost for other regions is quoted with the order and paid by the customer. Delivery within Kokand is free. Pickup from our Kokand office is also possible.',
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
            'uz' => 'Onlayn toʻlov — UzQR orqali. Shuningdek, naqd pul; kompaniyalar uchun hisob-varaq asosida bank oʻtkazmasi; Qoʻqondagi ofisda bank kartasi terminal orqali. Narxlar 12% QQS bilan koʻrsatiladi.',
            'ru' => 'Онлайн-оплата через UzQR. Также наличные; для компаний банковский перевод по счёту; в офисе в Коканде карта через терминал. Цены указаны с учётом НДС 12%.',
            'en' => 'Online payment is by UzQR. Cash is also accepted, companies can pay by bank transfer against an invoice, and cards are accepted at the terminal in our Kokand office. Prices include 12% VAT.',
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
            'uz' => 'Kafolat ishlab chiqaruvchi shartlari boʻyicha, distribyutorning vakolatli servisi orqali bajariladi; Poson qurilmani Qoʻqondagi servis markazida qabul qiladi, diagnostika qiladi va murojaatni muvofiqlashtiradi. Kafolatdan keyingi taʼmir — oʻz servis markazimizda: avval diagnostika va yozma smeta.',
            'ru' => 'Гарантия действует на условиях производителя и обслуживается через авторизованный сервис дистрибьютора; Poson принимает устройство в сервисном центре в Коканде, проводит диагностику и координирует обращение. Послегарантийный ремонт выполняем в собственном сервисном центре: сначала диагностика и письменная смета.',
            'en' => 'Warranty follows the manufacturer\'s terms and is handled through the distributor\'s authorised service; Poson accepts the device at its Kokand service centre, runs diagnostics and coordinates the claim. Out-of-warranty repair is done in our own service centre: diagnostics and a written estimate first.',
        ],
        'keywords' => [
            'kafolat*', 'garantiy*', 'гаранти*', 'warranty', 'guarantee', 'servis*', 'сервис*', 'service', 'remont*', 'ремонт*', 'tamir*', 'repair',
            'sozlash*', 'sozlab*', 'настройк*', 'настроить', 'ornatish*', 'установк*', 'setup', 'integratsiya*', 'интеграц*',
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
        'key' => 'repair_status',
        'name' => 'Repair status',
        'action' => 'repair_status',
        'params' => [],
        'priority' => 0,
        'menu' => 12,
        'label' => ['uz' => 'Taʼmir holati', 'ru' => 'Статус ремонта', 'en' => 'Repair status'],
        'answer' => [
            'uz' => 'Taʼmir holatini menejer aytib beradi. Iltimos, taʼmir raqamingiz bilan menejerga murojaat qiling.',
            'ru' => 'Статус ремонта сообщает менеджер. Пожалуйста, обратитесь к менеджеру, указав номер ремонта.',
            'en' => 'A manager will tell you the status of your repair. Please contact a manager and give your repair number.',
        ],
        'keywords' => [
            ['taʼmir holati', 20], ['статус ремонта', 20], ['repair status', 20], ['мой ремонт', 20], ['where is my repair', 20],
            ['taʼmirim holati', 20], ['remont holati', 20],
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
            'uz' => 'Biznes turi, vazifa (kassa, ombor, yorliq), taxminiy hajm va 1C bilan ishlashi kerakligini yozing — mutaxassis tanlashda yordam beradi.',
            'ru' => 'Напишите тип бизнеса, задачу (касса, склад, этикетки), примерный объём и нужна ли работа с 1С: специалист поможет с выбором.',
            'en' => 'Tell us your business type, the task (checkout, warehouse, labels), expected volume and whether it must work with 1C, and a specialist will help you choose.',
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

    [
        'key' => 'vat',
        'name' => 'VAT and closing documents',
        'action' => 'answer',
        'params' => [],
        'priority' => 5,
        'menu' => null,
        'label' => [],
        'answer' => [
            'uz' => 'Narxlar 12% QQS bilan koʻrsatiladi. Hisob-faktura va yopuvchi hujjatlar kompaniyangiz rekvizitlari boʻyicha rasmiylashtiriladi.',
            'ru' => 'Цены указаны с учётом НДС 12%. Счёт и закрывающие документы оформляются на реквизиты вашей компании.',
            'en' => 'Prices include 12% VAT. Invoices and closing documents are issued to your company details.',
        ],
        'keywords' => [
            'qqs*', 'ндс*', 'vat', 'yopuvchi hujjat*', 'закрывающ*', 'closing documents',
        ],
    ],

    [
        'key' => 'integration_1c',
        'name' => '1C integration',
        'action' => 'answer',
        'params' => [],
        'priority' => 5,
        'menu' => null,
        'label' => [],
        'answer' => [
            'uz' => 'Ha, skanerlar, printerlar, maʼlumot yigʻish terminallari, RFID va dasturlarni 1C ga ulashda yordam beramiz. Ish hajmi tijorat taklifida koʻrsatiladi. Sozlash alohida ixtiyoriy xizmat; buyurtma qilsangiz, xodimlarni oʻqitish ham kiradi.',
            'ru' => 'Да, поможем подключить сканеры, принтеры, мобильные компьютеры, RFID и программы к 1С. Объём работ указывается в коммерческом предложении. Настройка: отдельная необязательная услуга, при её заказе обучение сотрудников входит.',
            'en' => 'Yes, we help connect scanners, printers, mobile computers, RFID and software to 1C. The scope is set out in the quote. Setup is an optional service quoted separately; when you order it, staff training is included.',
        ],
        'keywords' => [
            '1c', '1с',
        ],
    ],

    [
        'key' => 'availability',
        'name' => 'Availability',
        'action' => 'handoff',
        'params' => [],
        'priority' => 5,
        'menu' => null,
        'label' => [],
        'answer' => [
            'uz' => 'Joriy mavjudlik va yetkazib berish muddatini menejer aniqlab beradi. Model nomini shu yerga yozing, menejer imkon qadar tezroq javob beradi.',
            'ru' => 'Актуальное наличие и срок поставки уточнит менеджер. Напишите здесь модель, менеджер ответит как можно скорее.',
            'en' => 'A manager will confirm current availability and the delivery timeframe. Write the model here and a manager will reply as soon as possible.',
        ],
        'keywords' => [
            'omborda bor*', 'mavjudmi', 'в наличии', 'наличие', 'in stock', 'availability',
        ],
    ],

    [
        'key' => 'dealer',
        'name' => 'Dealers and partners',
        'action' => 'handoff',
        'params' => [],
        'priority' => 5,
        'menu' => null,
        'label' => [],
        'answer' => [
            'uz' => 'Dilerlik shartlari roʻyxatdan oʻtgan hamkorlarga menejer bilan suhbatdan keyin taqdim etiladi. Qayta sotuvchilar, tizim integratorlari va chakana tarmoqlar bilan hamkorlikka ochiqmiz. Kompaniyangiz haqida shu yerga yozing, menejer imkon qadar tezroq javob beradi.',
            'ru' => 'Условия для дилеров предоставляются зарегистрированным партнёрам после беседы с менеджером. Мы открыты к сотрудничеству с реселлерами, системными интеграторами и торговыми сетями. Расскажите здесь о вашей компании, менеджер ответит как можно скорее.',
            'en' => 'Dealer terms are provided to registered partners after a conversation with a manager. We are open to resellers, system integrators and retail chains. Tell us about your company here and a manager will reply as soon as possible.',
        ],
        'keywords' => [
            'diler*', 'dealer*', 'дилер*', 'reseller', 'qayta sotuvchi', 'перепродаж*', 'integrator*', 'интегратор*', 'ulgurji', 'оптов*', 'оптом', 'wholesale',
        ],
    ],

    [
        'key' => 'complaint',
        'name' => 'Complaint or return',
        'action' => 'handoff',
        'params' => [],
        'priority' => 10,
        'menu' => null,
        'label' => [],
        'answer' => [
            'uz' => 'Noqulaylik uchun uzr soʻraymiz. Vaziyatni shu yerga yozing: menejer murojaatingizni koʻrib chiqadi va javob beradi. Karta raqami, SMS-kod yoki parollarni chatga yubormang.',
            'ru' => 'Приносим извинения за неудобства. Опишите ситуацию здесь: менеджер рассмотрит обращение и ответит. Не отправляйте в чат номер карты, SMS-коды и пароли.',
            'en' => 'We are sorry for the inconvenience. Describe the situation here: a manager will review your request and reply. Do not send card numbers, SMS codes or passwords in the chat.',
        ],
        'keywords' => [
            'shikoyat*', 'pretenziya*', 'qaytarish*', 'жалоб*', 'претензи*', 'возврат*', 'complaint*', 'refund*', 'return*',
        ],
    ],

    [
        'key' => 'label_ribbon_fit',
        'name' => 'Labels and ribbon fit',
        'action' => 'answer',
        'params' => [],
        'priority' => 5,
        'menu' => null,
        'label' => [],
        'answer' => [
            'uz' => 'Yorliq va ribbon tanlash uchun printer modeli, yorliq eni va boʻyi, gilza (core) diametri, ribbon turi (wax, wax-resin, resin) va IN/OUT oʻrami kerak. Shu maʼlumotlar bilan menyudagi «Menejer bilan bogʻlanish» orqali murojaat qiling, mutaxassis mosligini tekshiradi.',
            'ru' => 'Для подбора этикеток и риббона нужны модель принтера, ширина и высота этикетки, диаметр втулки (core), тип риббона (wax, wax-resin, resin) и намотка IN/OUT. С этими данными нажмите «Связаться с менеджером» в меню, специалист проверит совместимость.',
            'en' => 'To match labels and ribbons we need the printer model, label width and height, core diameter, ribbon type (wax, wax-resin, resin) and ink side (IN/OUT). With these details, choose “Talk to a manager” in the menu and a specialist will check the fit.',
        ],
        'keywords' => [
            'mos keladi', 'moslik', 'совместим*', 'compatible', 'compatibility', 'fit my printer',
        ],
    ],

    [
        'key' => 'repair_intake',
        'name' => 'Repair request',
        'action' => 'handoff',
        'params' => [],
        'priority' => 5,
        'menu' => null,
        'label' => [],
        'answer' => [
            'uz' => 'Nosozlikni qisqacha tasvirlab, qurilma modeli va seriya raqamini shu yerga yozing. Menejer imkon qadar tezroq javob beradi; diagnostikani Qoʻqondagi servis markazida oʻtkazamiz.',
            'ru' => 'Кратко опишите здесь неисправность, модель и серийный номер устройства. Менеджер ответит как можно скорее; диагностику проводим в сервисном центре в Коканде.',
            'en' => 'Briefly describe the fault here with the device model and serial number. A manager will reply as soon as possible; we run diagnostics at our Kokand service centre.',
        ],
        'keywords' => [
            'buzildi', 'ishlamayapti', 'не работает', 'сломал*', 'not working',
        ],
    ],

    [
        'key' => 'rfid_info',
        'name' => 'RFID basics',
        'action' => 'answer',
        'params' => [],
        'priority' => 5,
        'menu' => null,
        'label' => [],
        'answer' => [
            'uz' => 'UHF RFID masofadan koʻp metkani bir vaqtda oʻqiydi: inventarizatsiya, logistika, kiyim savdosi. HF qisqa masofada ishlaydi: kirish kartalari va NFC. Vazifangizni menyudagi «Menejer bilan bogʻlanish» orqali yozing, mutaxassis chastotani tanlashda yordam beradi.',
            'ru' => 'UHF RFID читает много меток одновременно на расстоянии: инвентаризация, логистика, одежда. HF работает на коротком расстоянии: карты доступа и NFC. Опишите задачу через «Связаться с менеджером» в меню, специалист поможет выбрать частоту.',
            'en' => 'UHF RFID reads many tags at once over a distance: stocktaking, logistics, apparel. HF works at short range: access cards and NFC. Describe your task via “Talk to a manager” in the menu and a specialist will help you choose the frequency.',
        ],
        'keywords' => [
            'uhf', 'hf', 'nfc', ['rfid nima', 20], ['что такое rfid', 20], ['what is rfid', 20], ['rfid chastota*', 20], ['rfid частот*', 20], ['частота rfid', 20], ['частоты rfid', 20],
        ],
    ],

];
