<?php

/**
 * Starter copy for system pages (Plan 07, T2), keyed by slug.
 *
 * Facts follow docs/pm/notes/business-facts.md (confirmed 2026-10-21): Kokand
 * main office, free delivery within Kokand, courier across Uzbekistan, pickup,
 * 1C as the only integration, manufacturer warranty, own service centre.
 * Phone, address, map and legal requisites come from Settings → Company and
 * are never hard-coded here. No prices, no low-price wording.
 *
 * privacy and offer are seeded with needs_review = true (admin badge only).
 * privacy is live and has no placeholders. Only the offer page (inactive
 * until the lawyer finishes it) still has open points for the lawyer and
 * client in [square brackets] inside its bodies.
 *
 * Uzbek is Latin script: oʻ/gʻ use ʻ (U+02BB), the tutuq belgisi uses ʼ (U+02BC).
 *
 * @return array<string, array{
 *     template: 'default'|'contacts'|'legal',
 *     needs_review: bool,
 *     title: array{uz: string, ru: string, en: string},
 *     meta_title: array{uz: string, ru: string, en: string},
 *     meta_description: array{uz: string, ru: string, en: string},
 *     body: array{uz: string, ru: string, en: string},
 *     faq: list<array{
 *         question: array{uz: string, ru: string, en: string},
 *         answer: array{uz: string, ru: string, en: string}
 *     }>
 * }>
 */
return [

    // ─── About ──────────────────────────────────────────────────────────────
    'about' => [
        'template' => 'default',
        'needs_review' => false,
        'title' => [
            'uz' => 'Biz haqimizda',
            'ru' => 'О компании',
            'en' => 'About us',
        ],
        'meta_title' => [
            'uz' => 'Biz haqimizda: Auto-ID uskunalari va servis — Poson',
            'ru' => 'О компании: Auto-ID оборудование и сервис — Poson',
            'en' => 'About us: Auto-ID equipment and service — Poson',
        ],
        'meta_description' => [
            'uz' => 'Poson — Qoʻqondagi Auto-ID kompaniyasi: skanerlar, printerlar, TSD, RFID va POS uskunalari, sozlash, 1C integratsiyasi, rasmiy kafolat va oʻz servis markazi.',
            'ru' => 'Poson — Auto-ID компания в Коканде: сканеры, принтеры, ТСД, RFID и POS-оборудование, настройка, интеграция с 1С, официальная гарантия и свой сервис-центр.',
            'en' => 'Poson in Kokand supplies Auto-ID equipment to businesses: scanners, printers, mobile computers, RFID and POS, with setup, 1C integration and a service centre.',
        ],
        'body' => [
            'uz' => <<<'HTML'
            <h2>Poson — Auto-ID uskunalari va ularga servis</h2>
            <p>Poson kompaniyalarni avtomatik identifikatsiya (Auto-ID) uskunalari bilan taʼminlaydi va ularga xizmat koʻrsatadi. Bosh ofisimiz Qoʻqon shahrida joylashgan, buyurtmalarni esa butun Oʻzbekiston boʻylab yetkazib beramiz.</p>
            <p>Biz uchun uskunani yetkazib berish — ishning boshlanishi, xolos. Mos modelni tanlash, sozlash, 1C bilan bogʻlash, xodimlarni oʻqitish va keyingi servis uchun bitta jamoa javob beradi.</p>
            <h3>Mahsulotlar</h3>
            <ul>
            <li>Shtrix-kod skanerlari</li>
            <li>Yorliq printerlari va chek printerlari</li>
            <li>Maʼlumot yigʻish terminallari (TSD)</li>
            <li>RFID uskunalari</li>
            <li>POS uskunalari va tarozilar</li>
            <li>Sarf materiallari: yorliqlar, ribbonlar, chek lentasi; nostandart oʻlchamdagi yorliqlar minimal partiya bilan buyurtma asosida tayyorlanadi</li>
            <li>Savdo, ombor hisobi va POS uchun dasturiy taʼminot</li>
            </ul>
            <h3>Xizmatlar</h3>
            <ul>
            <li><strong>Tanlash.</strong> Vazifangiz, ish hajmi va mavjud tizimlaringizga qarab uskunani tanlab beramiz.</li>
            <li><strong>Sozlash va ishga tushirish.</strong> Uskuna va dasturlarni sozlash ixtiyoriy xizmat sifatida mavjud, narxi alohida hisoblanadi. Sozlash buyurtma qilinganda xodimlaringizni oʻqitish ham unga kiradi.</li>
            <li><strong>1C bilan integratsiya.</strong> Skanerlar, printerlar, TSD, RFID va dasturiy taʼminotni 1C ga ulaymiz.</li>
            <li><strong>Rasmiy kafolat.</strong> Uskunalarga ishlab chiqaruvchining rasmiy kafolati beriladi, muddati brend va modelga bogʻliq.</li>
            <li><strong>Oʻz servis markazimiz.</strong> Diagnostika, taʼmirlash va profilaktika, shu jumladan boshqa joydan xarid qilingan uskunalar uchun ham.</li>
            <li><strong>Qoʻllab-quvvatlash.</strong> Masofaviy yordam va mutaxassisning joyiga chiqishi — oʻzbek, rus va ingliz tillarida.</li>
            </ul>
            <h3>Kimlar bilan ishlaymiz</h3>
            <p>Chakana savdo, omborlar va logistika, dorixonalar, HoReCa hamda ishlab chiqarish korxonalari.</p>
            <p>Kompaniyalar bilan qanday ishlashimiz haqida <a href="/uz/page/b2b">Kompaniyalar uchun</a> sahifasida oʻqing yoki <a href="/uz/page/contacts">murojaat qoldiring</a>.</p>
            HTML,
            'ru' => <<<'HTML'
            <h2>Poson — оборудование Auto-ID и сервис</h2>
            <p>Poson поставляет компаниям оборудование для автоматической идентификации (Auto-ID) и обслуживает его. Главный офис находится в Коканде, заказы мы доставляем по всему Узбекистану.</p>
            <p>Для нас поставка — только начало. Подбор модели, настройка, подключение к 1С, обучение сотрудников и дальнейший сервис — всё это делает одна команда.</p>
            <h3>Продукция</h3>
            <ul>
            <li>Сканеры штрих-кода</li>
            <li>Принтеры этикеток и чековые принтеры</li>
            <li>Терминалы сбора данных (ТСД)</li>
            <li>RFID-оборудование</li>
            <li>POS-оборудование и весы</li>
            <li>Расходные материалы: этикетки, риббоны, чековая лента; этикетки нестандартных размеров — под заказ с минимальной партией</li>
            <li>Программное обеспечение для торговли, складского учёта и POS</li>
            </ul>
            <h3>Услуги</h3>
            <ul>
            <li><strong>Подбор.</strong> Подбираем оборудование под вашу задачу, объём работы и действующие системы.</li>
            <li><strong>Настройка и запуск.</strong> Настройка оборудования и программ доступна как дополнительная услуга, её стоимость рассчитывается отдельно. Если вы заказываете настройку, обучение персонала входит в неё.</li>
            <li><strong>Интеграция с 1С.</strong> Подключаем к 1С сканеры, принтеры, ТСД, RFID и программное обеспечение.</li>
            <li><strong>Официальная гарантия.</strong> На оборудование действует официальная гарантия производителя, срок зависит от бренда и модели.</li>
            <li><strong>Собственный сервисный центр.</strong> Диагностика, ремонт и профилактика, в том числе оборудования, купленного в другом месте.</li>
            <li><strong>Поддержка.</strong> Удалённая помощь и выезд специалиста — на узбекском, русском и английском языках.</li>
            </ul>
            <h3>С кем мы работаем</h3>
            <p>Розничная торговля, склады и логистика, аптеки, HoReCa и производство.</p>
            <p>О том, как мы работаем с компаниями, читайте на странице <a href="/ru/page/b2b">Для бизнеса</a> или <a href="/ru/page/contacts">оставьте заявку</a>.</p>
            HTML,
            'en' => <<<'HTML'
            <h2>Poson: Auto-ID equipment and service</h2>
            <p>Poson supplies businesses with automatic identification (Auto-ID) equipment and services it. Our main office is in Kokand, and we deliver orders across Uzbekistan.</p>
            <p>For us, delivering the equipment is only the start. Choosing the right model, setup, connection to 1C, staff training and ongoing service are all handled by one team.</p>
            <h3>Products</h3>
            <ul>
            <li>Barcode scanners</li>
            <li>Label printers and receipt printers</li>
            <li>Mobile computers</li>
            <li>RFID equipment</li>
            <li>POS equipment and scales</li>
            <li>Consumables: labels, ribbons, receipt paper; custom label sizes made to order with a minimum batch</li>
            <li>Software for retail, inventory and POS</li>
            </ul>
            <h3>Services</h3>
            <ul>
            <li><strong>Selection.</strong> We choose equipment to match your task, workload and existing systems.</li>
            <li><strong>Setup and launch.</strong> Equipment and software setup is available as an optional service, quoted separately. When you order setup, staff training is included.</li>
            <li><strong>1C integration.</strong> We connect scanners, printers, mobile computers, RFID and software to 1C.</li>
            <li><strong>Official warranty.</strong> Equipment comes with the official manufacturer warranty; its length depends on the brand and model.</li>
            <li><strong>Our own service centre.</strong> Diagnostics, repair and preventive maintenance, including equipment bought elsewhere.</li>
            <li><strong>Support.</strong> Remote help and on-site visits, in Uzbek, Russian and English.</li>
            </ul>
            <h3>Who we work with</h3>
            <p>Retail, warehouses and logistics, pharmacies, HoReCa and manufacturing.</p>
            <p>Read how we work with companies on the <a href="/en/page/b2b">For business</a> page, or <a href="/en/page/contacts">send us an enquiry</a>.</p>
            HTML,
        ],
        'faq' => [],
    ],

    // ─── Contacts ───────────────────────────────────────────────────────────
    'contacts' => [
        'template' => 'contacts',
        'needs_review' => false,
        'title' => [
            'uz' => 'Kontaktlar',
            'ru' => 'Контакты',
            'en' => 'Contacts',
        ],
        'meta_title' => [
            'uz' => 'Kontaktlar: Qoʻqondagi ofis — Poson',
            'ru' => 'Контакты: офис в Коканде — Poson',
            'en' => 'Contacts: Kokand office — Poson',
        ],
        'meta_description' => [
            'uz' => 'Poson bilan bogʻlaning: Qoʻqondagi ofis manzili, telefon va xarita. Buyurtmani ofisdan olib ketish yoki murojaat qoldirish — menejer siz bilan bogʻlanadi.',
            'ru' => 'Контакты Poson: офис в Коканде, телефон, адрес и карта. Самовывоз из офиса, консультация по оборудованию и сервису — оставьте заявку, менеджер перезвонит.',
            'en' => 'Contact Poson: office in Kokand, phone, address and map. Pick up orders at the office or send an enquiry about equipment or service and a manager will reply.',
        ],
        'body' => [
            'uz' => <<<'HTML'
            <p>Bosh ofisimiz Qoʻqon shahrida joylashgan. Telefon raqami, manzil va xarita quyida keltirilgan.</p>
            <p>Buyurtmangizni ofisimizdan oʻzingiz olib ketishingiz mumkin. Uskuna tanlash, tijorat taklifi yoki servis boʻyicha savollaringiz boʻlsa, quyidagi shakl orqali murojaat qoldiring — menejerimiz siz bilan bogʻlanadi. Oʻzbek, rus va ingliz tillarida maslahat beramiz.</p>
            HTML,
            'ru' => <<<'HTML'
            <p>Наш главный офис находится в Коканде. Телефон, адрес и карта — ниже на этой странице.</p>
            <p>Заказ можно забрать самостоятельно из офиса. Если нужен подбор оборудования, коммерческое предложение или сервис, оставьте заявку через форму ниже — менеджер свяжется с вами. Консультируем на узбекском, русском и английском языках.</p>
            HTML,
            'en' => <<<'HTML'
            <p>Our main office is in Kokand. You will find the phone number, address and map below.</p>
            <p>You can pick up your order from the office yourself. If you need help choosing equipment, a quote or service, send an enquiry through the form below and a manager will get in touch. We can help in Uzbek, Russian or English.</p>
            HTML,
        ],
        'faq' => [],
    ],

    // ─── Delivery and payment ───────────────────────────────────────────────
    'delivery-payment' => [
        'template' => 'default',
        'needs_review' => false,
        'title' => [
            'uz' => 'Yetkazib berish va toʻlov',
            'ru' => 'Доставка и оплата',
            'en' => 'Delivery and payment',
        ],
        'meta_title' => [
            'uz' => 'Yetkazib berish va toʻlov usullari — Poson',
            'ru' => 'Доставка и способы оплаты — Poson',
            'en' => 'Delivery and payment methods — Poson',
        ],
        'meta_description' => [
            'uz' => 'Qoʻqon boʻylab bepul yetkazib berish, Oʻzbekiston boʻylab kuryer, ofisdan olib ketish. Toʻlov: bank oʻtkazmasi, naqd, Payme, Click, karta terminali va UzQR.',
            'ru' => 'Бесплатная доставка по Коканду, курьером по всему Узбекистану, самовывоз из офиса. Оплата: перечисление по счёту, наличные, Payme, Click, терминал и UzQR.',
            'en' => 'Free delivery within Kokand, courier delivery across Uzbekistan, pickup from our office. Payment by bank transfer, cash, Payme, Click, card terminal or UzQR.',
        ],
        'body' => [
            'uz' => <<<'HTML'
            <h2>Yetkazib berish</h2>
            <ul>
            <li><strong>Qoʻqon boʻylab</strong> — yetkazib berish bepul.</li>
            <li><strong>Oʻzbekiston boʻylab</strong> — kuryer xizmati orqali.</li>
            <li><strong>Olib ketish</strong> — Qoʻqondagi ofisimizdan.</li>
            </ul>
            <p>Yetkazib berish muddati va shartlarini menejer buyurtmani tasdiqlashda aytadi.</p>
            <h2>Toʻlov</h2>
            <ul>
            <li>Bank orqali oʻtkazma — hisob-varaq asosida</li>
            <li>Naqd pul</li>
            <li>Payme va Click</li>
            <li>Terminal orqali bank kartasi bilan</li>
            <li>UzQR</li>
            </ul>
            <p>Tijorat takliflarida narxlar 12% QQS bilan koʻrsatiladi. Kompaniyalar uchun hisob-varaq rekvizitlaringiz asosida rasmiylashtiriladi.</p>
            <p>Savollar boʻlsa, <a href="/uz/page/contacts">murojaat qoldiring</a>.</p>
            HTML,
            'ru' => <<<'HTML'
            <h2>Доставка</h2>
            <ul>
            <li><strong>По Коканду</strong> — бесплатно.</li>
            <li><strong>По Узбекистану</strong> — курьерской службой.</li>
            <li><strong>Самовывоз</strong> — из нашего офиса в Коканде.</li>
            </ul>
            <p>Сроки и условия доставки менеджер сообщает при подтверждении заказа.</p>
            <h2>Оплата</h2>
            <ul>
            <li>Безналичное перечисление по счёту</li>
            <li>Наличные</li>
            <li>Payme и Click</li>
            <li>Банковской картой через терминал</li>
            <li>UzQR</li>
            </ul>
            <p>В коммерческих предложениях цены указываются с учётом НДС 12%. Для компаний счёт оформляется по вашим реквизитам.</p>
            <p>Остались вопросы? <a href="/ru/page/contacts">Оставьте заявку</a>.</p>
            HTML,
            'en' => <<<'HTML'
            <h2>Delivery</h2>
            <ul>
            <li><strong>Within Kokand</strong>: delivery is free.</li>
            <li><strong>Across Uzbekistan</strong>: by courier.</li>
            <li><strong>Pickup</strong>: from our Kokand office.</li>
            </ul>
            <p>A manager confirms the delivery timeframe and terms when your order is confirmed.</p>
            <h2>Payment</h2>
            <ul>
            <li>Bank transfer against an invoice</li>
            <li>Cash</li>
            <li>Payme and Click</li>
            <li>Bank card at a card terminal</li>
            <li>UzQR</li>
            </ul>
            <p>Prices in our quotes include 12% VAT. Companies receive an invoice made out to their company details.</p>
            <p>Any questions? <a href="/en/page/contacts">Send us an enquiry</a>.</p>
            HTML,
        ],
        'faq' => [
            [
                'question' => [
                    'uz' => 'Qoʻqondan tashqariga yetkazib berasizmi?',
                    'ru' => 'Доставляете ли вы за пределы Коканда?',
                    'en' => 'Do you deliver outside Kokand?',
                ],
                'answer' => [
                    'uz' => 'Ha, Oʻzbekiston boʻylab buyurtmalarni kuryer xizmati yetkazadi. Muddat va shartlarni menejer buyurtmani tasdiqlashda aytadi.',
                    'ru' => 'Да, по всему Узбекистану заказы доставляет курьерская служба. Сроки и условия менеджер сообщает при подтверждении заказа.',
                    'en' => 'Yes, orders across Uzbekistan are delivered by courier. A manager confirms the timeframe and terms when the order is confirmed.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Buyurtmani oʻzim olib keta olamanmi?',
                    'ru' => 'Можно ли забрать заказ самостоятельно?',
                    'en' => 'Can I pick up the order myself?',
                ],
                'answer' => [
                    'uz' => 'Ha, buyurtmani Qoʻqondagi ofisimizdan olib ketishingiz mumkin. Olib ketish vaqtini menejer bilan kelishib oling.',
                    'ru' => 'Да, заказ можно забрать из нашего офиса в Коканде. Время самовывоза согласуйте с менеджером.',
                    'en' => 'Yes, you can pick up the order from our Kokand office. Please arrange a pickup time with a manager.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Kompaniya bank orqali toʻlay oladimi?',
                    'ru' => 'Может ли компания оплатить безналичным переводом?',
                    'en' => 'Can a company pay by bank transfer?',
                ],
                'answer' => [
                    'uz' => 'Ha. Menejer kompaniyangiz rekvizitlari asosida hisob-varaq tayyorlaydi, toʻlov bank orqali oʻtkaziladi.',
                    'ru' => 'Да. Менеджер выставит счёт на реквизиты вашей компании, оплата производится банковским переводом.',
                    'en' => 'Yes. A manager issues an invoice to your company details, and payment is made by bank transfer.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Narxlarga QQS kiradimi?',
                    'ru' => 'Включён ли НДС в цены?',
                    'en' => 'Do prices include VAT?',
                ],
                'answer' => [
                    'uz' => 'Tijorat takliflarida narxlar 12% QQS bilan koʻrsatiladi.',
                    'ru' => 'В коммерческих предложениях цены указываются с учётом НДС 12%.',
                    'en' => 'Yes, prices in our quotes include 12% VAT.',
                ],
            ],
        ],
    ],

    // ─── Warranty and service ───────────────────────────────────────────────
    'warranty-service' => [
        'template' => 'default',
        'needs_review' => false,
        'title' => [
            'uz' => 'Kafolat va servis',
            'ru' => 'Гарантия и сервис',
            'en' => 'Warranty and service',
        ],
        'meta_title' => [
            'uz' => 'Kafolat va servis xizmati — Poson',
            'ru' => 'Гарантия и сервисное обслуживание — Poson',
            'en' => 'Warranty and service — Poson',
        ],
        'meta_description' => [
            'uz' => 'Ishlab chiqaruvchining rasmiy kafolati, oʻz servis markazi: avval diagnostika, taʼmirdan oldin yozma smeta, profilaktika, servis shartnomalari va joyida yordam.',
            'ru' => 'Официальная гарантия производителя и свой сервис-центр: сначала диагностика, письменная смета до ремонта, профилактика, сервисные договоры, выезд инженера.',
            'en' => 'Official manufacturer warranty and our own service centre: diagnostics first, a written estimate before repair, preventive maintenance and on-site support.',
        ],
        'body' => [
            'uz' => <<<'HTML'
            <h2>Rasmiy kafolat</h2>
            <p>Poson yetkazib bergan uskunalarga ishlab chiqaruvchining rasmiy kafolati beriladi. Kafolat muddati brend va modelga bogʻliq, u tijorat taklifida va kafolat talonida koʻrsatiladi.</p>
            <h2>Servis markazi</h2>
            <p>Taʼmirlashni oʻz servis markazimizda bajaramiz. Boshqa joydan xarid qilingan uskunalarni ham qabul qilamiz — biz xizmat koʻrsatadigan istalgan brend boʻyicha.</p>
            <h3>Taʼmirlash qanday boʻladi</h3>
            <ul>
            <li><strong>Diagnostika.</strong> Avval nosozlik sababini aniqlaymiz.</li>
            <li><strong>Yozma smeta.</strong> Taʼmirlash boshlanishidan oldin narx va muddatni yozma ravishda kelishamiz.</li>
            <li><strong>Taʼmirlash.</strong> Ishni faqat siz tasdiqlaganingizdan keyin boshlaymiz.</li>
            </ul>
            <p>Taʼmirlash muddati nosozlik turiga bogʻliq va diagnostikadan keyin aniq boʻladi.</p>
            <h3>Profilaktika va servis shartnomalari</h3>
            <p>Uskunalaringiz uzoq va barqaror ishlashi uchun profilaktik xizmat koʻrsatamiz. Servis shartnomasi shartlari — javob berish vaqti va tashriflar soni — har bir mijoz bilan alohida kelishiladi.</p>
            <h3>Qoʻllab-quvvatlash</h3>
            <ul>
            <li>Masofaviy sozlash va yordam</li>
            <li>Mutaxassisning joyiga chiqishi</li>
            <li>Oʻzbek, rus va ingliz tillarida</li>
            </ul>
            <p>Servis boʻyicha <a href="/uz/page/contacts">murojaat qoldiring</a> — nosozlikni qisqacha tasvirlang, mutaxassisimiz siz bilan bogʻlanadi.</p>
            HTML,
            'ru' => <<<'HTML'
            <h2>Официальная гарантия</h2>
            <p>На оборудование, поставленное Poson, действует официальная гарантия производителя. Срок гарантии зависит от бренда и модели и указывается в коммерческом предложении и гарантийном талоне.</p>
            <h2>Сервисный центр</h2>
            <p>Ремонт выполняет наш собственный сервисный центр. Мы принимаем и оборудование, купленное в другом месте, — любого бренда, который мы обслуживаем.</p>
            <h3>Как проходит ремонт</h3>
            <ul>
            <li><strong>Диагностика.</strong> Сначала определяем причину неисправности.</li>
            <li><strong>Письменная смета.</strong> До начала ремонта письменно согласуем стоимость и сроки.</li>
            <li><strong>Ремонт.</strong> Приступаем к работе только после вашего подтверждения.</li>
            </ul>
            <p>Срок ремонта зависит от неисправности и становится понятен после диагностики.</p>
            <h3>Профилактика и сервисные договоры</h3>
            <p>Проводим профилактическое обслуживание, чтобы оборудование работало стабильно и дольше. Условия сервисного договора — время реакции и количество выездов — согласуются с каждым клиентом индивидуально.</p>
            <h3>Поддержка</h3>
            <ul>
            <li>Удалённая настройка и помощь</li>
            <li>Выезд специалиста</li>
            <li>На узбекском, русском и английском языках</li>
            </ul>
            <p><a href="/ru/page/contacts">Оставьте заявку на сервис</a> и кратко опишите неисправность — специалист свяжется с вами.</p>
            HTML,
            'en' => <<<'HTML'
            <h2>Official warranty</h2>
            <p>Equipment supplied by Poson comes with the official manufacturer warranty. The warranty period depends on the brand and model and is stated in the quote and on the warranty card.</p>
            <h2>Service centre</h2>
            <p>Repairs are carried out in our own service centre. We also accept equipment bought elsewhere, for any brand we service.</p>
            <h3>How a repair works</h3>
            <ul>
            <li><strong>Diagnostics.</strong> First we find the cause of the fault.</li>
            <li><strong>Written estimate.</strong> Before the repair starts, we agree the cost and timeline with you in writing.</li>
            <li><strong>Repair.</strong> We start work only after you confirm.</li>
            </ul>
            <p>Repair time depends on the fault and becomes clear after diagnostics.</p>
            <h3>Preventive maintenance and service contracts</h3>
            <p>We carry out preventive maintenance so your equipment runs reliably for longer. Service contract terms, such as response time and number of visits, are agreed individually with each client.</p>
            <h3>Support</h3>
            <ul>
            <li>Remote setup and help</li>
            <li>On-site visits by a specialist</li>
            <li>In Uzbek, Russian and English</li>
            </ul>
            <p><a href="/en/page/contacts">Send a service enquiry</a> with a short description of the fault, and a specialist will get in touch.</p>
            HTML,
        ],
        'faq' => [
            [
                'question' => [
                    'uz' => 'Kafolat muddati qancha?',
                    'ru' => 'Какой срок гарантии?',
                    'en' => 'How long is the warranty?',
                ],
                'answer' => [
                    'uz' => 'Muddat brend va modelga bogʻliq. U tijorat taklifida va kafolat talonida koʻrsatiladi.',
                    'ru' => 'Срок зависит от бренда и модели. Он указывается в коммерческом предложении и гарантийном талоне.',
                    'en' => 'It depends on the brand and model. The period is stated in the quote and on the warranty card.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Boshqa joydan olingan uskunani taʼmirlaysizmi?',
                    'ru' => 'Ремонтируете ли вы оборудование, купленное в другом месте?',
                    'en' => 'Do you repair equipment bought elsewhere?',
                ],
                'answer' => [
                    'uz' => 'Ha, biz xizmat koʻrsatadigan istalgan brend boʻyicha. Avval diagnostika oʻtkazamiz, soʻngra narx va muddatni yozma kelishamiz.',
                    'ru' => 'Да, любого бренда, который мы обслуживаем. Сначала проводим диагностику, затем письменно согласуем стоимость и сроки.',
                    'en' => 'Yes, for any brand we service. We run diagnostics first and then agree the cost and timeline in writing.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Taʼmirlash qancha vaqt oladi?',
                    'ru' => 'Сколько длится ремонт?',
                    'en' => 'How long does a repair take?',
                ],
                'answer' => [
                    'uz' => 'Bu nosozlikka bogʻliq. Diagnostikadan keyin muddat va narxni yozma ravishda aytamiz va ishni faqat tasdiqlaganingizdan keyin boshlaymiz.',
                    'ru' => 'Это зависит от неисправности. После диагностики мы письменно сообщаем сроки и стоимость и начинаем работу только после вашего подтверждения.',
                    'en' => 'It depends on the fault. After diagnostics we give you the timeline and cost in writing and start only after you confirm.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Servis shartnomasi tuzish mumkinmi?',
                    'ru' => 'Можно ли заключить сервисный договор?',
                    'en' => 'Can we sign a service contract?',
                ],
                'answer' => [
                    'uz' => 'Ha. Javob berish vaqti, tashriflar soni va profilaktika tarkibi har bir mijoz bilan alohida kelishiladi.',
                    'ru' => 'Да. Время реакции, количество выездов и состав профилактики согласуются с каждым клиентом индивидуально.',
                    'en' => 'Yes. Response time, number of visits and the scope of preventive maintenance are agreed individually with each client.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Mutaxassis joyiga chiqadimi?',
                    'ru' => 'Выезжает ли специалист на место?',
                    'en' => 'Does a specialist come on site?',
                ],
                'answer' => [
                    'uz' => 'Ha, kerak boʻlsa mutaxassisimiz joyiga chiqadi. Koʻp masalalarni masofaviy ravishda ham hal qilamiz.',
                    'ru' => 'Да, при необходимости специалист выезжает на место. Многие вопросы мы также решаем удалённо.',
                    'en' => 'Yes, a specialist comes on site when needed. Many issues can also be solved remotely.',
                ],
            ],
        ],
    ],

    // ─── For business (B2B) ─────────────────────────────────────────────────
    'b2b' => [
        'template' => 'default',
        'needs_review' => false,
        'title' => [
            'uz' => 'Kompaniyalar uchun',
            'ru' => 'Для бизнеса',
            'en' => 'For business',
        ],
        'meta_title' => [
            'uz' => 'Kompaniyalar uchun: B2B hamkorlik — Poson',
            'ru' => 'Для бизнеса: работа с компаниями — Poson',
            'en' => 'For business: working with companies — Poson',
        ],
        'meta_description' => [
            'uz' => 'Kompaniyalar uchun Poson: shaxsiy menejer, tijorat taklifi, hisob-varaq va bank orqali toʻlov, 1C bilan integratsiya, sozlash, xodimlarni oʻqitish va servis.',
            'ru' => 'Poson для компаний: персональный менеджер, коммерческое предложение, оплата по счёту, интеграция с 1С, настройка с обучением персонала и сервисные договоры.',
            'en' => 'Poson for companies: a personal manager, quotes, invoice and bank transfer, 1C integration, setup with staff training and service contracts.',
        ],
        'body' => [
            'uz' => <<<'HTML'
            <h2>Kompaniyalar bilan ishlash</h2>
            <p>Poson asosan kompaniyalar bilan ishlaydi: chakana savdo, omborlar va logistika, dorixonalar, HoReCa va ishlab chiqarish. Biz uskunani yetkazib berish bilan cheklanmaymiz — tanlashda yordam beramiz, keyingi servisni oʻz zimmamizga olamiz, sozlash va 1C bilan bogʻlashni esa ixtiyoriy xizmat sifatida taklif qilamiz.</p>
            <h3>Qanday ishlaymiz</h3>
            <ul>
            <li><strong>Shaxsiy menejer.</strong> Bitta menejer sizning buyurtmalaringiz va loyihalaringizni boshidan oxirigacha olib boradi.</li>
            <li><strong>Tijorat taklifi.</strong> Vazifangizga mos uskuna va xizmatlar bilan tijorat taklifi tayyorlaymiz. Narxlar 12% QQS bilan, amal qilish muddati taklifda koʻrsatiladi.</li>
            <li><strong>Hisob-varaq va bank orqali toʻlov.</strong> Hisob-varaq kompaniyangiz rekvizitlari asosida rasmiylashtiriladi.</li>
            <li><strong>1C bilan integratsiya.</strong> Skanerlar, printerlar, TSD, RFID va dasturiy taʼminotni 1C ga ulaymiz.</li>
            <li><strong>Uskuna va dasturlarni sozlash.</strong> Uskuna va dasturiy taʼminotni sozlash ixtiyoriy xizmat sifatida mavjud, narxi tijorat taklifida alohida koʻrsatiladi. Sozlash buyurtma qilinganda xodimlarni oʻqitish ham unga kiradi.</li>
            <li><strong>Yorliq shablonlari.</strong> Yorliq shablonlarini tayyorlash alohida xizmat sifatida bajariladi; nostandart oʻlchamdagi yorliqlar minimal partiya bilan buyurtma asosida tayyorlanadi.</li>
            <li><strong>Servis shartnomalari.</strong> Profilaktika, javob berish vaqti va tashriflar soni alohida kelishiladi.</li>
            </ul>
            <h3>Qanday boshlash mumkin</h3>
            <p>Vazifangizni qisqacha yozib <a href="/uz/page/contacts">murojaat qoldiring</a> yoki mahsulot sahifasida tijorat taklifi soʻrovini yuboring. Menejerimiz siz bilan bogʻlanib, taklif tayyorlaydi.</p>
            HTML,
            'ru' => <<<'HTML'
            <h2>Работа с компаниями</h2>
            <p>Poson работает в первую очередь с компаниями: розничная торговля, склады и логистика, аптеки, HoReCa и производство. Мы не ограничиваемся поставкой — помогаем с подбором, берём на себя дальнейший сервис, а настройку и подключение к 1С предлагаем как дополнительную услугу.</p>
            <h3>Как мы работаем</h3>
            <ul>
            <li><strong>Персональный менеджер.</strong> Один менеджер ведёт ваши заказы и проекты от начала до конца.</li>
            <li><strong>Коммерческое предложение.</strong> Готовим предложение с оборудованием и услугами под вашу задачу. Цены указываются с НДС 12%, срок действия — в самом предложении.</li>
            <li><strong>Счёт и оплата перечислением.</strong> Счёт оформляется на реквизиты вашей компании.</li>
            <li><strong>Интеграция с 1С.</strong> Подключаем к 1С сканеры, принтеры, ТСД, RFID и программное обеспечение.</li>
            <li><strong>Настройка оборудования и программ.</strong> Настройка оборудования и программного обеспечения доступна как дополнительная услуга, её стоимость указывается в предложении отдельно. Если вы заказываете настройку, обучение персонала входит в неё.</li>
            <li><strong>Шаблоны этикеток.</strong> Разработка шаблонов этикеток выполняется как отдельная услуга; этикетки нестандартных размеров — под заказ с минимальной партией.</li>
            <li><strong>Сервисные договоры.</strong> Профилактика, время реакции и количество выездов согласуются индивидуально.</li>
            </ul>
            <h3>Как начать</h3>
            <p>Кратко опишите задачу и <a href="/ru/page/contacts">оставьте заявку</a> или отправьте запрос коммерческого предложения со страницы товара. Менеджер свяжется с вами и подготовит предложение.</p>
            HTML,
            'en' => <<<'HTML'
            <h2>Working with companies</h2>
            <p>Poson works mainly with companies in retail, warehousing and logistics, pharmacy, HoReCa and manufacturing. We do more than supply equipment: we help with selection and take care of ongoing service, and offer setup and connection to 1C as an optional service.</p>
            <h3>How we work</h3>
            <ul>
            <li><strong>Personal manager.</strong> One manager handles your orders and projects from start to finish.</li>
            <li><strong>Quote.</strong> We prepare a quote with the equipment and services your task needs. Prices include 12% VAT, and the quote states how long it is valid.</li>
            <li><strong>Invoice and bank transfer.</strong> The invoice is issued to your company details.</li>
            <li><strong>1C integration.</strong> We connect scanners, printers, mobile computers, RFID and software to 1C.</li>
            <li><strong>Equipment and software setup.</strong> Setup of equipment and software is available as an optional service, quoted separately. When you order setup, staff training is included.</li>
            <li><strong>Label templates.</strong> Designing label templates is a separate service; custom label sizes are made to order with a minimum batch.</li>
            <li><strong>Service contracts.</strong> Preventive maintenance, response time and number of visits are agreed individually.</li>
            </ul>
            <h3>How to start</h3>
            <p>Describe your task briefly and <a href="/en/page/contacts">send us an enquiry</a>, or request a quote from a product page. A manager will get in touch and prepare the quote.</p>
            HTML,
        ],
        'faq' => [
            [
                'question' => [
                    'uz' => 'Tijorat taklifini qanday olsam boʻladi?',
                    'ru' => 'Как получить коммерческое предложение?',
                    'en' => 'How do I get a quote?',
                ],
                'answer' => [
                    'uz' => 'Saytda murojaat qoldiring yoki mahsulot sahifasida tijorat taklifi soʻrovini yuboring. Menejer taklif tayyorlaydi, unda narxlar 12% QQS bilan koʻrsatiladi.',
                    'ru' => 'Оставьте заявку на сайте или отправьте запрос коммерческого предложения со страницы товара. Менеджер подготовит предложение, цены в нём указываются с НДС 12%.',
                    'en' => 'Send an enquiry on the website or request a quote from a product page. A manager prepares the quote, with prices including 12% VAT.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Uskunani 1C ga ulab berasizmi?',
                    'ru' => 'Подключаете ли вы оборудование к 1С?',
                    'en' => 'Do you connect equipment to 1C?',
                ],
                'answer' => [
                    'uz' => 'Ha. Skanerlar, printerlar, TSD, RFID va dasturiy taʼminotni 1C ga ulaymiz. Ish tarkibi tijorat taklifida koʻrsatiladi.',
                    'ru' => 'Да. Мы подключаем к 1С сканеры, принтеры, ТСД, RFID и программное обеспечение. Состав работ указывается в коммерческом предложении.',
                    'en' => 'Yes. We connect scanners, printers, mobile computers, RFID and software to 1C. The scope of work is set out in the quote.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Xodimlarimizni oʻqitasizmi?',
                    'ru' => 'Обучаете ли вы сотрудников?',
                    'en' => 'Do you train our staff?',
                ],
                'answer' => [
                    'uz' => 'Ha. Sozlash ixtiyoriy xizmat sifatida mavjud va alohida hisoblanadi; uni buyurtma qilsangiz, xodimlarni oʻqitish ham unga kiradi.',
                    'ru' => 'Да. Настройка доступна как дополнительная услуга и рассчитывается отдельно; если вы её заказываете, обучение персонала входит в неё.',
                    'en' => 'Yes. Setup is available as an optional service, quoted separately; when you order it, staff training is included.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Servis shartnomasi qanday shartlarda tuziladi?',
                    'ru' => 'На каких условиях заключается сервисный договор?',
                    'en' => 'What are the terms of a service contract?',
                ],
                'answer' => [
                    'uz' => 'Shartlar individual: javob berish vaqti, tashriflar soni va profilaktika tarkibi har bir kompaniya bilan alohida kelishiladi.',
                    'ru' => 'Условия индивидуальные: время реакции, количество выездов и состав профилактики согласуются с каждой компанией отдельно.',
                    'en' => 'Terms are individual: response time, number of visits and the scope of preventive maintenance are agreed with each company.',
                ],
            ],
        ],
    ],

    // ─── Privacy policy (draft for a lawyer) ────────────────────────────────
    'privacy' => [
        'template' => 'legal',
        'needs_review' => true,
        'title' => [
            'uz' => 'Maxfiylik siyosati',
            'ru' => 'Политика конфиденциальности',
            'en' => 'Privacy policy',
        ],
        'meta_title' => [
            'uz' => 'Maxfiylik siyosati — Poson',
            'ru' => 'Политика конфиденциальности — Poson',
            'en' => 'Privacy policy — Poson',
        ],
        'meta_description' => [
            'uz' => 'Poson sayti qanday shaxsga doir maʼlumotlarni yigʻadi, ulardan qaysi maqsadda foydalanadi, qayerda va qancha saqlaydi hamda sizning huquqlaringiz haqida.',
            'ru' => 'Какие персональные данные собирает сайт Poson, для чего их использует, где и сколько хранит, а также ваши права и порядок обращения по вопросам данных.',
            'en' => 'What personal data the Poson website collects, why we use it, where and how long it is stored, your rights and how to contact us about your data.',
        ],
        'body' => [
            'uz' => <<<'HTML'
            <h2>1. Umumiy qoidalar</h2>
            <p>Ushbu siyosat Poson sayti foydalanuvchilarining shaxsga doir maʼlumotlari qanday yigʻilishi, ishlatilishi va himoya qilinishini belgilaydi. Siyosat Oʻzbekiston Respublikasi qonunchiligiga, jumladan «Shaxsga doir maʼlumotlar toʻgʻrisida»gi Qonunga muvofiq tuzilgan.</p>
            <h2>2. Operator</h2>
            <p>Shaxsga doir maʼlumotlar operatori — Poson. Operatorning toʻliq yuridik nomi, rekvizitlari va manzili <a href="/uz/page/contacts">Kontaktlar</a> sahifasida koʻrsatilgan.</p>
            <h2>3. Qanday maʼlumotlarni yigʻamiz</h2>
            <h3>3.1. Murojaat shakli</h3>
            <ul>
            <li>Ism</li>
            <li>Telefon raqami</li>
            <li>Kompaniya nomi</li>
            <li>Xabar matni</li>
            </ul>
            <h3>3.2. Tijorat taklifi havolasi</h3>
            <p>Tijorat taklifi sizga shaxsiy havola orqali yuboriladi, uni taxmin qilib boʻlmaydi. Taklifda siz bergan kompaniya va kontakt maʼlumotlari koʻrsatiladi. Havola ochilganda faqat taklif ochilgani va sanasi qayd etiladi.</p>
            <h3>3.3. Sayt statistikasi</h3>
            <p>Saytda oʻz analitikamiz ishlaydi: tashrif buyuruvchilarning IP manzillari saqlanmaydi va cookie fayllari ishlatilmaydi. Faqat umumlashtirilgan statistika (sahifalar koʻrilishi, tashriflar soni) yigʻiladi.</p>
            <p>Agar yoqilgan boʻlsa, Google Analytics 4 va Yandex Metrika xizmatlari ham ishlatiladi. Ular cookie fayllaridan foydalanadi va maʼlumotlarni oʻz maxfiylik siyosatlariga muvofiq qayta ishlaydi.</p>
            <h2>4. Maqsadlar</h2>
            <ul>
            <li>Murojaatlarga javob berish va maslahat berish</li>
            <li>Tijorat takliflarini tayyorlash va buyurtmalarni bajarish</li>
            <li>Servis va texnik yordam koʻrsatish</li>
            <li>Sayt ishini tahlil qilish va yaxshilash</li>
            </ul>
            <h2>5. Saqlash joyi va muddati</h2>
            <p>Sayt orqali yigʻilgan maʼlumotlar Oʻzbekiston Respublikasi hududidagi serverlarda saqlanadi.</p>
            <h2>6. Maʼlumotlarni uzatish</h2>
            <p>Murojaat haqidagi xabarnomalar xodimlarimizga Telegram messenjeri orqali yuboriladi. Boshqa uchinchi shaxslarga maʼlumotlar faqat qonunchilikda nazarda tutilgan hollarda beriladi.</p>
            <h2>7. Sizning huquqlaringiz</h2>
            <ul>
            <li>Oʻzingiz haqingizdagi maʼlumotlar bilan tanishish</li>
            <li>Maʼlumotlarni aniqlashtirish yoki tuzatish</li>
            <li>Maʼlumotlarni oʻchirishni talab qilish</li>
            <li>Ishlov berishga rozilikni qaytarib olish</li>
            </ul>
            <h2>8. Murojaatlar uchun aloqa</h2>
            <p>Shaxsga doir maʼlumotlar boʻyicha soʻrovlarni <a href="/uz/page/contacts">Kontaktlar</a> sahifasidagi aloqa maʼlumotlari yoki murojaat shakli orqali yuboring.</p>
            <h2>9. Siyosatni oʻzgartirish</h2>
            <p>Siyosat yangilanishi mumkin. Amaldagi tahrir shu sahifada eʼlon qilinadi va yangilangan sana koʻrsatiladi.</p>
            HTML,
            'ru' => <<<'HTML'
            <h2>1. Общие положения</h2>
            <p>Настоящая политика определяет, как собираются, используются и защищаются персональные данные пользователей сайта Poson. Политика составлена в соответствии с законодательством Республики Узбекистан, в том числе Законом «О персональных данных».</p>
            <h2>2. Оператор</h2>
            <p>Оператор персональных данных — Poson. Полное юридическое наименование, реквизиты и адрес оператора указаны на странице <a href="/ru/page/contacts">Контакты</a>.</p>
            <h2>3. Какие данные мы собираем</h2>
            <h3>3.1. Форма заявки</h3>
            <ul>
            <li>Имя</li>
            <li>Номер телефона</li>
            <li>Название компании</li>
            <li>Текст сообщения</li>
            </ul>
            <h3>3.2. Ссылка на коммерческое предложение</h3>
            <p>Коммерческое предложение направляется по персональной ссылке, которую невозможно подобрать. В предложении указаны данные компании и контактного лица, которые вы сообщили. При открытии ссылки фиксируется только факт и дата открытия.</p>
            <h3>3.3. Статистика сайта</h3>
            <p>На сайте работает собственная аналитика: IP-адреса посетителей не сохраняются, файлы cookie не используются. Собирается только обобщённая статистика (просмотры страниц, количество посещений).</p>
            <p>Если они включены, также используются Google Analytics 4 и Яндекс Метрика. Эти сервисы используют файлы cookie и обрабатывают данные в соответствии со своими политиками конфиденциальности.</p>
            <h2>4. Цели обработки</h2>
            <ul>
            <li>Ответ на заявки и консультации</li>
            <li>Подготовка коммерческих предложений и выполнение заказов</li>
            <li>Сервисное обслуживание и техническая поддержка</li>
            <li>Анализ и улучшение работы сайта</li>
            </ul>
            <h2>5. Место и срок хранения</h2>
            <p>Данные, собранные через сайт, хранятся на серверах на территории Республики Узбекистан.</p>
            <h2>6. Передача данных</h2>
            <p>Уведомления о заявках направляются нашим сотрудникам через мессенджер Telegram. Иным третьим лицам данные передаются только в случаях, предусмотренных законодательством.</p>
            <h2>7. Ваши права</h2>
            <ul>
            <li>Получить сведения о своих данных</li>
            <li>Уточнить или исправить данные</li>
            <li>Потребовать удаления данных</li>
            <li>Отозвать согласие на обработку</li>
            </ul>
            <h2>8. Контакт для обращений</h2>
            <p>Запросы по персональным данным направляйте через контакты или форму заявки на странице <a href="/ru/page/contacts">Контакты</a>.</p>
            <h2>9. Изменение политики</h2>
            <p>Политика может обновляться. Действующая редакция публикуется на этой странице с указанием даты обновления.</p>
            HTML,
            'en' => <<<'HTML'
            <h2>1. General provisions</h2>
            <p>This policy sets out how personal data of Poson website users is collected, used and protected. It is drawn up in line with the legislation of the Republic of Uzbekistan, including the Law “On Personal Data”.</p>
            <h2>2. Operator</h2>
            <p>The personal data operator is Poson. The full legal name, company details and address of the operator are shown on the <a href="/en/page/contacts">Contacts</a> page.</p>
            <h2>3. What data we collect</h2>
            <h3>3.1. Enquiry form</h3>
            <ul>
            <li>Name</li>
            <li>Phone number</li>
            <li>Company name</li>
            <li>Message text</li>
            </ul>
            <h3>3.2. Quote link</h3>
            <p>A quote is sent to you through a personal link that cannot be guessed. The quote shows the company and contact details you provided. When the link is opened, only the fact and date of opening are recorded.</p>
            <h3>3.3. Website statistics</h3>
            <p>The website uses its own analytics: visitor IP addresses are not stored and no cookies are used. Only aggregated statistics (page views, number of visits) are collected.</p>
            <p>When enabled, Google Analytics 4 and Yandex Metrika are also used. These services use cookies and process data under their own privacy policies.</p>
            <h2>4. Purposes</h2>
            <ul>
            <li>Replying to enquiries and giving advice</li>
            <li>Preparing quotes and fulfilling orders</li>
            <li>Service and technical support</li>
            <li>Analysing and improving the website</li>
            </ul>
            <h2>5. Storage location and retention</h2>
            <p>Data collected through the website is stored on servers located in the Republic of Uzbekistan.</p>
            <h2>6. Data transfer</h2>
            <p>Notifications about enquiries are sent to our staff via the Telegram messenger. Data is shared with other third parties only where required by law.</p>
            <h2>7. Your rights</h2>
            <ul>
            <li>Access the data we hold about you</li>
            <li>Have your data clarified or corrected</li>
            <li>Request deletion of your data</li>
            <li>Withdraw consent to processing</li>
            </ul>
            <h2>8. Contact for requests</h2>
            <p>Send requests about personal data using the contact details or the enquiry form on the <a href="/en/page/contacts">Contacts</a> page.</p>
            <h2>9. Changes to this policy</h2>
            <p>This policy may be updated. The current version is published on this page with the date of the last update.</p>
            HTML,
        ],
        'faq' => [],
    ],

    // ─── Public offer (draft for a lawyer, hidden until reviewed) ───────────
    'offer' => [
        'template' => 'legal',
        'needs_review' => true,
        'title' => [
            'uz' => 'Ommaviy oferta',
            'ru' => 'Публичная оферта',
            'en' => 'Public offer',
        ],
        'meta_title' => [
            'uz' => 'Ommaviy oferta — Poson',
            'ru' => 'Публичная оферта — Poson',
            'en' => 'Public offer — Poson',
        ],
        'meta_description' => [
            'uz' => 'Poson ommaviy ofertasi: tovar va xizmatlarni buyurtma qilish, toʻlov, yetkazib berish, kafolat, tomonlarning javobgarligi va nizolarni hal qilish shartlari.',
            'ru' => 'Публичная оферта Poson: порядок заказа товаров и услуг, условия оплаты и доставки, гарантия, ответственность сторон и разрешение споров по договору.',
            'en' => 'Poson public offer: terms for ordering goods and services, payment, delivery, warranty, liability of the parties and dispute resolution procedure.',
        ],
        'body' => [
            'uz' => <<<'HTML'
            <p><strong>Qoralama — yurist tomonidan koʻrib chiqilishi kerak.</strong></p>
            <h2>1. Umumiy qoidalar</h2>
            <p>Ushbu hujjat [Sotuvchining toʻliq yuridik nomi] (keyingi oʻrinlarda — Sotuvchi, Poson) tomonidan Oʻzbekiston Respublikasi Fuqarolik kodeksiga muvofiq eʼlon qilingan ommaviy ofertadir. [Yurist: moddalarga havolalar]</p>
            <h2>2. Atamalar</h2>
            <ul>
            <li><strong>Sotuvchi</strong> — [toʻliq nomi]</li>
            <li><strong>Xaridor</strong> — ofertani qabul qilgan yuridik yoki jismoniy shaxs</li>
            <li><strong>Tovar</strong> — saytda taqdim etilgan uskunalar, sarf materiallari va dasturiy taʼminot</li>
            <li><strong>Xizmat</strong> — sozlash, integratsiya, taʼmirlash va boshqa xizmatlar</li>
            <li><strong>Tijorat taklifi</strong> — Sotuvchi tomonidan Xaridorga yuborilgan taklif</li>
            </ul>
            <h2>3. Shartnoma predmeti</h2>
            <p>[Yurist: tovar va xizmatlarni sotish shartlari]</p>
            <h2>4. Ofertani qabul qilish (aksept)</h2>
            <p>[Yurist: aksept usuli — buyurtmani tasdiqlash yoki toʻlov]</p>
            <h2>5. Narx va toʻlov</h2>
            <p>Narxlar soʻmda, tijorat taklifi yoki hisob-varaqda 12% QQS bilan koʻrsatiladi. Tijorat taklifining amal qilish muddati taklifning oʻzida koʻrsatiladi.</p>
            <p>Toʻlov usullari: bank orqali oʻtkazma (hisob-varaq asosida), naqd pul, Payme, Click, karta terminali, UzQR. [Yurist: toʻlov muddatlari]</p>
            <h2>6. Yetkazib berish va topshirish</h2>
            <p>Qoʻqon boʻylab yetkazib berish, Oʻzbekiston boʻylab kuryer orqali yetkazib berish yoki Qoʻqondagi ofisdan olib ketish. [Yurist: topshirish hujjatlari, mulk huquqi va tavakkalchilik oʻtishi]</p>
            <h2>7. Kafolat va servis</h2>
            <p>Tovarga ishlab chiqaruvchining rasmiy kafolati beriladi; muddati brend va modelga bogʻliq va tijorat taklifi hamda kafolat talonida koʻrsatiladi. [Yurist: kafolat shartlari]</p>
            <h2>8. Tovarni qaytarish va almashtirish</h2>
            <p>[Yurist: qonunchilikka muvofiq tartib]</p>
            <h2>9. Tomonlarning javobgarligi</h2>
            <p>[Yurist]</p>
            <h2>10. Fors-major</h2>
            <p>[Yurist]</p>
            <h2>11. Nizolarni hal qilish</h2>
            <p>Nizolar muzokaralar yoʻli bilan, kelishuvga erishilmasa — Oʻzbekiston Respublikasi qonunchiligiga muvofiq hal qilinadi. [Yurist: sudlovlilik]</p>
            <h2>12. Amal qilish muddati va oʻzgartirish</h2>
            <p>[Yurist]</p>
            <h2>13. Sotuvchi rekvizitlari</h2>
            <p>Sotuvchining rekvizitlari <a href="/uz/page/contacts">Kontaktlar</a> sahifasida koʻrsatilgan.</p>
            HTML,
            'ru' => <<<'HTML'
            <p><strong>Черновик — требует проверки юристом.</strong></p>
            <h2>1. Общие положения</h2>
            <p>Настоящий документ является публичной офертой [полное юридическое наименование Продавца] (далее — Продавец, Poson) в соответствии с Гражданским кодексом Республики Узбекистан. [Юристу: ссылки на статьи]</p>
            <h2>2. Термины</h2>
            <ul>
            <li><strong>Продавец</strong> — [полное наименование]</li>
            <li><strong>Покупатель</strong> — юридическое или физическое лицо, принявшее оферту</li>
            <li><strong>Товар</strong> — оборудование, расходные материалы и программное обеспечение, представленные на сайте</li>
            <li><strong>Услуга</strong> — настройка, интеграция, ремонт и другие услуги</li>
            <li><strong>Коммерческое предложение</strong> — предложение, направленное Продавцом Покупателю</li>
            </ul>
            <h2>3. Предмет договора</h2>
            <p>[Юристу: условия продажи товаров и оказания услуг]</p>
            <h2>4. Акцепт оферты</h2>
            <p>[Юристу: способ акцепта — подтверждение заказа или оплата]</p>
            <h2>5. Цена и оплата</h2>
            <p>Цены указываются в сумах с учётом НДС 12% в коммерческом предложении или счёте. Срок действия коммерческого предложения указывается в самом предложении.</p>
            <p>Способы оплаты: безналичное перечисление по счёту, наличные, Payme, Click, терминал для банковских карт, UzQR. [Юристу: сроки оплаты]</p>
            <h2>6. Доставка и передача товара</h2>
            <p>Доставка по Коканду, курьерская доставка по Узбекистану или самовывоз из офиса в Коканде. [Юристу: документы передачи, переход права собственности и рисков]</p>
            <h2>7. Гарантия и сервис</h2>
            <p>На товар действует официальная гарантия производителя; срок зависит от бренда и модели и указывается в коммерческом предложении и гарантийном талоне. [Юристу: условия гарантии]</p>
            <h2>8. Возврат и обмен товара</h2>
            <p>[Юристу: порядок в соответствии с законодательством]</p>
            <h2>9. Ответственность сторон</h2>
            <p>[Юристу]</p>
            <h2>10. Форс-мажор</h2>
            <p>[Юристу]</p>
            <h2>11. Разрешение споров</h2>
            <p>Споры решаются путём переговоров, а при недостижении согласия — в соответствии с законодательством Республики Узбекистан. [Юристу: подсудность]</p>
            <h2>12. Срок действия и изменение оферты</h2>
            <p>[Юристу]</p>
            <h2>13. Реквизиты Продавца</h2>
            <p>Реквизиты Продавца указаны на странице <a href="/ru/page/contacts">Контакты</a>.</p>
            HTML,
            'en' => <<<'HTML'
            <p><strong>Draft: to be reviewed by a lawyer.</strong></p>
            <h2>1. General provisions</h2>
            <p>This document is a public offer by [full legal name of the Seller] (the Seller, Poson) under the Civil Code of the Republic of Uzbekistan. [Lawyer: article references]</p>
            <h2>2. Definitions</h2>
            <ul>
            <li><strong>Seller</strong>: [full legal name]</li>
            <li><strong>Buyer</strong>: a legal entity or individual who has accepted the offer</li>
            <li><strong>Goods</strong>: equipment, consumables and software presented on the website</li>
            <li><strong>Services</strong>: setup, integration, repair and other services</li>
            <li><strong>Quote</strong>: a price proposal sent by the Seller to the Buyer</li>
            </ul>
            <h2>3. Subject of the contract</h2>
            <p>[Lawyer: terms for supplying goods and providing services]</p>
            <h2>4. Acceptance of the offer</h2>
            <p>[Lawyer: method of acceptance, such as order confirmation or payment]</p>
            <h2>5. Price and payment</h2>
            <p>Prices are stated in soum, including 12% VAT, in the quote or invoice. The validity period of a quote is stated in the quote itself.</p>
            <p>Payment methods: bank transfer against an invoice, cash, Payme, Click, card terminal, UzQR. [Lawyer: payment terms]</p>
            <h2>6. Delivery and handover</h2>
            <p>Delivery within Kokand, courier delivery across Uzbekistan, or pickup from the Kokand office. [Lawyer: handover documents, transfer of ownership and risk]</p>
            <h2>7. Warranty and service</h2>
            <p>Goods come with the official manufacturer warranty; its length depends on the brand and model and is stated in the quote and on the warranty card. [Lawyer: warranty terms]</p>
            <h2>8. Returns and exchanges</h2>
            <p>[Lawyer: procedure under applicable law]</p>
            <h2>9. Liability of the parties</h2>
            <p>[Lawyer]</p>
            <h2>10. Force majeure</h2>
            <p>[Lawyer]</p>
            <h2>11. Dispute resolution</h2>
            <p>Disputes are settled by negotiation and, if no agreement is reached, under the legislation of the Republic of Uzbekistan. [Lawyer: jurisdiction]</p>
            <h2>12. Term and changes to the offer</h2>
            <p>[Lawyer]</p>
            <h2>13. Seller details</h2>
            <p>The Seller company details are shown on the <a href="/en/page/contacts">Contacts</a> page.</p>
            HTML,
        ],
        'faq' => [],
    ],

];
