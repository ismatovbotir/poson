<?php

/**
 * First FAQ set for faq_items (Plan 04, §8, T13).
 *
 * scope "general" = site-wide FAQ (slug null); scope "category" = top-level
 * category slug from database/seeders/data/categories.php.
 * Answers are plain text, 2–4 sentences. No prices, no low-price wording.
 *
 * Facts follow docs/pm/notes/business-facts.md (confirmed 2026-10-21): Kokand
 * main office, 1C integration (no WMS projects), paid label templates.
 * Mark any new unconfirmed claim with 'confirm' => true.
 *
 * Uzbek is Latin script: oʻ/gʻ use ʻ (U+02BB), the tutuq belgisi uses ʼ (U+02BC).
 *
 * @return list<array{
 *     scope: 'general'|'category',
 *     slug: string|null,
 *     items: list<array{
 *         question: array{uz: string, ru: string, en: string},
 *         answer: array{uz: string, ru: string, en: string},
 *         confirm?: bool
 *     }>
 * }>
 */
return [

    // ─── General ────────────────────────────────────────────────────────────
    [
        'scope' => 'general',
        'slug' => null,
        'items' => [
            [
                'question' => [
                    'uz' => 'Uskunani qanday yetkazib berasiz?',
                    'ru' => 'Как вы доставляете оборудование?',
                    'en' => 'How do you deliver equipment?',
                ],
                'answer' => [
                    'uz' => 'Bosh ofisimiz Qoʻqon shahrida joylashgan. Qoʻqon boʻylab yetkazib berish bepul, Oʻzbekistonning boshqa hududlariga buyurtmani kuryer xizmati yetkazadi. Buyurtmani Qoʻqondagi ofisimizdan oʻzingiz olib ketishingiz ham mumkin, muddatni esa menejerimiz buyurtmani tasdiqlashda aytadi.',
                    'ru' => 'Наш главный офис находится в Коканде. Доставка по Коканду бесплатная, в другие регионы Узбекистана заказ доставляет курьерская служба. Заказ также можно забрать самостоятельно из нашего офиса в Коканде, а срок менеджер сообщит при подтверждении заказа.',
                    'en' => 'Our main office is in Kokand. Delivery within Kokand is free, and orders to other regions of Uzbekistan are delivered by courier. You can also collect your order from our Kokand office, and our manager confirms the timing when the order is confirmed.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Uskunaga kafolat beriladimi?',
                    'ru' => 'Есть ли гарантия на оборудование?',
                    'en' => 'Is the equipment covered by a warranty?',
                ],
                'answer' => [
                    'uz' => 'Ha, uskunaga ishlab chiqaruvchining rasmiy kafolati beriladi. Kafolat muddati brend va modelga bogʻliq, u tijorat taklifida va kafolat talonida koʻrsatiladi. Kafolatli taʼmirlash Posonning oʻz servis markazida bajariladi.',
                    'ru' => 'Да, на оборудование действует официальная гарантия производителя. Срок гарантии зависит от бренда и модели и указывается в коммерческом предложении и в гарантийном талоне. Гарантийный ремонт выполняет собственный сервисный центр Poson.',
                    'en' => 'Yes, equipment comes with the manufacturer\'s official warranty. The warranty period depends on the brand and model and is stated in the quote and on the warranty card. Warranty repairs are carried out in the Poson service centre.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Qurilma ishdan chiqsa nima qilish kerak?',
                    'ru' => 'Что делать, если устройство вышло из строя?',
                    'en' => 'What should I do if a device stops working?',
                ],
                'answer' => [
                    'uz' => 'Saytdagi shakl yoki telefon orqali biz bilan bogʻlaning va nosozlikni qisqacha tasvirlang. Servis markazimiz diagnostika oʻtkazadi va taʼmirlash boshlanishidan oldin narx hamda muddatni yozma ravishda kelishadi. Kerak boʻlsa, muhandisimiz ish joyingizga chiqadi; kafolatdan keyingi taʼmirlash va profilaktika ham bajariladi.',
                    'ru' => 'Свяжитесь с нами через форму на сайте или по телефону и кратко опишите неисправность. Наш сервисный центр проведёт диагностику и до начала ремонта письменно согласует с вами стоимость и сроки. При необходимости инженер выедет к вам; мы также выполняем постгарантийный ремонт и профилактику.',
                    'en' => 'Contact us through the form on the website or by phone and briefly describe the fault. Our service centre runs diagnostics and gives you a written estimate of cost and timeline before the repair starts. If needed, an engineer comes on site, and we also handle post-warranty repair and preventive maintenance.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Uskunani 1C ga ulab bera olasizmi?',
                    'ru' => 'Можете ли вы подключить оборудование к 1С?',
                    'en' => 'Can you connect equipment to 1C?',
                ],
                'answer' => [
                    'uz' => 'Ha, Poson skaner, yorliq printeri, TSD va dasturlarni 1C ga ulaydi va sozlaydi. Ish tarkibi 1C konfiguratsiyangizga bogʻliq, uni tijorat taklifida koʻrsatamiz. WMS joriy etish loyihalarini bajarmaymiz.',
                    'ru' => 'Да, Poson подключает к 1С и настраивает сканеры, принтеры этикеток, ТСД и программы. Состав работ зависит от вашей конфигурации 1С и указывается в коммерческом предложении. Проекты по внедрению WMS мы не выполняем.',
                    'en' => 'Yes, Poson connects scanners, label printers, mobile computers and software to 1C and configures them. The scope depends on your 1C configuration and is set out in the quote. We do not run WMS implementation projects.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Kompaniya hisob-faktura va bank oʻtkazmasi orqali xarid qila oladimi?',
                    'ru' => 'Может ли компания купить по счёту с оплатой по безналичному расчёту?',
                    'en' => 'Can a company buy on invoice and pay by bank transfer?',
                ],
                'answer' => [
                    'uz' => 'Ha. Saytda kompaniya rekvizitlari bilan tijorat taklifi soʻrovini yuboring, biz taklif va toʻlov uchun hisob tayyorlaymiz. Savatcha orqali buyurtmalarni Payme, Click yoki naqd pul bilan ham toʻlash mumkin.',
                    'ru' => 'Да. Отправьте на сайте запрос коммерческого предложения с реквизитами компании, и мы подготовим предложение и счёт на оплату. Заказы через корзину также можно оплатить через Payme, Click или наличными.',
                    'en' => 'Yes. Send a quote request on the website with your company details, and we will prepare a commercial offer and an invoice for payment. Cart orders can also be paid with Payme, Click or cash.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Qaysi tillarda yordam berasiz?',
                    'ru' => 'На каких языках вы консультируете?',
                    'en' => 'Which languages do you support?',
                ],
                'answer' => [
                    'uz' => 'Maslahat, texnik yordam va hujjatlar boʻyicha oʻzbek, rus va ingliz tillarida muloqot qilamiz. Sayt ham shu uch tilda ishlaydi. Soʻrov yuborishda sizga qulay tilni tanlang.',
                    'ru' => 'Консультируем, оказываем техническую поддержку и ведём переписку на узбекском, русском и английском языках. Сайт тоже работает на этих трёх языках. При запросе выберите удобный для вас язык.',
                    'en' => 'We provide consultations, technical support and correspondence in Uzbek, Russian and English. The website is available in the same three languages. Choose the language that suits you when you send a request.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Buyurtmani qanday berish mumkin?',
                    'ru' => 'Как оформить заказ?',
                    'en' => 'How do I place an order?',
                ],
                'answer' => [
                    'uz' => 'Sarf materiallari va ayrim uskunalarni saytdagi savatcha orqali buyurtma qilish mumkin. Uskuna, loyiha va xizmatlar uchun tijorat taklifi soʻrovini yuboring: menejer tafsilotlarni aniqlab, taklif tayyorlaydi. Qaysi yoʻl mosligini bilmasangiz, shunchaki murojaat qoldiring, sizga yoʻl koʻrsatamiz.',
                    'ru' => 'Расходные материалы и часть оборудования можно заказать через корзину на сайте. Для оборудования, проектов и услуг отправьте запрос коммерческого предложения: менеджер уточнит детали и подготовит предложение. Если не уверены, какой вариант подходит, просто оставьте заявку, и мы подскажем.',
                    'en' => 'Consumables and some equipment can be ordered through the cart on the website. For equipment, projects and services, send a quote request: a manager clarifies the details and prepares an offer. If you are not sure which option fits, just send an enquiry and we will guide you.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Qanday toʻlov usullari mavjud?',
                    'ru' => 'Какие способы оплаты доступны?',
                    'en' => 'Which payment methods do you accept?',
                ],
                'answer' => [
                    'uz' => 'Hisob-varaq asosida bank oʻtkazmasi, naqd pul, Payme va Click, terminal orqali bank kartasi hamda UzQR orqali toʻlash mumkin. Kompaniyalar uchun hisob-varaq rekvizitlaringiz asosida tayyorlanadi. Buyurtmangiz uchun qulay usulni menejer bilan aniqlashtiring.',
                    'ru' => 'Можно оплатить безналичным переводом по счёту, наличными, через Payme и Click, банковской картой через терминал или по UzQR. Для компаний счёт выставляется на ваши реквизиты. Удобный способ для вашего заказа уточните у менеджера.',
                    'en' => 'You can pay by bank transfer against an invoice, in cash, with Payme or Click, by bank card at a card terminal or with UzQR. Companies receive an invoice made out to their details. Check the most convenient method for your order with a manager.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Kafolat boʻyicha qanday murojaat qilinadi?',
                    'ru' => 'Как обратиться по гарантии?',
                    'en' => 'How do I make a warranty claim?',
                ],
                'answer' => [
                    'uz' => 'Saytdagi shakl yoki telefon orqali murojaat qiling va qurilma modeli, seriya raqami hamda nosozlikni qisqacha yozing. Kafolat taloni va xarid hujjatini tayyorlab qoʻying. Servis markazimiz diagnostika oʻtkazadi va holat kafolatga kirsa, taʼmirlashni ishlab chiqaruvchi shartlari asosida bajaradi.',
                    'ru' => 'Свяжитесь с нами через форму на сайте или по телефону, укажите модель, серийный номер и кратко опишите неисправность. Подготовьте гарантийный талон и документ о покупке. Наш сервисный центр проведёт диагностику и, если случай гарантийный, выполнит ремонт на условиях производителя.',
                    'en' => 'Contact us through the website form or by phone with the device model, serial number and a short description of the fault. Have the warranty card and proof of purchase ready. Our service centre runs diagnostics and, if the case is covered, carries out the repair under the terms of the manufacturer.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Masofadan yordam bera olasizmi?',
                    'ru' => 'Помогаете ли вы удалённо?',
                    'en' => 'Do you provide remote support?',
                ],
                'answer' => [
                    'uz' => 'Ha, sozlash va dasturlar bilan bogʻliq koʻp masalalarni masofadan hal qilamiz. Muammoni joyida tekshirish kerak boʻlsa, muhandisimiz ish joyingizga chiqadi. Yordam oʻzbek, rus va ingliz tillarida koʻrsatiladi.',
                    'ru' => 'Да, многие вопросы настройки и работы программ мы решаем удалённо. Если проблему нужно проверить на месте, инженер выезжает к вам. Поддержка доступна на узбекском, русском и английском языках.',
                    'en' => 'Yes, many setup and software issues are solved remotely. If a problem needs to be checked on site, an engineer comes to you. Support is available in Uzbek, Russian and English.',
                ],
            ],
            [
                'confirm' => true,
                'question' => [
                    'uz' => 'Kompaniyalar uchun QQS bilan yopuvchi hujjatlar berasizmi?',
                    'ru' => 'Предоставляете ли вы закрывающие документы с НДС?',
                    'en' => 'Do you provide VAT invoices and closing documents for companies?',
                ],
                'answer' => [
                    'uz' => 'Ha, kompaniyalar uchun hisob-varaq va yopuvchi hujjatlar rekvizitlaringiz asosida rasmiylashtiriladi. Tijorat takliflarida narxlar 12% QQS bilan koʻrsatiladi. Hujjatlar roʻyxati va ularni yuborish usulini menejer bilan kelishib oling.',
                    'ru' => 'Да, счёт и закрывающие документы для компаний оформляются по вашим реквизитам. В коммерческих предложениях цены указываются с НДС 12%. Перечень документов и способ их передачи согласуйте с менеджером.',
                    'en' => 'Yes, invoices and closing documents for companies are issued to your company details. Prices in our quotes include 12% VAT. Agree the list of documents and how they are delivered with your manager.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Xodimlarni oʻqitish xizmatga kiradimi?',
                    'ru' => 'Входит ли обучение сотрудников в услуги?',
                    'en' => 'Is staff training included?',
                ],
                'answer' => [
                    'uz' => 'Ha, uskuna yoki dasturni sozlash xizmatini buyurtma qilsangiz, xodimlarni oʻqitish unga kiradi. Muhandislarimiz kassirlar, omborchilar yoki operatorlarga kundalik ishni qurilmaning oʻzida koʻrsatib beradi. Keyinchalik savollar paydo boʻlsa, masofadan yordam beramiz.',
                    'ru' => 'Да, если вы заказываете настройку оборудования или программы, обучение сотрудников входит в неё. Инженеры показывают кассирам, кладовщикам или операторам повседневную работу прямо на устройстве. Если позже появятся вопросы, поможем удалённо.',
                    'en' => 'Yes, when you order setup of equipment or software, staff training is included. Our engineers show cashiers, storekeepers or operators the daily routine on the device itself. If questions come up later, we help remotely.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Xariddan oldin uskuna tanlash boʻyicha maslahat bera olasizmi?',
                    'ru' => 'Можно ли получить консультацию по выбору до покупки?',
                    'en' => 'Can you advise on equipment before I buy?',
                ],
                'answer' => [
                    'uz' => 'Ha, mutaxassislarimiz vazifangizga mos uskuna va sarf materiallarini tanlashga yordam beradi. Biznes turi, ish joylari soni, kunlik hajm va ishlatadigan dasturingiz, masalan 1C konfiguratsiyasi haqida ayting. Shu asosda mos variantlarni tijorat taklifida koʻrsatamiz.',
                    'ru' => 'Да, наши специалисты помогут подобрать оборудование и расходные материалы под вашу задачу. Расскажите о формате бизнеса, количестве рабочих мест, дневном объёме и программе, которую вы используете, например о конфигурации 1С. На этой основе мы предложим варианты в коммерческом предложении.',
                    'en' => 'Yes, our specialists help you choose equipment and consumables for your task. Tell us about your business type, number of workstations, daily volume and the software you use, for example your 1C configuration. Based on this, we propose suitable options in a quote.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Uskuna yoki dastur narxiga sozlash kiradimi?',
                    'ru' => 'Входит ли настройка в стоимость оборудования или программы?',
                    'en' => 'Is setup included in the price of equipment or software?',
                ],
                'answer' => [
                    'uz' => 'Yoʻq, sozlash ixtiyoriy alohida xizmat: uskunani sozlashsiz ham xarid qilishingiz mumkin. Sozlash kerak boʻlsa, ish tarkibi va narxi tijorat taklifida alohida koʻrsatiladi. Sozlash buyurtma qilinganda xodimlarni oʻqitish ham unga kiradi.',
                    'ru' => 'Нет, настройка — отдельная дополнительная услуга: оборудование можно купить и без неё. Если настройка нужна, состав работ и стоимость указываются в коммерческом предложении отдельно. При заказе настройки обучение сотрудников входит в неё.',
                    'en' => 'No, setup is a separate optional service, and you can buy equipment without it. If you need setup, the scope and price are listed separately in the quote. When you order setup, staff training is included.',
                ],
            ],
        ],
    ],

    // ─── Barcode scanners ───────────────────────────────────────────────────
    [
        'scope' => 'category',
        'slug' => 'scanners',
        'items' => [
            [
                'question' => [
                    'uz' => '1D yoki 2D skaner: qaysi biri kerak?',
                    'ru' => '1D или 2D-сканер: какой выбрать?',
                    'en' => '1D or 2D scanner: which do I need?',
                ],
                'answer' => [
                    'uz' => '1D skaner EAN-13 yoki Code 128 kabi chiziqli shtrix-kodlarni oʻqiydi. 2D skaner ular bilan birga QR va DataMatrix kodlarini, shuningdek telefon ekranidagi kodlarni ham oʻqiydi. Markirovkadagi DataMatrix kodlari bilan ishlasangiz, 2D skaner tanlang.',
                    'ru' => '1D-сканер читает линейные штрих-коды, например EAN-13 или Code 128. 2D-сканер читает их же, а также QR и DataMatrix, в том числе с экрана телефона. Если вы работаете с кодами маркировки DataMatrix, выбирайте 2D-сканер.',
                    'en' => 'A 1D scanner reads linear barcodes such as EAN-13 or Code 128. A 2D scanner reads those as well as QR and DataMatrix codes, including codes on phone screens. If you work with DataMatrix product marking codes, choose a 2D scanner.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Simli yoki simsiz skaner tanlash kerakmi?',
                    'ru' => 'Проводной или беспроводной сканер?',
                    'en' => 'Corded or wireless scanner?',
                ],
                'answer' => [
                    'uz' => 'Simli skaner doimiy kassa joyi uchun qulay: zaryadlash talab qilinmaydi. Simsiz skaner Bluetooth yoki baza orqali ishlaydi va omborda, savdo zalida yoki yirik tovarlarni qabul qilishda erkin harakatlanish imkonini beradi. Ishlash masofasi modelga bogʻliq, uni mahsulot xususiyatlarida tekshiring.',
                    'ru' => 'Проводной сканер удобен для постоянного кассового места: его не нужно заряжать. Беспроводной работает через Bluetooth или базу и даёт свободу движения на складе, в торговом зале и при приёмке крупного товара. Дальность связи зависит от модели, её указываем в характеристиках.',
                    'en' => 'A corded scanner suits a fixed checkout because it never needs charging. A wireless scanner works over Bluetooth or through a cradle and lets staff move freely around the warehouse, shop floor or bulky goods at receiving. Range depends on the model and is listed in the specifications.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Skaner mening 1C bazam bilan ishlaydimi?',
                    'ru' => 'Будет ли сканер работать с моей 1С?',
                    'en' => 'Will the scanner work with my 1C?',
                ],
                'answer' => [
                    'uz' => 'Ha. Koʻpchilik skanerlar USB orqali klaviatura emulyatsiyasi rejimida ulanadi, shuning uchun matn kiritiladigan istalgan dastur bilan ishlaydi. Poson muhandislari skanerni 1C ga ulaydi, prefiks va suffikslarni sozlaydi hamda ishlashini tekshiradi.',
                    'ru' => 'Да. Большинство сканеров подключаются по USB в режиме эмуляции клавиатуры и поэтому работают с любой программой, куда вводится текст. Инженеры Poson подключат сканер к 1С, настроят префиксы и суффиксы и проверят работу.',
                    'en' => 'Yes. Most scanners connect over USB in keyboard emulation mode, so they work with any program that accepts typed input. Poson engineers connect the scanner to 1C, set prefixes and suffixes and test it.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Omborda ishlash uchun skaner qanchalik mustahkam boʻlishi kerak?',
                    'ru' => 'Насколько защищённым должен быть сканер для склада?',
                    'en' => 'How rugged should a warehouse scanner be?',
                ],
                'answer' => [
                    'uz' => 'IP himoya darajasi va tushishga chidamlilik (metrda) koʻrsatkichlariga eʼtibor bering. IP rating qancha yuqori boʻlsa, qurilma chang va namlikdan shuncha yaxshi himoyalangan. Chang, sovuq yoki tez-tez tushirib yuboriladigan sharoit uchun mustahkamlangan modelni tanlashga yordam beramiz.',
                    'ru' => 'Обратите внимание на степень защиты IP и устойчивость к падениям в метрах. Чем выше IP, тем лучше устройство защищено от пыли и влаги. Для пыльных, холодных помещений и работы с частыми падениями поможем подобрать защищённую модель.',
                    'en' => 'Look at the IP rating and the drop specification in metres. The higher the IP rating, the better the device is protected against dust and moisture. For dusty or cold areas, or where scanners get dropped often, we help you choose a rugged model.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Skaner telefon ekranidagi kodni oʻqiy oladimi?',
                    'ru' => 'Читает ли сканер коды с экрана телефона?',
                    'en' => 'Can a scanner read codes from a phone screen?',
                ],
                'answer' => [
                    'uz' => '2D imidjerli skanerlar telefon va planshet ekranidagi QR hamda shtrix-kodlarni oʻqiydi. Lazerli skanerlar ekrandan oʻqiy olmaydi, chunki ekran lazer nurini qogʻozdek qaytarmaydi. Xaridorlardan elektron kupon yoki QR kod qabul qilsangiz, 2D modelni tanlang.',
                    'ru' => 'Сканеры с 2D-имиджером читают QR и штрих-коды с экранов телефонов и планшетов. Лазерные сканеры с экрана не читают, потому что экран отражает луч лазера иначе, чем бумага. Если вы принимаете от покупателей электронные купоны или QR-коды, выбирайте 2D-модель.',
                    'en' => 'Scanners with a 2D imager read QR codes and barcodes from phone and tablet screens. Laser scanners cannot read from screens, because a screen does not reflect the laser beam the way paper does. If customers show you e-coupons or QR codes, choose a 2D model.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Kassa uchun qoʻl skaneri yaxshimi yoki statsionar skanermi?',
                    'ru' => 'Для кассы лучше ручной или стационарный сканер?',
                    'en' => 'Handheld or presentation scanner for the checkout?',
                ],
                'answer' => [
                    'uz' => 'Statsionar skaner peshtaxtada turadi va kassir tovarni uning oldidan oʻtkazadi, bu gavjum kassalarda ishni tezlashtiradi. Qoʻl skaneri yirik va ogʻir tovarlar uchun qulayroq. Koʻpgina qoʻl skanerlari stendga qoʻyilganda avtomatik oʻqish rejimiga oʻtadi, shuning uchun ikkala usulni birlashtirish mumkin.',
                    'ru' => 'Стационарный сканер стоит на прилавке, и кассир просто проносит товар перед ним, это ускоряет работу загруженных касс. Ручной сканер удобнее для крупного и тяжёлого товара. Многие ручные сканеры на подставке переходят в режим автоматического считывания, поэтому оба способа можно совместить.',
                    'en' => 'A presentation scanner sits on the counter and the cashier simply passes items in front of it, which speeds up busy checkouts. A handheld scanner is handier for large and heavy items. Many handheld scanners switch to automatic reading when placed in a stand, so you can combine both.',
                ],
            ],
        ],
    ],

    // ─── Label printers ─────────────────────────────────────────────────────
    [
        'scope' => 'category',
        'slug' => 'label-printers',
        'items' => [
            [
                'question' => [
                    'uz' => 'Termo yoki termotransfer chop etish: farqi nimada?',
                    'ru' => 'Прямая термопечать или термотрансфер: в чём разница?',
                    'en' => 'Direct thermal or thermal transfer: what is the difference?',
                ],
                'answer' => [
                    'uz' => 'Termo usulida printer issiqlikka sezgir yorliqqa ribbonsiz chop etadi, bu tarozi, logistika va qisqa muddatli belgilash uchun mos. Termotransfer usulida tasvir ribbon orqali tushiriladi va uzoq saqlanadi, namlik, ishqalanish va yorugʻlikka chidamli boʻladi. Koʻpgina termotransfer printerlar ikkala rejimda ham ishlay oladi.',
                    'ru' => 'При прямой термопечати принтер печатает на термочувствительной этикетке без риббона, это подходит для весов, логистики и маркировки с коротким сроком хранения. При термотрансфере изображение переносится через риббон и долго сохраняется, выдерживая влагу, истирание и свет. Многие термотрансферные принтеры умеют работать в обоих режимах.',
                    'en' => 'Direct thermal printers print on heat-sensitive labels without a ribbon, which suits scales, shipping and short-life labels. Thermal transfer prints through a ribbon, so the image lasts longer and resists moisture, abrasion and light. Many thermal transfer printers can also run in direct thermal mode.',
                ],
            ],
            [
                'question' => [
                    'uz' => '203, 300 yoki 600 dpi: qaysi oʻlcham kerak?',
                    'ru' => '203, 300 или 600 dpi: какое разрешение нужно?',
                    'en' => '203, 300 or 600 dpi: which resolution do I need?',
                ],
                'answer' => [
                    'uz' => '203 dpi javon, tovar va joʻnatma yorliqlarining koʻpchiligi uchun yetarli. 300 dpi kichik yorliqlar, mayda matn va kichik DataMatrix kodlari uchun kerak. 600 dpi elektronika va mayda detallarni belgilashda ishlatiladi.',
                    'ru' => '203 dpi достаточно для большинства полочных, товарных и транспортных этикеток. 300 dpi нужно для маленьких этикеток, мелкого текста и небольших кодов DataMatrix. 600 dpi применяют для маркировки электроники и мелких деталей.',
                    'en' => '203 dpi is enough for most shelf, product and shipping labels. 300 dpi is needed for small labels, fine text and small DataMatrix codes. 600 dpi is used for marking electronics and small parts.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Stol usti yoki sanoat printeri tanlash kerakmi?',
                    'ru' => 'Настольный или промышленный принтер?',
                    'en' => 'Desktop or industrial printer?',
                ],
                'answer' => [
                    'uz' => 'Stol usti printer doʻkon, dorixona va kichik ombordagi oʻrtacha hajm uchun mos. Sanoat printeri metall korpusga ega, katta rulon va uzun ribbonni sigʻdiradi hamda ishlab chiqarish yoki taqsimlash markazida uzluksiz ishlaydi. Kunlik yorliqlar soni va ish muhitini ayting, mos modelni tavsiya qilamiz.',
                    'ru' => 'Настольный принтер подходит для умеренных объёмов в магазине, аптеке и на небольшом складе. Промышленный принтер в металлическом корпусе вмещает большие рулоны и длинные риббоны и рассчитан на непрерывную работу на производстве и в распределительных центрах. Назовите дневной объём этикеток и условия работы, и мы порекомендуем модель.',
                    'en' => 'A desktop printer suits moderate volumes in shops, pharmacies and small warehouses. An industrial printer has a metal frame, holds larger rolls and longer ribbons and is built for continuous duty on production lines and in distribution centres. Tell us your daily label volume and environment and we will recommend a model.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Yorliq shablonlarini tayyorlab, printerni 1C ga ulaysizmi?',
                    'ru' => 'Вы подготовите шаблоны этикеток и подключите принтер к 1С?',
                    'en' => 'Will you prepare label templates and connect the printer to 1C?',
                ],
                'answer' => [
                    'uz' => 'Ha. Poson muhandislari printerni oʻrnatadi, kalibrlaydi va 1C ga ulaydi. Yorliq shablonlarini tayyorlash alohida pullik xizmat: narx soʻrov boʻyicha tijorat taklifida koʻrsatiladi.',
                    'ru' => 'Да. Инженеры Poson установят и откалибруют принтер и подключат его к 1С. Подготовка шаблонов этикеток — отдельная платная услуга: цена по запросу, её указываем в коммерческом предложении.',
                    'en' => 'Yes. Poson engineers install and calibrate the printer and connect it to 1C. Label template design is a separate paid service: the price is on request and stated in the quote.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Yorliq printeri qanday interfeyslar orqali ulanadi?',
                    'ru' => 'Через какие интерфейсы подключается принтер этикеток?',
                    'en' => 'Which interfaces do label printers use?',
                ],
                'answer' => [
                    'uz' => 'Deyarli barcha modellarda USB bor, koʻpchiligida Ethernet, ayrimlarida esa Wi-Fi, Bluetooth yoki ketma-ket port (RS232) ham mavjud. Tarmoq interfeysi bir printerdan bir nechta ish joyi foydalanishi kerak boʻlganda qulay. Printer qanday ishlatilishini ayting, mos interfeysli modelni tanlaymiz.',
                    'ru' => 'USB есть почти у всех моделей, у многих также Ethernet, у некоторых Wi-Fi, Bluetooth или последовательный порт (RS232). Сетевой интерфейс удобен, когда одним принтером пользуются несколько рабочих мест. Расскажите, как будет работать принтер, и мы подберём модель с нужным интерфейсом.',
                    'en' => 'Almost every model has USB, many also have Ethernet, and some offer Wi-Fi, Bluetooth or a serial (RS232) port. A network interface is convenient when several workstations share one printer. Tell us how the printer will be used and we will pick a model with the right interface.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Chop etish kallagini qanchalik tez-tez tozalash kerak?',
                    'ru' => 'Как часто чистить печатающую головку?',
                    'en' => 'How often should the printhead be cleaned?',
                ],
                'answer' => [
                    'uz' => 'Ishlab chiqaruvchilar odatda termotransfer rejimida har bir ribbon almashtirilganda, termo rejimida esa har bir yangi yorliq ruloni oʻrnatilganda tozalashni tavsiya qiladi. Buning uchun izopropil spirti va maxsus qalam yoki tuksiz salfetka ishlatiladi. Tozalash yordam bermasa, servis markazimizda diagnostika oʻtkazamiz.',
                    'ru' => 'Обычно производители рекомендуют чистить головку при каждой замене риббона в термотрансферном режиме и при каждом новом рулоне этикеток в режиме прямой термопечати. Для этого используют изопропиловый спирт и чистящий карандаш или безворсовую салфетку. Если чистка не помогает, проведём диагностику в нашем сервисном центре.',
                    'en' => 'Manufacturers usually recommend cleaning at every ribbon change in thermal transfer mode and with every new roll of labels in direct thermal mode. Use isopropyl alcohol with a cleaning pen or a lint-free wipe. If cleaning does not help, our service centre can run diagnostics.',
                ],
            ],
        ],
    ],

    // ─── Mobile computers (TSD) ─────────────────────────────────────────────
    [
        'scope' => 'category',
        'slug' => 'mobile-computers',
        'items' => [
            [
                'question' => [
                    'uz' => 'Android TSD yoki Windows TSD: qaysi birini tanlash kerak?',
                    'ru' => 'ТСД на Android или на Windows: что выбрать?',
                    'en' => 'Android or Windows mobile computer: which should I choose?',
                ],
                'answer' => [
                    'uz' => 'Hozirgi TSD modellarining aksariyati Android asosida ishlaydi, Microsoft esa Windows CE va Windows Embedded Handheld tizimlarini qoʻllab-quvvatlashni toʻxtatgan. Android uchun ombor ilovalari tanlovi ham kengroq. Agar dasturingiz eski Windows qurilmalar uchun yozilgan boʻlsa, Androidga oʻtish yoʻlini baholashga yordam beramiz.',
                    'ru' => 'Большинство современных ТСД работают на Android, а Microsoft прекратила поддержку Windows CE и Windows Embedded Handheld. Для Android доступно и больше складских приложений. Если ваша программа написана под старые Windows-устройства, поможем оценить переход на Android.',
                    'en' => 'Most current mobile computers run Android, and Microsoft has ended support for Windows CE and Windows Embedded Handheld. Android also has a wider choice of warehouse apps. If your software was written for older Windows devices, we help you assess the move to Android.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'TSD mening 1C bazam bilan ishlaydimi?',
                    'ru' => 'Будет ли ТСД работать с моей 1С?',
                    'en' => 'Will the mobile computer work with my 1C?',
                ],
                'answer' => [
                    'uz' => 'Ha, Poson TSDni 1C ga ulaydi. Muhandislarimiz mobil ilovani oʻrnatadi, 1C bilan maʼlumot almashinuvini sozlaydi va tovar qabul qilish yoki inventarizatsiya kabi jarayonlarni sinab koʻradi. Ish tarkibi 1C konfiguratsiyangizga bogʻliq va tijorat taklifida koʻrsatiladi.',
                    'ru' => 'Да, Poson подключает ТСД к 1С. Наши инженеры установят мобильное приложение, настроят обмен данными с 1С и проверят процессы, например приёмку или инвентаризацию. Состав работ зависит от вашей конфигурации 1С и указывается в коммерческом предложении.',
                    'en' => 'Yes, Poson connects mobile computers to 1C. Our engineers install the mobile app, set up data exchange with 1C and test processes such as receiving or stocktaking. The scope depends on your 1C configuration and is set out in the quote.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'TSD uchun qanday himoya darajasi kerak?',
                    'ru' => 'Какая степень защиты нужна ТСД?',
                    'en' => 'What protection rating does a mobile computer need?',
                ],
                'answer' => [
                    'uz' => 'Ombor va logistika uchun IP65 yoki IP67 himoyali va tushishga chidamli modellar mos keladi. Sovuq omborlar uchun ish harorati oraligʻini ham tekshiring. Savdo zalida ishlash uchun yengilroq korpusli modellar ham yetarli boʻlishi mumkin.',
                    'ru' => 'Для склада и логистики подходят модели с защитой IP65 или IP67 и устойчивостью к падениям. Для холодных складов проверьте также диапазон рабочих температур. Для работы в торговом зале может хватить моделей в более лёгком корпусе.',
                    'en' => 'For warehouses and logistics, choose models rated IP65 or IP67 with a solid drop specification. For cold stores, also check the operating temperature range. For shop-floor work, a lighter housing may be enough.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'TSDni ish joyimizda sozlab bera olasizmi?',
                    'ru' => 'Можете ли вы настроить ТСД у нас на месте?',
                    'en' => 'Can you set up mobile computers at our site?',
                ],
                'answer' => [
                    'uz' => 'Ha, Poson muhandislari ish joyingizga chiqadi. Ular terminallarni Wi-Fi tarmogʻingizga ulaydi, ilovani oʻrnatadi, 1C bilan almashinuvni sozlaydi va xodimlarni joyida oʻqitadi. Tashrif sanasi va ish tarkibi tijorat taklifida kelishiladi.',
                    'ru' => 'Да, инженеры Poson выезжают к вам. Они подключат терминалы к вашей сети Wi-Fi, установят приложение, настроят обмен с 1С и обучат сотрудников на месте. Дату выезда и состав работ согласуем в коммерческом предложении.',
                    'en' => 'Yes, Poson engineers come to your site. They connect the devices to your Wi-Fi network, install the app, set up data exchange with 1C and train your staff on the spot. The visit date and scope are agreed in the quote.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'TSD akkumulyatori butun smenaga yetadimi?',
                    'ru' => 'Хватит ли аккумулятора ТСД на всю смену?',
                    'en' => 'Will the battery last a full shift?',
                ],
                'answer' => [
                    'uz' => 'Bu akkumulyator sigʻimi (mAh), skanerlash intensivligi, ekran yorqinligi va haroratga bogʻliq. Koʻp smenali ish uchun olinadigan akkumulyatorli, yaxshisi qurilmani oʻchirmasdan almashtirish (hot-swap) imkoniyati bor modellarni tanlang. Zaxira akkumulyatorlar va zaryadlash stansiyalarini oldindan rejalashtirishga yordam beramiz.',
                    'ru' => 'Это зависит от ёмкости аккумулятора (мАч), интенсивности сканирования, яркости экрана и температуры. Для многосменной работы выбирайте модели со съёмным аккумулятором, лучше с горячей заменой без выключения устройства. Поможем заранее рассчитать запасные аккумуляторы и зарядные станции.',
                    'en' => 'It depends on battery capacity (mAh), scanning intensity, screen brightness and temperature. For multi-shift work, choose models with a removable battery, ideally with hot-swap so the device stays on during the change. We help you plan spare batteries and charging cradles in advance.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'TSD orqali joyida yorliq chop etsa boʻladimi?',
                    'ru' => 'Можно ли печатать этикетки прямо с ТСД?',
                    'en' => 'Can I print labels directly from a mobile computer?',
                ],
                'answer' => [
                    'uz' => 'Ha, buning uchun TSD mobil yorliq printeriga Bluetooth yoki Wi-Fi orqali ulanadi. Bu javon yorliqlarini almashtirish, omborda qayta belgilash va yetkazib berishda qulay. Poson printer va TSDni bir-biriga ulab, ilovangizdan chop etishni sozlaydi.',
                    'ru' => 'Да, для этого ТСД подключается к мобильному принтеру этикеток по Bluetooth или Wi-Fi. Это удобно при замене ценников, перемаркировке на складе и при доставке. Poson свяжет принтер с ТСД и настроит печать из вашего приложения.',
                    'en' => 'Yes, the mobile computer connects to a mobile label printer over Bluetooth or Wi-Fi. This is handy for replacing shelf labels, relabelling in the warehouse and on delivery routes. Poson pairs the printer with the mobile computer and sets up printing from your app.',
                ],
            ],
        ],
    ],

    // ─── Labels and ribbons ─────────────────────────────────────────────────
    [
        'scope' => 'category',
        'slug' => 'labels-ribbons',
        'items' => [
            [
                'question' => [
                    'uz' => 'Qaysi yorliq uchun qaysi ribbon kerak?',
                    'ru' => 'Какой риббон нужен для какой этикетки?',
                    'en' => 'Which ribbon goes with which label?',
                ],
                'answer' => [
                    'uz' => 'Wax ribbon qogʻoz yorliqlar uchun mos va koʻpgina umumiy vazifalarni hal qiladi. Wax-resin qoplamali qogʻoz va ayrim sintetik yorliqlarda ishqalanishga chidamliroq tasvir beradi. Resin ribbon polipropilen, poliester kabi sintetik materiallar uchun kerak, u kimyoviy taʼsir va issiqlikka chidamli.',
                    'ru' => 'Риббон wax подходит для бумажных этикеток и решает большинство повседневных задач. Wax-resin даёт более стойкое к истиранию изображение на мелованной бумаге и части синтетики. Resin нужен для синтетических материалов, таких как полипропилен и полиэстер, и выдерживает химию и нагрев.',
                    'en' => 'Wax ribbons suit paper labels and cover most everyday jobs. Wax-resin gives a more abrasion-resistant print on coated paper and some synthetics. Resin is for synthetic materials such as polypropylene and polyester and withstands chemicals and heat.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Ribbon IN yoki OUT: qanday aniqlash mumkin?',
                    'ru' => 'Риббон IN или OUT: как определить?',
                    'en' => 'Ribbon IN or OUT: how do I tell?',
                ],
                'answer' => [
                    'uz' => 'IN yoki OUT boʻyoq qatlami rulonning ichki yoki tashqi tomonida joylashganini bildiradi. Kerakli turi printer modeliga bogʻliq va printer qoʻllanmasida koʻrsatilgan. Printer modelini ayting, mos ribbonni tanlab beramiz.',
                    'ru' => 'IN или OUT означает, с какой стороны рулона находится красящий слой: внутри или снаружи. Нужный тип зависит от модели принтера и указан в его руководстве. Назовите модель принтера, и мы подберём подходящий риббон.',
                    'en' => 'IN or OUT tells you whether the ink side faces the inside or the outside of the roll. The type you need depends on the printer model and is given in its manual. Tell us your printer model and we will pick the matching ribbon.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Yorliq oʻlchami va gilza diametrini qanday tanlash kerak?',
                    'ru' => 'Как выбрать размер этикетки и диаметр втулки?',
                    'en' => 'How do I choose label size and core diameter?',
                ],
                'answer' => [
                    'uz' => 'Yorliq kengligi printerning chop etish kengligidan oshmasligi, rulonning tashqi diametri esa printer sigʻdira oladigan maksimal oʻlchamdan katta boʻlmasligi kerak. Gilza diametri printer turiga bogʻliq: stol usti printerlarda odatda kichikroq, sanoat printerlarida kattaroq gilza ishlatiladi. Printer modeli va yorliqda nima chop etilishini ayting, mos variantni tanlaymiz.',
                    'ru' => 'Ширина этикетки не должна превышать ширину печати принтера, а внешний диаметр рулона — максимальный диаметр, который вмещает принтер. Диаметр втулки зависит от типа принтера: в настольных обычно используют втулку меньше, в промышленных — больше. Назовите модель принтера и что печатается на этикетке, и мы подберём вариант.',
                    'en' => 'The label width must not exceed the printer print width, and the roll outer diameter must not exceed the largest roll the printer can hold. Core diameter depends on the printer type: desktop printers usually take smaller cores and industrial printers larger ones. Tell us the printer model and what goes on the label, and we will suggest the right option.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Katalogda yoʻq oʻlchamdagi yorliqlarni buyurtma qilsa boʻladimi?',
                    'ru' => 'Можно ли заказать этикетки нестандартного размера?',
                    'en' => 'Can I order labels in a size that is not in the catalogue?',
                ],
                'answer' => [
                    'uz' => 'Ha, katalogda yoʻq oʻlchamdagi yorliqlar buyurtma asosida minimal partiya bilan tayyorlanadi. Tijorat taklifi soʻrovida oʻlcham, material, gilza va rulondagi yorliqlar sonini koʻrsating. Minimal partiya va tayyorlash muddatini taklifda tasdiqlaymiz.',
                    'ru' => 'Да, этикетки нестандартного размера изготавливаются под заказ с минимальной партией. В запросе коммерческого предложения укажите размер, материал, втулку и количество этикеток в рулоне. Минимальную партию и срок изготовления подтвердим в предложении.',
                    'en' => 'Yes, labels in non-catalogue sizes are made to order with a minimum batch. In your quote request, give the size, material, core and number of labels per roll. We confirm the minimum batch and lead time in the quote.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Termoyorliqlarni qanday saqlash kerak?',
                    'ru' => 'Как хранить термоэтикетки?',
                    'en' => 'How should direct thermal labels be stored?',
                ],
                'answer' => [
                    'uz' => 'Termoyorliqlar issiqlik, quyosh nuri, namlik, yogʻ va ayrim kimyoviy moddalar taʼsirida qorayadi yoki xiralashadi. Rulonlarni qadoqda, quruq, xona haroratidagi va toʻgʻridan-toʻgʻri yorugʻlik tushmaydigan joyda saqlang. Uzoq saqlanadigan yoki ogʻir sharoitda ishlatiladigan yorliqlar uchun Top qatlamli termoyorliq yoki termotransfer yorliqni tanlang.',
                    'ru' => 'Термоэтикетки темнеют или выцветают под действием тепла, солнечного света, влаги, жиров и некоторых химических веществ. Храните рулоны в упаковке, в сухом месте при комнатной температуре и без прямого света. Для долгого хранения или жёстких условий выбирайте термоэтикетки с защитным слоем Top или термотрансферные этикетки.',
                    'en' => 'Direct thermal labels darken or fade when exposed to heat, sunlight, moisture, grease and certain chemicals. Store rolls in their packaging in a dry place at room temperature, away from direct light. For long storage or harsh conditions, choose top-coated thermal labels or thermal transfer labels.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Qaysi chek lentasi printerimga mos keladi?',
                    'ru' => 'Какая чековая лента подойдёт к моему принтеру?',
                    'en' => 'Which receipt paper fits my printer?',
                ],
                'answer' => [
                    'uz' => 'Uchta koʻrsatkichni tekshiring: kenglik (odatda 58 yoki 80 mm), printer sigʻdira oladigan rulonning eng katta tashqi diametri va gilza diametri. Ular printer qoʻllanmasida koʻrsatilgan. Printer modelini ayting, mos rulonni tanlab, muntazam yetkazib berishni tashkil qilamiz.',
                    'ru' => 'Проверьте три параметра: ширину (обычно 58 или 80 мм), наибольший внешний диаметр рулона, который вмещает принтер, и диаметр втулки. Они указаны в руководстве к принтеру. Назовите модель принтера, и мы подберём рулон и организуем регулярные поставки.',
                    'en' => 'Check three parameters: width (usually 58 or 80 mm), the largest roll outer diameter the printer can hold, and the core diameter. They are listed in the printer manual. Tell us your printer model and we will pick the right roll and arrange regular deliveries.',
                ],
            ],
        ],
    ],

    // ─── RFID ───────────────────────────────────────────────────────────────
    [
        'scope' => 'category',
        'slug' => 'rfid',
        'items' => [
            [
                'question' => [
                    'uz' => 'UHF RFID metkani qancha masofadan oʻqish mumkin?',
                    'ru' => 'С какого расстояния читается UHF RFID-метка?',
                    'en' => 'What is the read range of UHF RFID?',
                ],
                'answer' => [
                    'uz' => 'Oʻqish masofasi oʻquvchi quvvati, antenna, metka oʻlchami va u yopishtirilgan materialga bogʻliq: bir necha santimetrdan bir necha metrgacha. Metall va suyuqliklar oʻqishni yomonlashtiradi. Aniq natijani olish uchun metkalarni oʻz tovarlaringizda pilot loyihada sinab koʻrishni tavsiya qilamiz.',
                    'ru' => 'Дальность зависит от мощности считывателя, антенны, размера метки и материала, на который она нанесена: от нескольких сантиметров до нескольких метров. Металл и жидкости ухудшают считывание. Чтобы получить точный результат, рекомендуем проверить метки на ваших товарах в пилотном проекте.',
                    'en' => 'Read range depends on reader power, the antenna, tag size and the material the tag sits on, and runs from a few centimetres to several metres. Metal and liquids reduce performance. For a reliable answer, we recommend testing tags on your own items in a pilot.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'UHF yoki HF RFID: farqi nimada?',
                    'ru' => 'UHF или HF RFID: в чём разница?',
                    'en' => 'UHF or HF RFID: what is the difference?',
                ],
                'answer' => [
                    'uz' => 'UHF (860–960 MHz) uzoqroq masofada koʻplab metkalarni bir vaqtda oʻqiydi, shuning uchun inventarizatsiya, logistika va kiyim savdosida ishlatiladi. HF (13,56 MHz) qisqa masofada ishlaydi va kirish kartalari, kutubxonalar hamda NFC uchun mos. Vazifangizga qarab chastotani tanlashga yordam beramiz.',
                    'ru' => 'UHF (860–960 МГц) читает много меток одновременно на большем расстоянии, поэтому его используют для инвентаризации, логистики и в магазинах одежды. HF (13,56 МГц) работает на коротком расстоянии и подходит для карт доступа, библиотек и NFC. Поможем выбрать частоту под вашу задачу.',
                    'en' => 'UHF (860–960 MHz) reads many tags at once over a longer distance, so it is used for stocktaking, logistics and apparel retail. HF (13.56 MHz) works at short range and suits access cards, libraries and NFC. We help you choose the frequency for your task.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Metall yoki suyuqlikli tovarlarga RFID metka yopishtirsa boʻladimi?',
                    'ru' => 'Можно ли клеить RFID-метки на металл или товары с жидкостью?',
                    'en' => 'Can RFID tags be used on metal or liquid-filled items?',
                ],
                'answer' => [
                    'uz' => 'Oddiy metka metall yuzada yomon oʻqiladi, buning uchun maxsus on-metal metkalar ishlatiladi. Suyuqlikli tovarlarda ham metka turi va joylashuvini sinov orqali tanlash kerak. Poson obyekt materialiga mos metkani tanlashga yordam beradi va sinov namunalarini taqdim etadi.',
                    'ru' => 'Обычная метка плохо читается на металле, для этого используют специальные on-metal метки. Для товаров с жидкостью тип метки и место её размещения тоже подбирают по результатам теста. Poson поможет подобрать метку под материал и предоставит тестовые образцы.',
                    'en' => 'A standard tag reads poorly on metal, so special on-metal tags are used. For liquid-filled items, the tag type and placement should also be chosen through testing. Poson helps choose a tag for the item material and can provide test samples.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'RFID loyihasini nimadan boshlash kerak?',
                    'ru' => 'С чего начать RFID-проект?',
                    'en' => 'Where should an RFID project start?',
                ],
                'answer' => [
                    'uz' => 'Pilot loyihadan boshlash maʼqul: bitta ombor zonasi yoki doʻkonda metkalar, oʻquvchilar va antennalar sinab koʻriladi. Natijalarga qarab uskuna soni, joylashuvi va sozlamalari aniqlanadi. Shundan soʻng Poson uskunani oʻrnatadi, antennalarni sozlaydi va RFID maʼlumotlarini 1C ga uzatishni yoʻlga qoʻyadi.',
                    'ru' => 'Лучше начать с пилотного проекта: в одной зоне склада или в одном магазине проверяются метки, считыватели и антенны. По результатам определяются количество, расстановка и настройки оборудования. Затем Poson монтирует оборудование, настраивает антенны и налаживает передачу RFID-данных в 1С.',
                    'en' => 'Start with a pilot: tags, readers and antennas are tested in one warehouse zone or one store. The results define how much equipment you need, where it goes and how it is configured. Poson then installs the hardware, tunes the antennas and sets up the flow of RFID data into 1C.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'RFID maʼlumotlarini 1C ga uzatish mumkinmi?',
                    'ru' => 'Можно ли передавать данные RFID в 1С?',
                    'en' => 'Can RFID data be sent to 1C?',
                ],
                'answer' => [
                    'uz' => 'Ha, Poson RFID oʻquvchilardan olingan maʼlumotlarni 1C ga ulaydi. Masalan, inventarizatsiya natijalari yoki tovar harakati hisob tizimingizga tushadi. Ish tarkibi 1C konfiguratsiyangiz va jarayonlaringizga bogʻliq, uni tijorat taklifida koʻrsatamiz.',
                    'ru' => 'Да, Poson подключает данные с RFID-считывателей к 1С. Например, результаты инвентаризации или движение товара попадают в вашу учётную систему. Состав работ зависит от конфигурации 1С и ваших процессов, его указываем в коммерческом предложении.',
                    'en' => 'Yes, Poson connects data from RFID readers to 1C. For example, stocktaking results or item movements go into your accounting system. The scope depends on your 1C configuration and processes and is set out in the quote.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'RFID yorliqlarni oʻzimiz chop etib, kodlay olamizmi?',
                    'ru' => 'Можно ли самим печатать и кодировать RFID-этикетки?',
                    'en' => 'Can we print and encode RFID labels ourselves?',
                ],
                'answer' => [
                    'uz' => 'Ha, buning uchun RFID printer kerak: u yorliqqa matn va shtrix-kod chop etadi hamda bir vaqtning oʻzida ichidagi metkani kodlaydi. Yorliq va inleylar printer modeliga mos boʻlishi kerak. Poson printer va yorliqlarni tanlashga yordam beradi hamda kodlash shablonlarini tayyorlaydi.',
                    'ru' => 'Да, для этого нужен RFID-принтер: он печатает на этикетке текст и штрих-код и одновременно кодирует метку внутри неё. Этикетки и инлеи должны подходить к модели принтера. Poson поможет подобрать принтер и этикетки и подготовит шаблоны кодирования.',
                    'en' => 'Yes, you need an RFID printer: it prints text and a barcode on the label and encodes the tag inside it in the same pass. The labels and inlays must suit the printer model. Poson helps choose the printer and labels and prepares the encoding templates.',
                ],
            ],
        ],
    ],

    // ─── POS equipment ──────────────────────────────────────────────────────
    [
        'scope' => 'category',
        'slug' => 'pos',
        'items' => [
            [
                'question' => [
                    'uz' => 'POS uskunasi bilan qaysi kassa dasturini ishlatish kerak?',
                    'ru' => 'Какую кассовую программу использовать с POS-оборудованием?',
                    'en' => 'Which POS program should I use with the equipment?',
                ],
                'answer' => [
                    'uz' => 'Poson kassa joyini oʻz katalogidagi kassa yoki savdo hisobi dasturi bilan birga jamlaydi va unga skaner, chek printeri hamda pul yashigini ulaydi. Agar boshqa dastur ishlatsangiz, uning qoʻllab-quvvatlanadigan qurilmalar roʻyxatini tekshiring. Klaviatura emulyatsiyasi rejimidagi skanerlar istalgan dastur bilan ishlaydi.',
                    'ru' => 'Poson комплектует кассовое место вместе с кассовой или учётной программой из своего каталога и подключает к ней сканер, чековый принтер и денежный ящик. Если вы используете другую программу, сверьтесь с её списком поддерживаемых устройств. Сканеры в режиме эмуляции клавиатуры работают с любой программой.',
                    'en' => 'Poson puts the checkout station together with a POS or inventory program from its own catalogue and connects the scanner, receipt printer and cash drawer to it. If you use a different program, check its list of supported devices. Scanners in keyboard emulation mode work with any program.',
                ],
            ],
            [
                'question' => [
                    'uz' => '58 mm yoki 80 mm chek printeri: qaysi biri kerak?',
                    'ru' => 'Чековый принтер 58 или 80 мм: какой выбрать?',
                    'en' => '58 mm or 80 mm receipt printer: which should I choose?',
                ],
                'answer' => [
                    'uz' => '80 mm printer xaridorlar oqimi katta kassalar uchun mos: chek oʻqishga qulay va koʻp qatorni sigʻdiradi. 58 mm printer ixcham, kichik doʻkon, kafe yoki mobil ish joyi uchun yetarli. Tez ishlaydigan kassalar uchun avtomatik kesgichli modelni tanlang.',
                    'ru' => 'Принтер 80 мм подходит для касс с большим потоком покупателей: чек удобно читать и на нём помещается больше строк. Принтер 58 мм компактнее и подойдёт небольшому магазину, кафе или мобильному рабочему месту. Для загруженных касс выбирайте модель с автоотрезчиком.',
                    'en' => 'An 80 mm printer suits busy checkouts: receipts are easier to read and fit more lines. A 58 mm printer is more compact and works well for a small shop, café or mobile station. For busy checkouts, choose a model with an auto-cutter.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Yorliq chop etuvchi tarozi qanday ishlaydi?',
                    'ru' => 'Как работают весы с печатью этикеток?',
                    'en' => 'How do label-printing scales work?',
                ],
                'answer' => [
                    'uz' => 'Tarozi tovarni tortadi va ogʻirlik yoki narx kodlangan shtrix-kodli yorliq chop etadi, kassadagi skaner esa uni oʻqiydi. Buning uchun tarozi va kassa dasturida kod prefiksi hamda tovarlar roʻyxati (PLU) bir xil sozlangan boʻlishi kerak. Poson tarozini oʻrnatadi va sozlaydi, tovarlar roʻyxatini esa mijoz oʻzi yuritadi.',
                    'ru' => 'Весы взвешивают товар и печатают этикетку со штрих-кодом, в котором закодирован вес или стоимость, а сканер на кассе его считывает. Для этого префикс кода и список товаров (PLU) должны быть согласованно настроены в весах и кассовой программе. Poson установит и настроит весы, а товарный справочник ведёт сам клиент.',
                    'en' => 'The scale weighs the item and prints a barcode label that encodes the weight or amount, and the checkout scanner reads it. For this, the barcode prefix and the product list (PLU) must be set up consistently in the scale and the POS software. Poson installs and configures the scale, and you maintain the product list.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Kassa joyini toʻliq jihozlab bera olasizmi?',
                    'ru' => 'Можете ли вы укомплектовать кассовое место целиком?',
                    'en' => 'Can you equip a complete checkout station?',
                ],
                'answer' => [
                    'uz' => 'Ha, POS terminal, skaner, chek printeri, pul yashigi va xaridor displeyini bitta toʻplam sifatida tanlaymiz. Muhandislarimiz uskunani oʻrnatadi, oʻz katalogimizdagi kassa dasturini sozlaydi va kassirlarni oʻqitadi. Kafolat davomida xizmatni oʻz servis markazimiz koʻrsatadi.',
                    'ru' => 'Да, подбираем POS-терминал, сканер, чековый принтер, денежный ящик и дисплей покупателя единым комплектом. Наши инженеры установят оборудование, настроят кассовую программу из нашего каталога и обучат кассиров. Гарантийное обслуживание выполняет наш собственный сервисный центр.',
                    'en' => 'Yes, we put together the POS terminal, scanner, receipt printer, cash drawer and customer display as one set. Our engineers install the equipment, set up a POS program from our catalogue and train your cashiers. Warranty service is handled by our own service centre.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Pul yashigi qanday ulanadi?',
                    'ru' => 'Как подключается денежный ящик?',
                    'en' => 'How is a cash drawer connected?',
                ],
                'answer' => [
                    'uz' => 'Koʻpincha pul yashigi chek printeriga maxsus kabel (RJ11 yoki RJ12) orqali ulanadi va chek chop etilganda avtomatik ochiladi. Ayrim POS terminallarda yashik uchun alohida port boʻladi. Poson toʻplamni jamlashda yashik, printer va kassa dasturi bir-biriga mosligini tekshiradi.',
                    'ru' => 'Чаще всего денежный ящик подключается к чековому принтеру специальным кабелем (RJ11 или RJ12) и открывается автоматически при печати чека. У некоторых POS-терминалов есть отдельный порт для ящика. При комплектации Poson проверяет совместимость ящика, принтера и кассовой программы.',
                    'en' => 'In most setups, the cash drawer connects to the receipt printer with a special cable (RJ11 or RJ12) and opens automatically when a receipt is printed. Some POS terminals have a dedicated drawer port. When putting the kit together, Poson checks that the drawer, printer and POS software work together.',
                ],
            ],
            [
                'confirm' => true,
                'question' => [
                    'uz' => 'Kassa uchun onlayn-NKM yoki virtual kassa kerakmi?',
                    'ru' => 'Нужна ли для кассы онлайн-ККМ или виртуальная касса?',
                    'en' => 'Do I need an online cash register or a virtual cash register?',
                ],
                'answer' => [
                    'uz' => 'Oʻzbekistonda xaridorga chek davlat reyestriga kiritilgan onlayn nazorat-kassa mashinasi yoki virtual kassa orqali, fiskal modul bilan beriladi. Talablar vaqti-vaqti bilan oʻzgaradi, shuning uchun amaldagi qoidalarni buxgalteringiz va dastur ishlab chiqaruvchisi bilan aniqlang. Poson tanlangan kassa dasturi bilan ishlaydigan uskunani tanlashga yordam beradi.',
                    'ru' => 'В Узбекистане чек покупателю выдаётся через онлайн-ККМ или виртуальную кассу, включённые в государственный реестр и работающие с фискальным модулем. Требования время от времени меняются, поэтому актуальные правила уточните у бухгалтера и разработчика программы. Poson поможет подобрать оборудование, совместимое с выбранной кассовой программой.',
                    'en' => 'In Uzbekistan, the customer receipt is issued through an online cash register or a virtual cash register that is included in the state register and works with a fiscal module. Requirements change from time to time, so check the current rules with your accountant and the software vendor. Poson helps you choose equipment that works with your chosen POS software.',
                ],
            ],
        ],
    ],

    // ─── Software ───────────────────────────────────────────────────────────
    [
        'scope' => 'category',
        'slug' => 'software',
        'items' => [
            [
                'question' => [
                    'uz' => 'Dastur mening 1C bilan ishlaydimi?',
                    'ru' => 'Будет ли программа работать с моей 1С?',
                    'en' => 'Will the software work with my 1C?',
                ],
                'answer' => [
                    'uz' => 'Ha, Poson dasturni 1C ga ulaydi va maʼlumot almashinuvini sozlaydi. Ish tarkibi dastur va 1C konfiguratsiyangizga bogʻliq. Konfiguratsiya nomini ayting, ish tarkibini tijorat taklifida koʻrsatamiz.',
                    'ru' => 'Да, Poson подключает программу к 1С и настраивает обмен данными. Состав работ зависит от программы и вашей конфигурации 1С. Назовите конфигурацию, и мы опишем работы в коммерческом предложении.',
                    'en' => 'Yes, Poson connects the software to 1C and sets up the data exchange. The scope depends on the software and your 1C configuration. Tell us your configuration and we will set out the work in the quote.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Doimiy litsenziya yoki obuna: farqi nimada?',
                    'ru' => 'Бессрочная лицензия или подписка: в чём разница?',
                    'en' => 'Perpetual licence or subscription: what is the difference?',
                ],
                'answer' => [
                    'uz' => 'Doimiy litsenziya bir marta sotib olinadi va muddatsiz ishlatiladi, yangilanishlar alohida shartlar asosida beriladi. Obuna belgilangan muddatga amal qiladi va odatda yangilanishlarni oʻz ichiga oladi. Litsenziya turi va shartlari ishlab chiqaruvchiga bogʻliq, ular mahsulot sahifasida yoki taklifda koʻrsatiladi.',
                    'ru' => 'Бессрочная лицензия покупается один раз и действует без ограничения срока, обновления предоставляются на отдельных условиях. Подписка действует определённый срок и обычно включает обновления. Тип и условия лицензии зависят от производителя и указаны на странице товара или в предложении.',
                    'en' => 'A perpetual licence is bought once and has no expiry, with updates provided on separate terms. A subscription runs for a set term and usually includes updates. The licence type and terms depend on the vendor and are shown on the product page or in the quote.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Dasturni oʻrnatib, xodimlarni oʻqitasizmi?',
                    'ru' => 'Вы установите программу и обучите сотрудников?',
                    'en' => 'Will you install the software and train our staff?',
                ],
                'answer' => [
                    'uz' => 'Ha, Poson litsenziyani faollashtiradi, dasturni oʻrnatadi va uskunangiz bilan sozlaydi. Ishga tushirilgandan soʻng xodimlaringizni dastur bilan ishlashga oʻrgatamiz. Keyingi savollar boʻyicha texnik yordam oʻzbek, rus va ingliz tillarida koʻrsatiladi.',
                    'ru' => 'Да, Poson активирует лицензию, устанавливает программу и настраивает её вместе с вашим оборудованием. После запуска обучаем сотрудников работе с программой. По дальнейшим вопросам техническая поддержка доступна на узбекском, русском и английском языках.',
                    'en' => 'Yes, Poson activates the licence, installs the software and configures it with your equipment. After launch we train your staff to use it. Technical support for follow-up questions is available in Uzbek, Russian and English.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Biznesimiz uchun qaysi dasturni tanlash kerak?',
                    'ru' => 'Какую программу выбрать для нашего бизнеса?',
                    'en' => 'Which program should we choose for our business?',
                ],
                'answer' => [
                    'uz' => 'Tanlov biznes turiga bogʻliq: doʻkon, savdo tarmogʻi, kafe yoki ombor. Savdo nuqtalari soni, tovarlar hajmi va ishlatiladigan uskuna ham muhim. Vazifangizni aytib bering, Poson katalogidagi dasturlar orasidan mosini tanlashga yordam beradi.',
                    'ru' => 'Выбор зависит от формата бизнеса: магазин, торговая сеть, кафе или склад. Важны также число точек продаж, объём ассортимента и используемое оборудование. Опишите задачу, и Poson поможет выбрать подходящую программу из своего каталога.',
                    'en' => 'It depends on your business type: a shop, a store chain, a café or a warehouse. The number of outlets, the size of your range and the equipment you use also matter. Describe your task and Poson will help you pick a suitable program from its catalogue.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Qaysi dasturlarni taklif qilasiz?',
                    'ru' => 'Какие программы вы предлагаете?',
                    'en' => 'Which programs do you offer?',
                ],
                'answer' => [
                    'uz' => 'Katalogimizda Oʻzbekistonda ishlatiladigan dasturlar bor: chakana savdo uchun Billz platformasi hamda Ox, Hippo va Pospoint kassa tizimlari, kafe va restoranlar uchun AliPos, Rahmat virtual kassasi va tovar hamda savdo hisobi uchun MoySklad. Imkoniyatlar, platformalar va litsenziya shartlari har bir mahsulot sahifasida koʻrsatiladi. Biznesingizga qaysi biri mosligini tanlashga yordam beramiz.',
                    'ru' => 'В нашем каталоге программы, которые используются в Узбекистане: платформа Billz и кассовые системы Ox, Hippo и Pospoint для розницы, AliPos для кафе и ресторанов, виртуальная касса Rahmat и МойСклад для товарного и торгового учёта. Возможности, платформы и условия лицензии указаны на странице каждого товара. Поможем выбрать подходящую для вашего бизнеса.',
                    'en' => 'Our catalogue includes programs used in Uzbekistan: the Billz platform and the Ox, Hippo and Pospoint POS systems for retail, AliPos for cafés and restaurants, the Rahmat virtual cash register and MoySklad for inventory and trade. Features, platforms and licence terms are listed on each product page. We help you choose the one that fits your business.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Dastur uskunam bilan mos ishlashini tekshirasizmi?',
                    'ru' => 'Проверяете ли вы совместимость программы с оборудованием?',
                    'en' => 'Do you check that the software works with my equipment?',
                ],
                'answer' => [
                    'uz' => 'Ha, dastur tanlashda skaner, chek va yorliq printerlari, tarozi va boshqa qurilmalaringiz u bilan ishlay olishini tekshiramiz. Biror qurilma mos kelmasa, dastur qoʻllab-quvvatlaydigan uskunani taklif qilamiz. Dastur va uskunani sozlash alohida xizmat sifatida bajariladi.',
                    'ru' => 'Да, при выборе программы проверяем, смогут ли с ней работать ваши сканеры, чековые и этикеточные принтеры, весы и другие устройства. Если что-то не подходит, предложим оборудование, которое поддерживает программа. Настройка программы и оборудования выполняется как отдельная услуга.',
                    'en' => 'Yes, when choosing software we check that your scanners, receipt and label printers, scales and other devices can work with it. If something does not fit, we suggest equipment the program supports. Setup of the software and equipment is provided as a separate service.',
                ],
            ],
        ],
    ],

    // ─── Services ───────────────────────────────────────────────────────────
    [
        'scope' => 'category',
        'slug' => 'services',
        'items' => [
            [
                'question' => [
                    'uz' => 'Sozlash xizmatiga nimalar kiradi?',
                    'ru' => 'Что входит в настройку?',
                    'en' => 'What does setup include?',
                ],
                'answer' => [
                    'uz' => 'Sozlash uskunani oʻrnatish, kerakli parametrlarni sozlash, hisob tizimingizga ulash, ishlashini sinash va xodimlarni oʻqitishni oʻz ichiga oladi. Ish joyida yoki masofadan bajariladi. Ish hajmi vazifaga bogʻliq, shuning uchun narx soʻrov boʻyicha aniqlanadi va tijorat taklifida koʻrsatiladi.',
                    'ru' => 'Настройка включает установку оборудования, настройку параметров, подключение к вашей учётной системе, проверку работы и обучение сотрудников. Работы выполняются на месте или удалённо. Объём зависит от задачи, поэтому цена по запросу и указывается в коммерческом предложении.',
                    'en' => 'Setup covers installing the equipment, configuring it, connecting it to your accounting system, testing and staff training. The work is done on site or remotely. The scope depends on the task, so the price is on request and stated in the quote.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Taʼmirlash qancha vaqt oladi?',
                    'ru' => 'Сколько длится ремонт?',
                    'en' => 'How long does a repair take?',
                ],
                'answer' => [
                    'uz' => 'Muddat nosozlik turiga va ehtiyot qismlar mavjudligiga bogʻliq. Avval diagnostika oʻtkazilib, nosozlik sababi aniqlanadi. Taʼmirlash boshlanishidan oldin muddat va narxni yozma ravishda siz bilan kelishamiz.',
                    'ru' => 'Срок зависит от характера неисправности и наличия запчастей. Сначала проводим диагностику и находим причину. Сроки и стоимость ремонта письменно согласуем с вами до начала работ.',
                    'en' => 'It depends on the fault and the availability of spare parts. We start with diagnostics to find the cause. You get a written estimate of timeline and cost before the repair starts.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Boshqa joydan sotib olingan uskunani taʼmirlaysizmi?',
                    'ru' => 'Ремонтируете ли вы оборудование, купленное не у вас?',
                    'en' => 'Do you repair equipment bought elsewhere?',
                ],
                'answer' => [
                    'uz' => 'Ha, biz xizmat koʻrsatadigan har qanday brend uskunasini, qayerdan sotib olinganidan qatʼi nazar, taʼmirlaymiz. Avval diagnostika oʻtkaziladi, soʻng muddat va narxni yozma ravishda kelishamiz. Servis soʻrovida qurilma modeli va nosozlikni koʻrsating.',
                    'ru' => 'Да, мы ремонтируем оборудование любых обслуживаемых нами брендов, независимо от того, где оно куплено. Сначала проводим диагностику, затем письменно согласуем с вами сроки и стоимость. В сервисном запросе укажите модель устройства и опишите неисправность.',
                    'en' => 'Yes, we repair equipment from any brand we service, wherever it was bought. Diagnostics come first, then you get a written estimate of timeline and cost. In your service request, give the device model and describe the fault.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Texnik xizmat koʻrsatish shartnomasini tuzsa boʻladimi?',
                    'ru' => 'Можно ли заключить договор на обслуживание?',
                    'en' => 'Can we sign a maintenance contract?',
                ],
                'answer' => [
                    'uz' => 'Ha, uskunalar parki uchun texnik xizmat koʻrsatish shartnomasini tuzamiz. Xizmat tarkibi, javob berish muddati va tashriflar soni har bir mijoz bilan alohida kelishiladi. Kelishilgan shartlar tijorat taklifida koʻrsatiladi.',
                    'ru' => 'Да, заключаем договоры на обслуживание парка оборудования. Состав услуг, время реагирования и количество выездов согласуем с каждым клиентом индивидуально. Согласованные условия указываем в коммерческом предложении.',
                    'en' => 'Yes, we sign maintenance contracts for equipment fleets. The scope, response time and number of visits are agreed individually with each customer. The agreed terms are set out in the quote.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Taʼmirlash vaqtida vaqtinchalik qurilma berasizmi?',
                    'ru' => 'Предоставляете ли вы подменное устройство на время ремонта?',
                    'en' => 'Do you provide a replacement device during repair?',
                ],
                'answer' => [
                    'uz' => 'Yoʻq, taʼmirlash davrida almashtiruvchi qurilma berilmaydi. Shuning uchun muhim ish joylari uchun zaxira skaner yoki printer saqlashni tavsiya qilamiz. Taʼmirlash muddatini diagnostikadan soʻng siz bilan yozma ravishda kelishamiz.',
                    'ru' => 'Нет, подменные устройства на время ремонта мы не предоставляем. Поэтому для критичных рабочих мест рекомендуем держать резервный сканер или принтер. Срок ремонта письменно согласуем с вами после диагностики.',
                    'en' => 'No, we do not provide replacement devices during repair. For critical workstations we therefore recommend keeping a spare scanner or printer. The repair timeline is agreed with you in writing after diagnostics.',
                ],
            ],
            [
                'question' => [
                    'uz' => 'Profilaktik xizmatga nimalar kiradi?',
                    'ru' => 'Что входит в профилактическое обслуживание?',
                    'en' => 'What does preventive maintenance include?',
                ],
                'answer' => [
                    'uz' => 'Profilaktika odatda qurilmalarni tozalash, printer kallagi va rezina val kabi eskiruvchi qismlarni tekshirish hamda sozlash va kalibrlashni oʻz ichiga oladi. Bu kutilmagan nosozliklar ehtimolini kamaytiradi. Aniq ish tarkibi va tashriflar jadvali servis shartnomasida kelishiladi.',
                    'ru' => 'Профилактика обычно включает чистку устройств, проверку изнашиваемых деталей, например печатающей головки и прижимного вала, а также настройку и калибровку. Это снижает риск внезапных поломок. Точный состав работ и график выездов согласуются в сервисном договоре.',
                    'en' => 'Preventive maintenance usually covers cleaning the devices, checking wear parts such as the printhead and platen roller, and adjustment and calibration. This reduces the risk of unexpected breakdowns. The exact scope and visit schedule are agreed in the service contract.',
                ],
            ],
        ],
    ],

];
