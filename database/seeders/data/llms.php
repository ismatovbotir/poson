<?php

/**
 * Copy for /llms.txt (Plan 04, §8, T13).
 *
 * Short, factual, quotable statements about Poson for AI assistants and
 * search bots. No prices, no superlatives, no unverifiable numbers.
 * Brand rule: sell on service quality; no price-led wording (see CLAUDE.md).
 *
 * Uzbek is Latin script: oʻ/gʻ use ʻ (U+02BB), the tutuq belgisi uses ʼ (U+02BC).
 *
 * @return array{
 *     summary: array{en: string, ru: string, uz: string},
 *     about_points: array{en: list<string>, ru: list<string>, uz: list<string>},
 *     services_points: array{en: list<string>, ru: list<string>, uz: list<string>},
 *     contact_note: array{en: string, ru: string, uz: string}
 * }
 */
return [

    'summary' => [
        'en' => 'Poson is a company in Uzbekistan, with its main office in Kokand, that supplies and services Auto-ID equipment for business customers and delivers across Uzbekistan. Its catalogue covers barcode scanners, label and receipt printers, mobile computers (TSD), RFID readers and tags, POS equipment, consumables such as labels, ribbons and receipt paper, and POS and inventory software. Poson helps customers select equipment, installs and configures it, and connects it to 1C. Warranty and post-warranty repairs are carried out in the company\'s own service centre, and support is provided in Uzbek, Russian and English.',
        'ru' => 'Poson — компания в Узбекистане с главным офисом в Коканде, которая поставляет и обслуживает Auto-ID оборудование для бизнеса и доставляет его по всему Узбекистану. В каталоге — сканеры штрих-кода, принтеры этикеток и чековые принтеры, терминалы сбора данных (ТСД), RFID-считыватели и метки, POS-оборудование, расходные материалы (этикетки, риббоны, чековая лента) а также кассовые программы и программы товарного учёта. Poson помогает подобрать оборудование, устанавливает и настраивает его и подключает к 1С. Гарантийный и постгарантийный ремонт выполняется в собственном сервисном центре компании, поддержка ведётся на узбекском, русском и английском языках.',
        'uz' => 'Poson — Oʻzbekistondagi biznes mijozlar uchun Auto-ID uskunalarini yetkazib beruvchi va ularga servis xizmati koʻrsatuvchi kompaniya; bosh ofisi Qoʻqon shahrida joylashgan, yetkazib berish butun Oʻzbekiston boʻylab amalga oshiriladi. Katalogda shtrix-kod skanerlari, yorliq va chek printerlari, maʼlumot yigʻish terminallari (TSD), RFID oʻquvchilar va metkalar, POS uskunalari, sarf materiallari (yorliqlar, ribbonlar, chek lentalari) hamda kassa va tovar hisobi dasturlari bor. Poson uskunani tanlashda yordam beradi, uni oʻrnatadi, sozlaydi va 1C ga ulaydi. Kafolatli va kafolatdan keyingi taʼmirlash kompaniyaning oʻz servis markazida bajariladi, qoʻllab-quvvatlash oʻzbek, rus va ingliz tillarida koʻrsatiladi.',
    ],

    'about_points' => [
        'en' => [
            'Poson works with business customers in Uzbekistan: retail, warehouses and logistics, pharmacies, HoReCa and manufacturing.',
            'The main office is in Kokand. Delivery within Kokand is free, a courier delivers across Uzbekistan, and orders can be collected from the Kokand office.',
            'Product range: barcode scanners, label and receipt printers, mobile computers (TSD), RFID equipment, POS equipment, consumables and software.',
            'Equipment is supplied with an official warranty, and repairs are handled in Poson\'s own service centre.',
            'Companies can request a commercial quote on the website and pay by bank transfer.',
            'The website and customer support are available in Uzbek, Russian and English.',
        ],
        'ru' => [
            'Poson работает с бизнес-клиентами в Узбекистане: розница, склады и логистика, аптеки, HoReCa и производство.',
            'Главный офис находится в Коканде. Доставка по Коканду бесплатная, по Узбекистану доставляет курьерская служба, заказ можно забрать в офисе в Коканде.',
            'Ассортимент: сканеры штрих-кода, принтеры этикеток и чековые принтеры, терминалы сбора данных (ТСД), RFID- и POS-оборудование, расходные материалы и программное обеспечение.',
            'Оборудование поставляется с официальной гарантией, ремонт выполняет собственный сервисный центр Poson.',
            'Компании могут запросить коммерческое предложение на сайте и оплатить по безналичному расчёту.',
            'Сайт и поддержка клиентов доступны на узбекском, русском и английском языках.',
        ],
        'uz' => [
            'Poson Oʻzbekistondagi biznes mijozlar bilan ishlaydi: chakana savdo, ombor va logistika, dorixonalar, HoReCa va ishlab chiqarish.',
            'Bosh ofis Qoʻqon shahrida. Qoʻqon boʻylab yetkazib berish bepul, Oʻzbekiston boʻylab kuryer xizmati yetkazib beradi, buyurtmani Qoʻqondagi ofisdan olib ketish ham mumkin.',
            'Mahsulotlar: shtrix-kod skanerlari, yorliq va chek printerlari, maʼlumot yigʻish terminallari (TSD), RFID va POS uskunalari, sarf materiallari va dasturiy taʼminot.',
            'Uskunalar rasmiy kafolat bilan yetkaziladi, taʼmirlashni Posonning oʻz servis markazi bajaradi.',
            'Kompaniyalar saytda tijorat taklifi soʻrovini yuborishi va bank oʻtkazmasi orqali toʻlashi mumkin.',
            'Sayt va mijozlarni qoʻllab-quvvatlash oʻzbek, rus va ingliz tillarida ishlaydi.',
        ],
    ],

    'services_points' => [
        'en' => [
            'Selection: help choosing equipment and consumables for the task, the codes in use and the working environment.',
            'Setup and integration: installation, configuration and connection of scanners, printers, mobile computers and software to 1C.',
            'Warranty and repair: official warranty service, post-warranty repair and preventive maintenance in Poson\'s own service centre, with a written estimate of cost and timeline before any repair and on-site engineer visits.',
            'Training: staff training on scanners, printers, mobile computers and software after installation.',
            'Support: technical support in Uzbek, Russian and English.',
        ],
        'ru' => [
            'Подбор: помощь в выборе оборудования и расходных материалов под задачу, используемые коды и условия работы.',
            'Настройка и интеграция: установка, настройка и подключение сканеров, принтеров, ТСД и программ к 1С.',
            'Гарантия и ремонт: официальное гарантийное обслуживание, постгарантийный ремонт и профилактика в собственном сервисном центре Poson, письменная оценка стоимости и сроков до начала ремонта и выезд инженеров на объект.',
            'Обучение: обучение сотрудников работе со сканерами, принтерами, ТСД и программами после установки.',
            'Поддержка: техническая поддержка на узбекском, русском и английском языках.',
        ],
        'uz' => [
            'Tanlash: vazifa, ishlatiladigan kodlar va ish sharoitiga mos uskuna hamda sarf materiallarini tanlashda yordam.',
            'Sozlash va integratsiya: skaner, printer, TSD va dasturlarni oʻrnatish, sozlash hamda 1C ga ulash.',
            'Kafolat va taʼmirlash: rasmiy kafolat xizmati, kafolatdan keyingi taʼmirlash va profilaktika Posonning oʻz servis markazida, taʼmirlashdan oldin narx va muddatning yozma bahosi hamda muhandislarning ish joyiga chiqishi.',
            'Oʻqitish: oʻrnatishdan soʻng xodimlarni skaner, printer, TSD va dasturlar bilan ishlashga oʻrgatish.',
            'Qoʻllab-quvvatlash: oʻzbek, rus va ingliz tillarida texnik yordam.',
        ],
    ],

    'contact_note' => [
        'en' => 'Contact details and the quote request form are on the Poson website.',
        'ru' => 'Контакты и форма запроса коммерческого предложения — на сайте Poson.',
        'uz' => 'Aloqa maʼlumotlari va tijorat taklifi soʻrovi shakli Poson saytida joylashgan.',
    ],

];
