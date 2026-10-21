<?php

/**
 * Catalog category tree for the CatalogSeeder (Plan 03, T9).
 *
 * Top-level slugs match App\Support\Site\Navigation and must not change:
 * scanners, label-printers, mobile-computers, labels-ribbons, rfid, pos, software.
 * All slugs are unique across the whole tree. Icons are keys of x-site.icon or null.
 *
 * Uzbek is Latin script; oʻ, gʻ and the tutuq belgisi use ʼ (U+02BB).
 *
 * @return list<array{
 *     slug: string,
 *     icon: string|null,
 *     sort: int,
 *     name: array{uz: string, ru: string, en: string},
 *     description: array{uz: string, ru: string, en: string},
 *     meta_title: array{uz: string, ru: string, en: string},
 *     meta_description: array{uz: string, ru: string, en: string},
 *     children: list<array<string, mixed>>
 * }>
 */
return [

    // ─── 1. Barcode scanners ────────────────────────────────────────────────
    [
        'slug' => 'scanners',
        'icon' => 'scan',
        'sort' => 1,
        'name' => [
            'uz' => 'Shtrix-kod skanerlari',
            'ru' => 'Сканеры штрих-кода',
            'en' => 'Barcode scanners',
        ],
        'description' => [
            'uz' => 'Chakana savdo kassalari, omborlar, dorixonalar va ishlab chiqarish uchun qoʻl, simsiz, statsionar va oʻrnatiladigan shtrix-kod skanerlari. 1D va 2D kodlarni, jumladan markirovkadagi DataMatrix kodlarini oʻqiydigan modelni vazifangiz va ish sharoitingizga qarab tanlashga yordam beramiz. Poson skanerni 1C, ERP yoki kassa dasturingizga ulaydi, prefiks va suffikslarni sozlaydi hamda kafolat davomida oʻz servis markazida xizmat koʻrsatadi.',
            'ru' => 'Ручные, беспроводные, стационарные и встраиваемые сканеры штрих-кода для касс, складов, аптек и производства. Подберём модель под ваши коды и условия работы — от 1D до 2D, включая DataMatrix для маркировки. Специалисты Poson подключат сканер к 1С, ERP или кассовой программе, настроят префиксы и суффиксы, а гарантийное обслуживание выполнит наш собственный сервисный центр.',
            'en' => 'Handheld, wireless, presentation and embedded barcode scanners for retail checkouts, warehouses, pharmacies and production lines. We help you choose the right model for your codes and environment, from 1D to 2D including DataMatrix for product marking. Poson connects the scanner to 1C, your ERP or POS software, configures prefixes and suffixes, and handles warranty service in its own service centre.',
        ],
        'meta_title' => [
            'uz' => 'Shtrix-kod skanerlari: tanlash va sozlash — Poson',
            'ru' => 'Сканеры штрих-кода: подбор и настройка — Poson',
            'en' => 'Barcode scanners: selection and setup — Poson',
        ],
        'meta_description' => [
            'uz' => 'Qoʻl, simsiz va statsionar shtrix-kod skanerlari: 1D, 2D va DataMatrix. 1C va POS bilan integratsiya, sozlash, rasmiy kafolat va servis. Toshkent.',
            'ru' => 'Сканеры штрих-кода для магазинов, складов и аптек: ручные, беспроводные, 2D с DataMatrix. Подбор, подключение к 1С, официальная гарантия и сервис Poson.',
            'en' => 'Handheld, wireless and presentation barcode scanners for retail, warehouses and pharmacies. Expert selection, 1C and POS integration, warranty service.',
        ],
        'children' => [
            [
                'slug' => 'handheld-scanners',
                'icon' => 'scan',
                'sort' => 1,
                'name' => [
                    'uz' => 'Qoʻl skanerlari',
                    'ru' => 'Ручные сканеры',
                    'en' => 'Handheld scanners',
                ],
                'description' => [
                    'uz' => 'Kassa, tovar qabul qilish va inventarizatsiya uchun simli qoʻl skanerlari: 1D lazerli, CCD va 2D modellar. Poson skanerni hisob tizimingizga ulab, kerakli kod turlarini sozlab beradi.',
                    'ru' => 'Проводные ручные сканеры для кассы, приёмки и инвентаризации: лазерные, CCD и 2D-имиджеры. Poson подключит сканер к вашей учётной системе и настроит нужные типы кодов.',
                    'en' => 'Corded handheld scanners for checkouts, goods receiving and stocktaking, with laser, CCD or 2D imager engines. Poson connects the scanner to your accounting system and enables the symbologies you need.',
                ],
                'meta_title' => [
                    'uz' => 'Qoʻl shtrix-kod skanerlari — Poson',
                    'ru' => 'Ручные сканеры штрих-кода — Poson',
                    'en' => 'Handheld barcode scanners — Poson',
                ],
                'meta_description' => [
                    'uz' => 'Kassa, ombor va dorixona uchun simli qoʻl skanerlari: 1D lazerli, CCD va 2D modellar. Tanlash, 1C bilan integratsiya, rasmiy kafolat va servis markazi.',
                    'ru' => 'Проводные ручные сканеры штрих-кода для кассы, склада и аптеки: лазерные, CCD и 2D. Подбор, подключение к 1С, официальная гарантия и сервис Poson.',
                    'en' => 'Corded handheld barcode scanners for checkouts, warehouses and pharmacies: laser, CCD and 2D imagers. Expert setup, 1C integration and warranty service.',
                ],
                'children' => [],
            ],
            [
                'slug' => 'wireless-scanners',
                'icon' => 'scan',
                'sort' => 2,
                'name' => [
                    'uz' => 'Simsiz skanerlar',
                    'ru' => 'Беспроводные сканеры',
                    'en' => 'Wireless scanners',
                ],
                'description' => [
                    'uz' => 'Bluetooth yoki radiokanal orqali ishlaydigan skanerlar omborda, savdo zalida va yirik tovarlarni qabul qilishda erkin harakatlanish imkonini beradi. Bazani, ulanishni va dasturingiz bilan ishlashni Poson mutaxassislari sozlaydi.',
                    'ru' => 'Сканеры с Bluetooth или радиоканалом дают свободу движения в торговом зале, на складе и при приёмке крупногабаритного товара. Специалисты Poson настроят базу, сопряжение и работу с вашей программой.',
                    'en' => 'Bluetooth and radio-link scanners give staff freedom to move around the shop floor, warehouse aisles or bulky goods at receiving. Poson engineers set up the cradle, pairing and connection to your software.',
                ],
                'meta_title' => [
                    'uz' => 'Simsiz shtrix-kod skanerlari — Poson',
                    'ru' => 'Беспроводные сканеры штрих-кода — Poson',
                    'en' => 'Wireless barcode scanners — Poson',
                ],
                'meta_description' => [
                    'uz' => 'Bluetooth va radiokanalli simsiz shtrix-kod skanerlari ombor, doʻkon va dorixona uchun. Tanlash, sozlash, 1C bilan integratsiya va rasmiy kafolat.',
                    'ru' => 'Беспроводные сканеры штрих-кода с Bluetooth и радиоканалом для склада, магазина и аптеки. Подбор, настройка базы, интеграция с 1С и официальная гарантия.',
                    'en' => 'Bluetooth and radio-link wireless barcode scanners for warehouses, shops and pharmacies. Expert selection, setup, 1C integration and official warranty.',
                ],
                'children' => [],
            ],
            [
                'slug' => 'presentation-scanners',
                'icon' => 'scan',
                'sort' => 3,
                'name' => [
                    'uz' => 'Statsionar skanerlar',
                    'ru' => 'Стационарные сканеры',
                    'en' => 'Presentation scanners',
                ],
                'description' => [
                    'uz' => 'Kassa yoniga yoki ichiga oʻrnatiladigan statsionar va koʻp tekislikli skanerlar xaridorlar oqimi katta boʻlgan joylarda tovarni tez oʻqiydi. Poson skanerni kassa dasturiga ulaydi va kassirlar uchun qulay oʻqish rejimini sozlaydi.',
                    'ru' => 'Стационарные и многоплоскостные сканеры для касс с большим потоком покупателей: товар считывается без лишних движений. Poson подключит сканер к кассовой программе и настроит удобный для кассира режим считывания.',
                    'en' => 'Presentation and multi-plane scanners for busy checkouts read items as they pass, with no aiming needed. Poson connects the scanner to your POS software and tunes the read mode for cashiers.',
                ],
                'meta_title' => [
                    'uz' => 'Statsionar shtrix-kod skanerlari — Poson',
                    'ru' => 'Стационарные сканеры штрих-кода — Poson',
                    'en' => 'Presentation barcode scanners — Poson',
                ],
                'meta_description' => [
                    'uz' => 'Kassalar uchun statsionar va koʻp tekislikli shtrix-kod skanerlari: tez oʻqish, 2D va DataMatrix. Kassa dasturiga ulash, sozlash, kafolat va servis.',
                    'ru' => 'Стационарные и многоплоскостные сканеры штрих-кода для касс с большим потоком покупателей. Подключение к кассовой программе, настройка и сервис.',
                    'en' => 'Presentation and multi-plane barcode scanners for busy retail checkouts, with 2D and DataMatrix reading. POS integration, setup and warranty service.',
                ],
                'children' => [],
            ],
            [
                'slug' => 'embedded-scanners',
                'icon' => 'scan',
                'sort' => 4,
                'name' => [
                    'uz' => 'Oʻrnatiladigan skanerlar',
                    'ru' => 'Встраиваемые сканеры',
                    'en' => 'Embedded scanners',
                ],
                'description' => [
                    'uz' => 'Kiosklar, terminallar, konveyerlar va avtomatlar ichiga oʻrnatiladigan kompakt skanerlar va OEM skanerlash modullari. Poson integratorlarga ulanish interfeysi va oʻqish parametrlarini tanlashda maslahat beradi.',
                    'ru' => 'Компактные сканеры и OEM-модули для встраивания в киоски, терминалы, конвейеры и вендинговые аппараты. Poson поможет интеграторам выбрать интерфейс и параметры считывания.',
                    'en' => 'Compact fixed-mount scanners and OEM scan engines for kiosks, terminals, conveyors and vending machines. Poson helps integrators choose the interface and read settings for their device.',
                ],
                'meta_title' => [
                    'uz' => 'Oʻrnatiladigan skanerlar va OEM modullar — Poson',
                    'ru' => 'Встраиваемые сканеры и OEM-модули — Poson',
                    'en' => 'Embedded scanners and OEM scan engines — Poson',
                ],
                'meta_description' => [
                    'uz' => 'Kiosk, terminal, konveyer va avtomatlar uchun oʻrnatiladigan shtrix-kod skanerlari va OEM modullar. Interfeys tanlash boʻyicha maslahat va servis.',
                    'ru' => 'Встраиваемые сканеры штрих-кода и OEM-модули для киосков, терминалов, конвейеров и вендинга. Помощь в выборе интерфейса, надёжная поставка и сервис.',
                    'en' => 'Embedded barcode scanners and OEM scan engines for kiosks, terminals, conveyors and vending. Help choosing the interface, reliable supply and service.',
                ],
                'children' => [],
            ],
        ],
    ],

    // ─── 2. Label printers ──────────────────────────────────────────────────
    [
        'slug' => 'label-printers',
        'icon' => 'print',
        'sort' => 2,
        'name' => [
            'uz' => 'Yorliq printerlari',
            'ru' => 'Принтеры этикеток',
            'en' => 'Label printers',
        ],
        'description' => [
            'uz' => 'Chakana savdo, ombor, logistika va ishlab chiqarish uchun stol usti, sanoat va mobil yorliq printerlari. Kunlik chop etish hajmi, yorliq oʻlchami va chop etish usuliga (termo yoki termotransfer) qarab mos modelni tanlaymiz. Poson printerni 1C, WMS yoki markirovka tizimiga ulaydi, yorliq shablonlarini tayyorlaydi va oʻz servis markazida kafolatli xizmat koʻrsatadi.',
            'ru' => 'Настольные, промышленные и мобильные принтеры этикеток для розницы, складов, логистики и производства. Подберём модель по объёму печати, размеру этикетки и способу печати — прямая термопечать или термотрансфер. Poson подключит принтер к 1С, WMS или системе маркировки, подготовит шаблоны этикеток и обеспечит гарантийное обслуживание в собственном сервисном центре.',
            'en' => 'Desktop, industrial and mobile label printers for retail, warehousing, logistics and manufacturing. We match the model to your daily volume, label size and print method, direct thermal or thermal transfer. Poson connects the printer to 1C, your WMS or marking system, prepares label templates and provides warranty service in its own service centre.',
        ],
        'meta_title' => [
            'uz' => 'Yorliq printerlari: tanlash va sozlash — Poson',
            'ru' => 'Принтеры этикеток: подбор и настройка — Poson',
            'en' => 'Label printers: selection and setup — Poson',
        ],
        'meta_description' => [
            'uz' => 'Stol usti, sanoat va mobil yorliq printerlari: termo va termotransfer chop etish. Shablonlar, 1C va WMS bilan integratsiya, rasmiy kafolat va servis.',
            'ru' => 'Настольные, промышленные и мобильные принтеры этикеток: термо и термотрансфер. Шаблоны этикеток, интеграция с 1С и WMS, гарантия и сервис Poson.',
            'en' => 'Desktop, industrial and mobile label printers, direct thermal and thermal transfer. Label templates, 1C and WMS integration, warranty and service by Poson.',
        ],
        'children' => [
            [
                'slug' => 'desktop-label-printers',
                'icon' => 'print',
                'sort' => 1,
                'name' => [
                    'uz' => 'Stol usti yorliq printerlari',
                    'ru' => 'Настольные принтеры этикеток',
                    'en' => 'Desktop label printers',
                ],
                'description' => [
                    'uz' => 'Doʻkon, dorixona, kichik ombor va onlayn savdo uchun ixcham printerlar: javon yorliqlari, tovar va joʻnatma yorliqlarini chop etadi. Poson printerni kompyuter va hisob dasturingizga ulab, shablonlarni sozlab beradi.',
                    'ru' => 'Компактные принтеры для магазинов, аптек, небольших складов и интернет-торговли: полочные этикетки, этикетки на товар и отправления. Poson подключит принтер к компьютеру и учётной программе и настроит шаблоны.',
                    'en' => 'Compact printers for shops, pharmacies, small warehouses and e-commerce: shelf labels, product labels and shipping labels. Poson connects the printer to your PC and accounting software and sets up the templates.',
                ],
                'meta_title' => [
                    'uz' => 'Stol usti yorliq printerlari — Poson',
                    'ru' => 'Настольные принтеры этикеток — Poson',
                    'en' => 'Desktop label printers — Poson',
                ],
                'meta_description' => [
                    'uz' => 'Doʻkon, dorixona va kichik ombor uchun stol usti yorliq printerlari: 203 va 300 dpi, termo va termotransfer. Sozlash, shablonlar va rasmiy kafolat.',
                    'ru' => 'Настольные принтеры этикеток для магазинов, аптек и небольших складов: 203 и 300 dpi, термо и термотрансфер. Настройка, шаблоны и официальная гарантия.',
                    'en' => 'Desktop label printers for shops, pharmacies and small warehouses: 203 and 300 dpi, direct thermal and thermal transfer. Setup, templates and warranty.',
                ],
                'children' => [],
            ],
            [
                'slug' => 'industrial-label-printers',
                'icon' => 'print',
                'sort' => 2,
                'name' => [
                    'uz' => 'Sanoat yorliq printerlari',
                    'ru' => 'Промышленные принтеры этикеток',
                    'en' => 'Industrial label printers',
                ],
                'description' => [
                    'uz' => 'Metall korpusli printerlar katta hajmdagi yorliqlarni uzluksiz chop etadi: ishlab chiqarish liniyalari, taqsimlash markazlari va markirovka uchastkalari uchun. Poson printerni liniya yoki WMS bilan integratsiya qiladi va profilaktik xizmat koʻrsatadi.',
                    'ru' => 'Принтеры в металлическом корпусе для непрерывной печати больших объёмов на производстве, в распределительных центрах и на участках маркировки. Poson интегрирует принтер с линией или WMS и проводит плановое обслуживание.',
                    'en' => 'Metal-frame printers built for continuous high-volume printing on production lines, in distribution centres and at marking stations. Poson integrates the printer with your line or WMS and provides preventive maintenance.',
                ],
                'meta_title' => [
                    'uz' => 'Sanoat yorliq printerlari — Poson',
                    'ru' => 'Промышленные принтеры этикеток — Poson',
                    'en' => 'Industrial label printers — Poson',
                ],
                'meta_description' => [
                    'uz' => 'Ishlab chiqarish va taqsimlash markazlari uchun sanoat yorliq printerlari: uzluksiz ish, 300 va 600 dpi. Integratsiya, profilaktika, kafolat va servis.',
                    'ru' => 'Промышленные принтеры этикеток для производства и распределительных центров: непрерывная работа, 300 и 600 dpi. Интеграция, гарантия и сервис.',
                    'en' => 'Industrial label printers for production lines and distribution centres: continuous duty, 300 and 600 dpi. Line and WMS integration, maintenance, warranty.',
                ],
                'children' => [],
            ],
            [
                'slug' => 'mobile-label-printers',
                'icon' => 'print',
                'sort' => 3,
                'name' => [
                    'uz' => 'Mobil yorliq printerlari',
                    'ru' => 'Мобильные принтеры этикеток',
                    'en' => 'Mobile label printers',
                ],
                'description' => [
                    'uz' => 'Akkumulyatorli portativ printerlar yorliq va cheklarni toʻgʻridan-toʻgʻri ish joyida chop etadi: omborda, savdo zalida va yetkazib berishda. Poson printerni TSD bilan Bluetooth yoki Wi-Fi orqali bogʻlab, ish jarayoningizga moslaydi.',
                    'ru' => 'Портативные принтеры на аккумуляторе печатают этикетки и чеки прямо на месте: на складе, в торговом зале и при доставке. Poson свяжет принтер с ТСД по Bluetooth или Wi-Fi и настроит под ваш процесс.',
                    'en' => 'Battery-powered portable printers print labels and receipts right where the work happens: in the warehouse, on the shop floor or on delivery routes. Poson pairs the printer with your mobile computers over Bluetooth or Wi-Fi and fits it into your workflow.',
                ],
                'meta_title' => [
                    'uz' => 'Mobil yorliq printerlari — Poson',
                    'ru' => 'Мобильные принтеры этикеток — Poson',
                    'en' => 'Mobile label printers — Poson',
                ],
                'meta_description' => [
                    'uz' => 'Ombor, savdo zali va yetkazib berish uchun mobil yorliq printerlari: Bluetooth, Wi-Fi, akkumulyator. TSD bilan bogʻlash, sozlash va rasmiy kafolat.',
                    'ru' => 'Мобильные принтеры этикеток и чеков для склада, торгового зала и доставки: Bluetooth, Wi-Fi, аккумулятор. Связка с ТСД, настройка и официальная гарантия.',
                    'en' => 'Mobile label and receipt printers for warehouses, retail and delivery: Bluetooth, Wi-Fi, rechargeable battery. Pairing with mobile computers and warranty.',
                ],
                'children' => [],
            ],
        ],
    ],

    // ─── 3. Mobile computers (TSD) ──────────────────────────────────────────
    [
        'slug' => 'mobile-computers',
        'icon' => 'term',
        'sort' => 3,
        'name' => [
            'uz' => 'Maʼlumot yigʻish terminallari (TSD)',
            'ru' => 'Терминалы сбора данных (ТСД)',
            'en' => 'Mobile computers',
        ],
        'description' => [
            'uz' => 'Android asosidagi TSD tovar qabul qilish, inventarizatsiya, buyurtma yigʻish va yetkazib berishni qogʻozsiz bajarishga va hisobdagi xatolarni kamaytirishga yordam beradi. Skanerlash moduli, ekran oʻlchami, himoya darajasi va RFID imkoniyatiga qarab mos modelni tanlaymiz. Poson terminallarni 1C, WMS yoki mobil ilovangizga ulaydi, MDM orqali sozlaydi va oʻz servis markazida taʼmirlaydi.',
            'ru' => 'Терминалы сбора данных на Android переводят приёмку, инвентаризацию, сборку заказов и доставку в электронный вид и сокращают ошибки учёта. Подберём модель по сканирующему модулю, размеру экрана, степени защиты и поддержке RFID. Poson подключит ТСД к 1С, WMS или вашему мобильному приложению, настроит парк устройств через MDM и обеспечит ремонт в собственном сервисном центре.',
            'en' => 'Android mobile computers take receiving, stocktaking, order picking and proof of delivery off paper and cut data-entry errors. We help you choose by scan engine, screen size, ruggedness rating and RFID support. Poson connects the devices to 1C, your WMS or mobile app, configures the fleet through MDM and repairs them in its own service centre.',
        ],
        'meta_title' => [
            'uz' => 'Maʼlumot yigʻish terminallari (TSD) — Poson',
            'ru' => 'Терминалы сбора данных (ТСД) — Poson',
            'en' => 'Mobile computers for warehouse and retail — Poson',
        ],
        'meta_description' => [
            'uz' => 'Ombor, savdo va logistika uchun Android TSD: 2D skaner, IP himoya, RFID. 1C va WMS bilan integratsiya, MDM sozlash, kafolat va servis markazi.',
            'ru' => 'ТСД на Android для склада, магазина и логистики: 2D-сканер, защита IP, RFID. Интеграция с 1С и WMS, настройка через MDM, гарантия и сервис Poson.',
            'en' => 'Android mobile computers for warehouses, retail and logistics: 2D scanning, rugged IP-rated design, RFID. 1C and WMS integration, MDM setup and service.',
        ],
        'children' => [
            [
                'slug' => 'android-mobile-computers',
                'icon' => 'term',
                'sort' => 1,
                'name' => [
                    'uz' => 'Android TSD',
                    'ru' => 'ТСД на Android',
                    'en' => 'Android mobile computers',
                ],
                'description' => [
                    'uz' => 'Qoʻlda ishlatiladigan Android terminallar 2D skaner, Wi-Fi va modelga qarab mobil aloqa bilan: omborda, savdo zalida va kuryerlik ishida foydalanish uchun. Poson ilovalarni oʻrnatadi, hisob tizimingiz bilan almashinuvni sozlaydi va qurilmalarni MDM orqali boshqarishga tayyorlaydi.',
                    'ru' => 'Ручные терминалы на Android со 2D-сканером, Wi-Fi и, в зависимости от модели, мобильной связью — для склада, торгового зала и курьеров. Poson установит приложения, настроит обмен с учётной системой и подготовит устройства к управлению через MDM.',
                    'en' => 'Handheld Android terminals with a 2D scanner, Wi-Fi and, depending on the model, cellular connectivity for warehouse, shop-floor and courier work. Poson installs your apps, sets up data exchange with your accounting system and prepares the devices for MDM.',
                ],
                'meta_title' => [
                    'uz' => 'Android TSD terminallari — Poson',
                    'ru' => 'ТСД на Android — Poson',
                    'en' => 'Android mobile computers — Poson',
                ],
                'meta_description' => [
                    'uz' => 'Ombor, doʻkon va kuryerlar uchun Android TSD: 2D skaner, Wi-Fi, IP himoya. Ilovalarni oʻrnatish, 1C bilan almashinuv, MDM, rasmiy kafolat va servis.',
                    'ru' => 'ТСД на Android для склада, магазина и курьеров: 2D-сканер, Wi-Fi, защищённый корпус. Установка приложений, обмен с 1С, MDM, гарантия и сервис.',
                    'en' => 'Android mobile computers for warehouses, stores and couriers: 2D scanner, Wi-Fi, rugged housing. App installation, 1C data exchange, MDM and warranty.',
                ],
                'children' => [],
            ],
            [
                'slug' => 'wearable-computers',
                'icon' => 'term',
                'sort' => 2,
                'name' => [
                    'uz' => 'Taqiladigan terminallar',
                    'ru' => 'Носимые терминалы',
                    'en' => 'Wearable computers',
                ],
                'description' => [
                    'uz' => 'Qoʻlga taqiladigan terminallar va halqa skanerlar buyurtma yigʻuvchining ikki qoʻlini boʻshatadi va yigʻishni tezlashtiradi. Poson qurilmalarni WMS bilan ulab, omboringizda pilot loyiha oʻtkazishga yordam beradi.',
                    'ru' => 'Носимые терминалы на запястье и кольцевые сканеры освобождают обе руки комплектовщика и ускоряют сборку заказов. Poson подключит устройства к WMS и поможет провести пилот на вашем складе.',
                    'en' => 'Wrist-mounted computers and ring scanners keep both of the picker\'s hands free and speed up order picking. Poson connects the devices to your WMS and helps you run a pilot in your warehouse.',
                ],
                'meta_title' => [
                    'uz' => 'Taqiladigan terminallar va halqa skanerlar — Poson',
                    'ru' => 'Носимые терминалы и кольцевые сканеры — Poson',
                    'en' => 'Wearable computers and ring scanners — Poson',
                ],
                'meta_description' => [
                    'uz' => 'Buyurtma yigʻish uchun qoʻlga taqiladigan terminallar va halqa skanerlar: ikki qoʻl boʻsh. WMS bilan integratsiya, pilot loyiha, kafolat va servis.',
                    'ru' => 'Носимые терминалы и кольцевые сканеры для сборки заказов: обе руки свободны. Интеграция с WMS, пилотный проект на складе, гарантия и сервис Poson.',
                    'en' => 'Wearable wrist computers and ring scanners for hands-free order picking. WMS integration, a pilot in your warehouse, warranty and service by Poson.',
                ],
                'children' => [],
            ],
            [
                'slug' => 'rfid-mobile-computers',
                'icon' => 'term',
                'sort' => 3,
                'name' => [
                    'uz' => 'RFID oʻquvchili TSD',
                    'ru' => 'ТСД с RFID',
                    'en' => 'RFID mobile computers',
                ],
                'description' => [
                    'uz' => 'Oʻrnatilgan UHF RFID oʻquvchili terminallar koʻplab metkalarni bir vaqtda oʻqiydi: kiyim doʻkonlari, omborlar va asosiy vositalar inventarizatsiyasi uchun. Poson oʻqish quvvati va masofasini vazifangizga moslab sozlaydi.',
                    'ru' => 'Терминалы со встроенным UHF RFID-считывателем читают множество меток одновременно — для магазинов одежды, складов и инвентаризации основных средств. Poson настроит мощность и дальность считывания под вашу задачу.',
                    'en' => 'Mobile computers with a built-in UHF RFID reader read many tags at once, for apparel stores, warehouses and fixed-asset audits. Poson tunes the power and read range to your task.',
                ],
                'meta_title' => [
                    'uz' => 'RFID oʻquvchili TSD — Poson',
                    'ru' => 'ТСД с RFID-считывателем — Poson',
                    'en' => 'RFID mobile computers — Poson',
                ],
                'meta_description' => [
                    'uz' => 'Kiyim doʻkonlari, omborlar va asosiy vositalar inventarizatsiyasi uchun UHF RFID oʻquvchili TSD. Quvvat va masofani sozlash, integratsiya, servis.',
                    'ru' => 'ТСД со встроенным UHF RFID для магазинов одежды, складов и инвентаризации основных средств. Настройка мощности, интеграция, гарантия и сервис.',
                    'en' => 'Mobile computers with built-in UHF RFID for apparel retail, warehouses and fixed-asset audits. Read power tuning, integration, warranty and service.',
                ],
                'children' => [],
            ],
        ],
    ],

    // ─── 4. Labels and ribbons (consumables) ────────────────────────────────
    [
        'slug' => 'labels-ribbons',
        'icon' => 'label',
        'sort' => 4,
        'name' => [
            'uz' => 'Yorliqlar va ribbonlar',
            'ru' => 'Этикетки и риббоны',
            'en' => 'Labels and ribbons',
        ],
        'description' => [
            'uz' => 'Termo va termotransfer yorliqlar, ribbonlar va chek lentalari — printeringiz va vazifangizga mos sarf materiallari. Yorliq oʻlchami, materiali, yelim turi va gilza diametrini printer modeli hamda saqlash sharoitiga qarab tanlashga yordam beramiz. Poson yorliq va ribbonni bir-biriga moslab beradi, shunda chop etish sifati yuqori boʻladi va printer kallagi uzoqroq xizmat qiladi; muntazam buyurtmalar uchun ishonchli yetkazib berishni taʼminlaymiz.',
            'ru' => 'Термо- и термотрансферные этикетки, риббоны и чековая лента для задач маркировки и учёта. Поможем выбрать размер, материал, клей и диаметр втулки под ваш принтер и условия хранения товара. Poson подбирает этикетку и риббон в паре, чтобы печать была чёткой, а печатающая головка служила дольше, и обеспечивает надёжные поставки для регулярных заказов.',
            'en' => 'Direct thermal and thermal transfer labels, ribbons and receipt rolls for labelling and record-keeping. We help you choose size, face material, adhesive and core diameter for your printer and storage conditions. Poson matches label and ribbon as a pair for sharp print and longer printhead life, and keeps supply reliable for repeat orders.',
        ],
        'meta_title' => [
            'uz' => 'Yorliqlar, ribbonlar va chek lentasi — Poson',
            'ru' => 'Этикетки, риббоны и чековая лента — Poson',
            'en' => 'Labels, ribbons and receipt rolls — Poson',
        ],
        'meta_description' => [
            'uz' => 'Termo va termotransfer yorliqlar, wax, wax-resin va resin ribbonlar, chek lentalari. Printerga mos tanlash, kerakli oʻlchamlar, ishonchli yetkazib berish.',
            'ru' => 'Термо и термотрансферные этикетки, риббоны wax, wax-resin и resin, чековая лента. Подбор под ваш принтер, размеры под задачу и надёжные поставки.',
            'en' => 'Direct thermal and thermal transfer labels, wax, wax-resin and resin ribbons, receipt rolls. Matched to your printer, sized to your task, reliably supplied.',
        ],
        'children' => [
            [
                'slug' => 'thermal-labels',
                'icon' => 'label',
                'sort' => 1,
                'name' => [
                    'uz' => 'Termoyorliqlar',
                    'ru' => 'Термоэтикетки',
                    'en' => 'Direct thermal labels',
                ],
                'description' => [
                    'uz' => 'Ribbonsiz chop etiladigan yorliqlar: tarozilar, logistika va qisqa muddatli belgilash uchun. Saqlash muddati va sharoitiga qarab Eco, Top yoki boshqa termoqatlam turini tanlashga yordam beramiz.',
                    'ru' => 'Этикетки для печати без риббона: для весов, логистики и маркировки с коротким сроком хранения. Поможем выбрать тип термослоя — Eco, Top и другие — под срок и условия хранения.',
                    'en' => 'Labels printed without a ribbon, for scales, shipping and short-life labelling. We help you pick the thermal coating, such as Eco or Top, for the required shelf life and storage conditions.',
                ],
                'meta_title' => [
                    'uz' => 'Termoyorliqlar rulonda — Poson',
                    'ru' => 'Термоэтикетки в рулонах — Poson',
                    'en' => 'Direct thermal labels — Poson',
                ],
                'meta_description' => [
                    'uz' => 'Tarozi, logistika va ombor uchun termoyorliqlar: Eco va Top qatlam, turli oʻlcham va gilzalar. Printerga mos tanlash va ishonchli yetkazib berish.',
                    'ru' => 'Термоэтикетки в рулонах для весов, логистики и склада: термослой Eco и Top, разные размеры и втулки. Подбор под ваш принтер и надёжные поставки.',
                    'en' => 'Direct thermal labels for scales, shipping and warehouses: Eco and Top coatings, many sizes and core options. Matched to your printer, reliably supplied.',
                ],
                'children' => [],
            ],
            [
                'slug' => 'thermal-transfer-labels',
                'icon' => 'label',
                'sort' => 2,
                'name' => [
                    'uz' => 'Termotransfer yorliqlar',
                    'ru' => 'Термотрансферные этикетки',
                    'en' => 'Thermal transfer labels',
                ],
                'description' => [
                    'uz' => 'Ribbon orqali chop etiladigan qogʻoz va sintetik yorliqlar: uzoq saqlanadigan, namlik, ishqalanish yoki sovuqqa chidamli belgilash uchun. Poson yorliq materialiga mos ribbon turini tanlab beradi.',
                    'ru' => 'Бумажные и синтетические этикетки для печати через риббон, когда маркировка должна долго сохраняться и выдерживать влагу, истирание или холод. Poson подберёт тип риббона под материал этикетки.',
                    'en' => 'Paper and synthetic labels printed through a ribbon, for marking that has to last and withstand moisture, abrasion or cold. Poson pairs the right ribbon type with the label material.',
                ],
                'meta_title' => [
                    'uz' => 'Termotransfer yorliqlar — Poson',
                    'ru' => 'Термотрансферные этикетки — Poson',
                    'en' => 'Thermal transfer labels — Poson',
                ],
                'meta_description' => [
                    'uz' => 'Qogʻoz va sintetik termotransfer yorliqlar: polipropilen, polietilen va boshqalar. Mos ribbon tanlash, kerakli oʻlcham va ishonchli yetkazib berish.',
                    'ru' => 'Бумажные и синтетические термотрансферные этикетки: полипропилен, полиэтилен и другие. Подбор риббона в пару, размер под задачу и надёжные поставки.',
                    'en' => 'Paper and synthetic thermal transfer labels in polypropylene, polyethylene and more. Matching ribbon selection, the sizes you need and reliable supply.',
                ],
                'children' => [],
            ],
            [
                'slug' => 'ribbons',
                'icon' => 'label',
                'sort' => 3,
                'name' => [
                    'uz' => 'Ribbonlar',
                    'ru' => 'Риббоны',
                    'en' => 'Ribbons',
                ],
                'description' => [
                    'uz' => 'Termotransfer printerlar uchun wax, wax-resin va resin ribbonlar: qogʻoz yorliqlardan tortib kimyoviy taʼsirga chidamli sintetik yorliqlargacha. Poson printer modeli, yorliq materiali va boʻyoq qatlami tomoniga (IN yoki OUT) qarab mos ribbonni tanlaydi.',
                    'ru' => 'Риббоны wax, wax-resin и resin для термотрансферных принтеров — от бумажных этикеток до синтетики, стойкой к химии. Poson подберёт риббон по модели принтера, материалу этикетки и стороне красящего слоя (IN или OUT).',
                    'en' => 'Wax, wax-resin and resin ribbons for thermal transfer printers, from paper labels to chemical-resistant synthetics. Poson selects the ribbon by printer model, label material and ink side, IN or OUT.',
                ],
                'meta_title' => [
                    'uz' => 'Wax, wax-resin va resin ribbonlar — Poson',
                    'ru' => 'Риббоны wax, wax-resin и resin — Poson',
                    'en' => 'Wax, wax-resin and resin ribbons — Poson',
                ],
                'meta_description' => [
                    'uz' => 'Termotransfer printerlar uchun wax, wax-resin va resin ribbonlar, IN va OUT. Printer va yorliq materialiga mos tanlash, ishonchli yetkazib berish.',
                    'ru' => 'Риббоны wax, wax-resin и resin для термотрансферных принтеров, намотка IN и OUT. Подбор под принтер и материал этикетки, надёжные регулярные поставки.',
                    'en' => 'Wax, wax-resin and resin ribbons for thermal transfer printers, ink side IN or OUT. Selection by printer and label material, with reliable supply.',
                ],
                'children' => [],
            ],
            [
                'slug' => 'receipt-paper',
                'icon' => 'label',
                'sort' => 4,
                'name' => [
                    'uz' => 'Chek lentalari',
                    'ru' => 'Чековая лента',
                    'en' => 'Receipt paper',
                ],
                'description' => [
                    'uz' => 'Kassa apparatlari, POS terminallar va chek printerlari uchun 58 va 80 mm kenglikdagi termoqogʻoz lentalar. Poson chek printeringiz va kunlik cheklar soniga mos rulon oʻlchamini tanlab, muntazam yetkazib berishni tashkil qiladi.',
                    'ru' => 'Чековая лента из термобумаги шириной 58 и 80 мм для кассовых аппаратов, POS-терминалов и чековых принтеров. Poson подберёт размер рулона под ваш принтер и поток чеков и организует регулярные поставки.',
                    'en' => '58 mm and 80 mm thermal paper rolls for cash registers, POS terminals and receipt printers. Poson matches the roll size to your printer and receipt volume and arranges regular deliveries.',
                ],
                'meta_title' => [
                    'uz' => 'Chek lentasi 58 va 80 mm — Poson',
                    'ru' => 'Чековая лента 58 и 80 мм — Poson',
                    'en' => 'Receipt paper rolls 58 and 80 mm — Poson',
                ],
                'meta_description' => [
                    'uz' => 'Kassa apparatlari, POS terminallar va chek printerlari uchun 58 va 80 mm termoqogʻoz lenta. Rulon oʻlchamini tanlash va muntazam yetkazib berish.',
                    'ru' => 'Чековая лента из термобумаги 58 и 80 мм для кассовых аппаратов, POS-терминалов и чековых принтеров. Подбор размера рулона и регулярные поставки.',
                    'en' => '58 mm and 80 mm thermal receipt paper rolls for cash registers, POS terminals and receipt printers. Roll size selection and regular, reliable deliveries.',
                ],
                'children' => [],
            ],
        ],
    ],

    // ─── 5. RFID ────────────────────────────────────────────────────────────
    [
        'slug' => 'rfid',
        'icon' => 'rfid',
        'sort' => 5,
        'name' => [
            'uz' => 'RFID uskunalari',
            'ru' => 'RFID-оборудование',
            'en' => 'RFID equipment',
        ],
        'description' => [
            'uz' => 'UHF va HF diapazonidagi statsionar va qoʻl RFID oʻquvchilari, antennalar, metkalar va RFID printerlar. RFID bir vaqtda koʻplab metkani bevosita koʻrinmasdan oʻqiydi, shuning uchun omborda, kiyim doʻkonida va asosiy vositalar hisobida inventarizatsiya ancha tezlashadi. Chastota, oʻqish masofasi va metka turini obyekt materiali hamda ish muhitiga qarab tanlaymiz. Poson pilot loyihadan boshlab uskunani oʻrnatadi, antennalarni sozlaydi va RFID maʼlumotlarini hisob tizimingiz bilan bogʻlaydi.',
            'ru' => 'Стационарные и ручные RFID-считыватели UHF и HF, антенны, метки и RFID-принтеры. RFID читает множество меток одновременно и без прямой видимости, поэтому инвентаризация на складе, в магазине одежды и в учёте основных средств проходит значительно быстрее. Частоту, дальность считывания и тип метки подбираем под материал объекта и условия работы. Poson начинает с пилотного проекта, монтирует оборудование, настраивает антенны и связывает RFID-данные с вашей учётной системой.',
            'en' => 'Fixed and handheld UHF and HF RFID readers, antennas, tags and RFID printers. RFID reads many tags at once without line of sight, so stocktaking in warehouses, apparel stores and fixed-asset registers becomes much faster. We choose frequency, read range and tag type for the item material and environment. Poson starts with a pilot, installs the hardware, tunes the antennas and links RFID data to your accounting system.',
        ],
        'meta_title' => [
            'uz' => 'RFID uskunalari: oʻquvchilar va metkalar — Poson',
            'ru' => 'RFID-оборудование: считыватели и метки — Poson',
            'en' => 'RFID readers, antennas and tags — Poson',
        ],
        'meta_description' => [
            'uz' => 'UHF va HF RFID oʻquvchilari, antennalar, metkalar va printerlar: ombor, kiyim doʻkoni va asosiy vositalar uchun. Pilot loyiha, montaj va integratsiya.',
            'ru' => 'RFID-считыватели UHF и HF, антенны, метки и принтеры для склада, магазинов одежды и учёта основных средств. Пилотный проект, монтаж и интеграция.',
            'en' => 'UHF and HF RFID readers, antennas, tags and printers for warehouses, apparel retail and fixed-asset tracking. Pilot projects, installation and integration.',
        ],
        'children' => [
            [
                'slug' => 'fixed-rfid-readers',
                'icon' => 'rfid',
                'sort' => 1,
                'name' => [
                    'uz' => 'Statsionar RFID oʻquvchilar',
                    'ru' => 'Стационарные RFID-считыватели',
                    'en' => 'Fixed RFID readers',
                ],
                'description' => [
                    'uz' => 'Darvozalar, konveyerlar, ombor eshiklari va kassalarda metkalarni avtomatik oʻqish uchun koʻp portli oʻquvchilar. Poson antennalar joylashuvini rejalashtiradi, montaj qiladi va oʻqish zonasini sozlaydi.',
                    'ru' => 'Многопортовые считыватели для автоматического чтения меток на воротах, конвейерах, складских проёмах и кассах. Poson спланирует расстановку антенн, выполнит монтаж и настроит зону считывания.',
                    'en' => 'Multi-port readers that capture tags automatically at dock doors, conveyors, portals and checkouts. Poson plans antenna placement, installs the hardware and tunes the read zone.',
                ],
                'meta_title' => [
                    'uz' => 'Statsionar RFID oʻquvchilar — Poson',
                    'ru' => 'Стационарные RFID-считыватели — Poson',
                    'en' => 'Fixed RFID readers — Poson',
                ],
                'meta_description' => [
                    'uz' => 'Darvoza, konveyer va ombor eshiklari uchun koʻp portli statsionar RFID oʻquvchilar. Antenna joylashuvini rejalashtirish, montaj va oʻqish zonasini sozlash.',
                    'ru' => 'Многопортовые стационарные RFID-считыватели для ворот, конвейеров и складских проёмов. Расстановка антенн, монтаж и настройка зоны считывания.',
                    'en' => 'Multi-port fixed RFID readers for dock doors, conveyors and portals. Antenna placement planning, professional installation and read-zone tuning by Poson.',
                ],
                'children' => [],
            ],
            [
                'slug' => 'handheld-rfid-readers',
                'icon' => 'rfid',
                'sort' => 2,
                'name' => [
                    'uz' => 'Qoʻl RFID oʻquvchilari',
                    'ru' => 'Ручные RFID-считыватели',
                    'en' => 'Handheld RFID readers',
                ],
                'description' => [
                    'uz' => 'Smartfon yoki TSD bilan Bluetooth orqali ishlaydigan RFID qurilmalar (sled) va mustaqil qoʻl oʻquvchilari: inventarizatsiya va tovar qidirish uchun. Poson oʻquvchini ilovangiz bilan bogʻlab, quvvat sozlamalarini moslaydi.',
                    'ru' => 'Bluetooth-насадки (sled) для смартфона или ТСД и автономные ручные считыватели для инвентаризации и поиска товара. Poson свяжет считыватель с вашим приложением и настроит параметры мощности.',
                    'en' => 'Bluetooth sleds for smartphones or mobile computers and standalone handheld readers for stocktaking and item search. Poson connects the reader to your app and tunes the power settings.',
                ],
                'meta_title' => [
                    'uz' => 'Qoʻl RFID oʻquvchilari — Poson',
                    'ru' => 'Ручные RFID-считыватели — Poson',
                    'en' => 'Handheld RFID readers and sleds — Poson',
                ],
                'meta_description' => [
                    'uz' => 'Inventarizatsiya va tovar qidirish uchun qoʻl RFID oʻquvchilari va Bluetooth sled qurilmalar. Ilova bilan bogʻlash, sozlash, kafolat va servis.',
                    'ru' => 'Ручные RFID-считыватели и Bluetooth-насадки для инвентаризации и поиска товара. Связка с вашим приложением, настройка, гарантия и сервис Poson.',
                    'en' => 'Handheld RFID readers and Bluetooth sleds for stocktaking and item search. Connection to your app, power tuning, warranty and service by Poson.',
                ],
                'children' => [],
            ],
            [
                'slug' => 'rfid-antennas',
                'icon' => 'rfid',
                'sort' => 3,
                'name' => [
                    'uz' => 'RFID antennalar',
                    'ru' => 'RFID-антенны',
                    'en' => 'RFID antennas',
                ],
                'description' => [
                    'uz' => 'Statsionar oʻquvchilar uchun chiziqli va doiraviy qutblanishli antennalar: darvozalar, javonlar va konveyerlar uchun. Poson oʻqish zonasini oʻlchab, antennalar soni va joylashuvini hisoblaydi.',
                    'ru' => 'Антенны с линейной и круговой поляризацией для стационарных считывателей — на ворота, полки и конвейеры. Poson замерит зону считывания и рассчитает количество и расстановку антенн.',
                    'en' => 'Linear and circular polarised antennas for fixed readers on portals, shelves and conveyors. Poson surveys the read zone and works out how many antennas you need and where they go.',
                ],
                'meta_title' => [
                    'uz' => 'RFID antennalar — Poson',
                    'ru' => 'RFID-антенны — Poson',
                    'en' => 'RFID antennas — Poson',
                ],
                'meta_description' => [
                    'uz' => 'Statsionar RFID oʻquvchilar uchun chiziqli va doiraviy qutblanishli antennalar. Oʻqish zonasini oʻlchash, antennalar joylashuvini hisoblash va montaj.',
                    'ru' => 'Антенны с линейной и круговой поляризацией для стационарных RFID-считывателей. Замер зоны считывания, расчёт расстановки и монтаж специалистами Poson.',
                    'en' => 'Linear and circular polarised antennas for fixed RFID readers. Read-zone survey, antenna placement planning and installation by Poson engineers.',
                ],
                'children' => [],
            ],
            [
                'slug' => 'rfid-tags',
                'icon' => 'rfid',
                'sort' => 4,
                'name' => [
                    'uz' => 'RFID metkalar va inleylar',
                    'ru' => 'RFID-метки и инлеи',
                    'en' => 'RFID tags and inlays',
                ],
                'description' => [
                    'uz' => 'Kiyim, karton, plastik va metall yuzalar uchun UHF va HF metkalar, RFID yorliqlar va inleylar. Poson metkani obyekt materiali va oʻqish masofasiga qarab tanlashga yordam beradi hamda sinov namunalarini taqdim etadi.',
                    'ru' => 'UHF и HF метки, RFID-этикетки и инлеи для одежды, картона, пластика и металлических поверхностей. Poson подберёт метку под материал объекта и дальность считывания и предоставит тестовые образцы.',
                    'en' => 'UHF and HF tags, RFID labels and inlays for apparel, cartons, plastic and metal surfaces. Poson helps choose the tag for the item material and read range and can provide test samples.',
                ],
                'meta_title' => [
                    'uz' => 'RFID metkalar va inleylar — Poson',
                    'ru' => 'RFID-метки и инлеи — Poson',
                    'en' => 'RFID tags, labels and inlays — Poson',
                ],
                'meta_description' => [
                    'uz' => 'Kiyim, karton, plastik va metall uchun UHF va HF RFID metkalar, yorliqlar va inleylar. Material va oʻqish masofasiga qarab tanlash, sinov namunalari.',
                    'ru' => 'UHF и HF RFID-метки, этикетки и инлеи для одежды, картона, пластика и металла. Подбор под материал и дальность считывания, тестовые образцы и поставки.',
                    'en' => 'UHF and HF RFID tags, labels and inlays for apparel, cartons, plastic and metal. Selection by material and read range, with test samples available.',
                ],
                'children' => [],
            ],
            [
                'slug' => 'rfid-printers',
                'icon' => 'print',
                'sort' => 5,
                'name' => [
                    'uz' => 'RFID printerlar',
                    'ru' => 'RFID-принтеры',
                    'en' => 'RFID printers',
                ],
                'description' => [
                    'uz' => 'Yorliqni chop etish bilan bir vaqtda RFID metkani kodlaydigan printerlar: kiyim, logistika va ishlab chiqarish uchun. Poson kodlash shablonlarini tayyorlaydi va printerni hisob tizimingizga ulaydi.',
                    'ru' => 'Принтеры, которые одновременно печатают этикетку и кодируют RFID-метку, — для одежды, логистики и производства. Poson подготовит шаблоны кодирования и подключит принтер к учётной системе.',
                    'en' => 'Printers that print the label and encode the RFID inlay in one pass, for apparel, logistics and manufacturing. Poson prepares encoding templates and connects the printer to your system.',
                ],
                'meta_title' => [
                    'uz' => 'RFID yorliq printerlari — Poson',
                    'ru' => 'RFID-принтеры этикеток — Poson',
                    'en' => 'RFID label printers — Poson',
                ],
                'meta_description' => [
                    'uz' => 'Yorliqni chop etib, RFID metkani bir vaqtda kodlaydigan printerlar. Kodlash shablonlari, hisob tizimiga ulash, rasmiy kafolat va servis markazi.',
                    'ru' => 'Принтеры, которые печатают этикетку и кодируют RFID-метку за один проход. Шаблоны кодирования, интеграция с учётной системой, гарантия и сервис.',
                    'en' => 'RFID printers that print the label and encode the tag in a single pass. Encoding templates, integration with your system, warranty and service.',
                ],
                'children' => [],
            ],
        ],
    ],

    // ─── 6. POS equipment ───────────────────────────────────────────────────
    [
        'slug' => 'pos',
        'icon' => 'pos',
        'sort' => 6,
        'name' => [
            'uz' => 'POS uskunalari',
            'ru' => 'POS-оборудование',
            'en' => 'POS equipment',
        ],
        'description' => [
            'uz' => 'Doʻkon, dorixona, kafe va restoran kassalari uchun POS terminallar, chek printerlari, pul yashiklari, xaridor displeylari va yorliq chop etuvchi tarozilar. Kassa joyini toʻliq jamlaymiz va har bir qurilma kassa dasturingiz bilan mos ishlashini tekshiramiz. Poson uskunani oʻrnatadi, kassa dasturi va 1C bilan integratsiya qiladi hamda kafolat davomida oʻz servis markazida xizmat koʻrsatadi.',
            'ru' => 'POS-терминалы, чековые принтеры, денежные ящики, дисплеи покупателя и весы с печатью этикеток для магазинов, аптек, кафе и ресторанов. Комплектуем кассовое место целиком и проверяем совместимость каждого устройства с вашей кассовой программой. Poson устанавливает оборудование, интегрирует его с кассовой программой и 1С и обслуживает по гарантии в собственном сервисном центре.',
            'en' => 'POS terminals, receipt printers, cash drawers, customer displays and label-printing scales for shops, pharmacies, cafés and restaurants. We put together the complete checkout station and check that every device works with your POS software. Poson installs the equipment, integrates it with your POS software and 1C, and provides warranty service in its own service centre.',
        ],
        'meta_title' => [
            'uz' => 'POS uskunalari: kassa joyini jihozlash — Poson',
            'ru' => 'POS-оборудование для кассового места — Poson',
            'en' => 'POS equipment for checkout stations — Poson',
        ],
        'meta_description' => [
            'uz' => 'POS terminallar, chek printerlari, pul yashiklari, displeylar va tarozilar doʻkon, dorixona va HoReCa uchun. Oʻrnatish, integratsiya va servis.',
            'ru' => 'POS-терминалы, чековые принтеры, денежные ящики, дисплеи и весы для магазинов, аптек и HoReCa. Установка, интеграция с кассовой программой, сервис.',
            'en' => 'POS terminals, receipt printers, cash drawers, customer displays and scales for retail, pharmacies and HoReCa. Installation, integration and service.',
        ],
        'children' => [
            [
                'slug' => 'pos-terminals',
                'icon' => 'pos',
                'sort' => 1,
                'name' => [
                    'uz' => 'POS terminallar',
                    'ru' => 'POS-терминалы',
                    'en' => 'POS terminals',
                ],
                'description' => [
                    'uz' => 'Kassa dasturi uchun sensorli ekranli monobloklar va Android terminallar: chakana savdo, dorixona va HoReCa uchun. Poson dasturni oʻrnatadi, periferiyani ulaydi va kassirlarni oʻqitadi.',
                    'ru' => 'Сенсорные моноблоки и терминалы на Android для кассовой программы — в рознице, аптеках и HoReCa. Poson установит ПО, подключит периферию и обучит кассиров.',
                    'en' => 'Touchscreen all-in-one and Android terminals that run your POS software in retail, pharmacies and HoReCa. Poson installs the software, connects the peripherals and trains your cashiers.',
                ],
                'meta_title' => [
                    'uz' => 'POS terminallar va monobloklar — Poson',
                    'ru' => 'POS-терминалы и моноблоки — Poson',
                    'en' => 'POS terminals and all-in-ones — Poson',
                ],
                'meta_description' => [
                    'uz' => 'Doʻkon, dorixona va HoReCa uchun sensorli POS monobloklar va Android terminallar. Dasturni oʻrnatish, periferiyani ulash, kassirlarni oʻqitish va servis.',
                    'ru' => 'Сенсорные POS-моноблоки и терминалы на Android для магазинов, аптек и HoReCa. Установка ПО, подключение периферии, обучение кассиров и сервис.',
                    'en' => 'Touchscreen POS all-in-ones and Android terminals for retail, pharmacies and HoReCa. Software installation, peripheral setup, cashier training, service.',
                ],
                'children' => [],
            ],
            [
                'slug' => 'receipt-printers',
                'icon' => 'print',
                'sort' => 2,
                'name' => [
                    'uz' => 'Chek printerlari',
                    'ru' => 'Чековые принтеры',
                    'en' => 'Receipt printers',
                ],
                'description' => [
                    'uz' => 'Kassa, oshxona va buyurtma chiptalari uchun 58 va 80 mm kenglikdagi termoprinterlar, avtokesgichli va avtokesgichsiz. Poson printerni kassa dasturi va pul yashigi bilan ulaydi hamda chek shablonini sozlaydi.',
                    'ru' => 'Термопринтеры с шириной печати 58 и 80 мм для кассы, кухни и печати заказов, с автоотрезчиком и без. Poson подключит принтер к кассовой программе и денежному ящику и настроит шаблон чека.',
                    'en' => '58 mm and 80 mm thermal printers for checkouts, kitchens and order tickets, with or without an auto-cutter. Poson connects the printer to your POS software and cash drawer and sets up the receipt layout.',
                ],
                'meta_title' => [
                    'uz' => 'Chek printerlari 58 va 80 mm — Poson',
                    'ru' => 'Чековые принтеры 58 и 80 мм — Poson',
                    'en' => 'Receipt printers 58 and 80 mm — Poson',
                ],
                'meta_description' => [
                    'uz' => 'Kassa va oshxona uchun 58 va 80 mm chek printerlari: avtokesgich, USB, Ethernet, Bluetooth. Kassa dasturiga ulash, sozlash, rasmiy kafolat va servis.',
                    'ru' => 'Чековые принтеры 58 и 80 мм для кассы и кухни: автоотрезчик, USB, Ethernet, Bluetooth. Подключение к кассовой программе, официальная гарантия и сервис.',
                    'en' => '58 mm and 80 mm receipt printers for checkouts and kitchens: auto-cutter, USB, Ethernet, Bluetooth. POS software setup, official warranty and service.',
                ],
                'children' => [],
            ],
            [
                'slug' => 'cash-drawers',
                'icon' => 'pos',
                'sort' => 3,
                'name' => [
                    'uz' => 'Pul yashiklari',
                    'ru' => 'Денежные ящики',
                    'en' => 'Cash drawers',
                ],
                'description' => [
                    'uz' => 'Chek printeri yoki POS terminal buyrugʻi bilan ochiladigan, kupyura va tangalar uchun boʻlimli metall pul yashiklari. Poson yashikni ulaydi va chek chop etilganda avtomatik ochilishini sozlaydi.',
                    'ru' => 'Металлические денежные ящики с отделениями для купюр и монет, открываются по команде от чекового принтера или POS-терминала. Poson подключит ящик и настроит автоматическое открытие при печати чека.',
                    'en' => 'Steel cash drawers with note and coin compartments that open on a signal from the receipt printer or POS terminal. Poson connects the drawer and sets it to open automatically when a receipt prints.',
                ],
                'meta_title' => [
                    'uz' => 'Pul yashiklari — Poson',
                    'ru' => 'Денежные ящики для кассы — Poson',
                    'en' => 'Cash drawers — Poson',
                ],
                'meta_description' => [
                    'uz' => 'Kupyura va tanga boʻlimli metall pul yashiklari, chek printeri yoki POS terminaldan ochiladi. Ulash, avtomatik ochilishni sozlash, kafolat va servis.',
                    'ru' => 'Металлические денежные ящики с отделениями для купюр и монет, открытие от чекового принтера или POS-терминала. Подключение, настройка и сервис.',
                    'en' => 'Steel cash drawers with note and coin compartments, opened by the receipt printer or POS terminal. Connection, auto-open setup, warranty and service.',
                ],
                'children' => [],
            ],
            [
                'slug' => 'customer-displays',
                'icon' => 'pos',
                'sort' => 4,
                'name' => [
                    'uz' => 'Xaridor displeylari',
                    'ru' => 'Дисплеи покупателя',
                    'en' => 'Customer displays',
                ],
                'description' => [
                    'uz' => 'Xaridorga xarid tafsilotlarini koʻrsatadigan matnli va grafik displeylar hamda ikkinchi ekranlar. Poson displeyni kassa dasturiga ulab, kerakli maʼlumotlar chiqishini sozlaydi.',
                    'ru' => 'Текстовые и графические дисплеи покупателя и вторые экраны, на которых покупатель видит состав покупки. Poson подключит дисплей к кассовой программе и настроит вывод информации.',
                    'en' => 'Text and graphic customer displays and second screens that show shoppers the details of their purchase. Poson connects the display to your POS software and sets up what it shows.',
                ],
                'meta_title' => [
                    'uz' => 'Xaridor displeylari — Poson',
                    'ru' => 'Дисплеи покупателя — Poson',
                    'en' => 'Customer displays — Poson',
                ],
                'meta_description' => [
                    'uz' => 'Kassa uchun matnli va grafik xaridor displeylari hamda ikkinchi ekranlar. Kassa dasturiga ulash, maʼlumot chiqarishni sozlash, kafolat va servis.',
                    'ru' => 'Текстовые и графические дисплеи покупателя и вторые экраны для кассы. Подключение к кассовой программе, настройка вывода, гарантия и сервис Poson.',
                    'en' => 'Text and graphic customer displays and second screens for checkouts. Connection to your POS software, display setup, warranty and service by Poson.',
                ],
                'children' => [],
            ],
            [
                'slug' => 'scales',
                'icon' => 'pos',
                'sort' => 5,
                'name' => [
                    'uz' => 'Yorliq chop etuvchi tarozilar',
                    'ru' => 'Весы с печатью этикеток',
                    'en' => 'Label-printing scales',
                ],
                'description' => [
                    'uz' => 'Tortib sotiladigan tovarlar uchun yorliq chop etuvchi va kassa tarozilari: oziq-ovqat doʻkonlari va supermarketlar uchun. Poson tovar bazasini yuklaydi, yorliq shablonini sozlaydi va tarozini hisob tizimi bilan bogʻlaydi.',
                    'ru' => 'Весы с печатью этикеток и кассовые весы для весового товара в продуктовых магазинах и супермаркетах. Poson загрузит товарную базу, настроит шаблон этикетки и свяжет весы с учётной системой.',
                    'en' => 'Label-printing and checkout scales for weighed goods in grocery stores and supermarkets. Poson loads the product database, sets up the label template and links the scales to your accounting system.',
                ],
                'meta_title' => [
                    'uz' => 'Yorliq chop etuvchi tarozilar — Poson',
                    'ru' => 'Весы с печатью этикеток — Poson',
                    'en' => 'Label-printing scales — Poson',
                ],
                'meta_description' => [
                    'uz' => 'Oziq-ovqat doʻkonlari va supermarketlar uchun yorliq chop etuvchi va kassa tarozilari. Tovar bazasini yuklash, shablon sozlash, 1C bilan bogʻlash.',
                    'ru' => 'Весы с печатью этикеток и кассовые весы для продуктовых магазинов и супермаркетов. Загрузка товарной базы, шаблоны этикеток, связь с 1С и сервис.',
                    'en' => 'Label-printing and checkout scales for grocery stores and supermarkets. Product database upload, label templates, 1C integration and service by Poson.',
                ],
                'children' => [],
            ],
        ],
    ],

    // ─── 7. Software ────────────────────────────────────────────────────────
    [
        'slug' => 'software',
        'icon' => null,
        'sort' => 7,
        'name' => [
            'uz' => 'Dasturiy taʼminot',
            'ru' => 'Программное обеспечение',
            'en' => 'Software',
        ],
        'description' => [
            'uz' => 'Ombor hisobi, kassa, yorliq dizayni va mobil qurilmalarni boshqarish (MDM) uchun dasturlar va litsenziyalar. Uskunangiz va hisob tizimingizga mos dasturni tanlaymiz, shunda skaner, printer va TSD yagona tizim sifatida ishlaydi. Poson litsenziyani faollashtiradi, dasturni oʻrnatadi, 1C bilan integratsiyani sozlaydi va xodimlaringizni oʻqitadi.',
            'ru' => 'Программы и лицензии для складского учёта, кассы, дизайна этикеток и управления мобильными устройствами (MDM). Подбираем ПО под ваше оборудование и учётную систему, чтобы сканеры, принтеры и ТСД работали как единое целое. Poson активирует лицензии, устанавливает и настраивает программы, выполняет интеграцию с 1С и обучает сотрудников.',
            'en' => 'Software and licences for inventory management, POS, label design and mobile device management (MDM). We pick software that fits your hardware and accounting system so scanners, printers and mobile computers work as one. Poson activates licences, installs and configures the software, integrates it with 1C and trains your staff.',
        ],
        'meta_title' => [
            'uz' => 'Dasturiy taʼminot va litsenziyalar — Poson',
            'ru' => 'Программное обеспечение и лицензии — Poson',
            'en' => 'Software and licences — Poson',
        ],
        'meta_description' => [
            'uz' => 'Ombor hisobi, kassa, yorliq dizayni va MDM uchun dasturlar va litsenziyalar. Oʻrnatish, 1C bilan integratsiya, xodimlarni oʻqitish va qoʻllab-quvvatlash.',
            'ru' => 'Программы для складского учёта, кассы, дизайна этикеток и MDM, лицензии. Установка, интеграция с 1С, обучение сотрудников и техподдержка Poson.',
            'en' => 'Inventory, POS, label design and MDM software and licences. Installation, 1C integration, staff training and ongoing support from Poson engineers.',
        ],
        'children' => [
            [
                'slug' => 'warehouse-software',
                'icon' => null,
                'sort' => 1,
                'name' => [
                    'uz' => 'Ombor hisobi dasturlari',
                    'ru' => 'ПО для складского учёта',
                    'en' => 'Warehouse software',
                ],
                'description' => [
                    'uz' => 'TSD uchun tovar qabul qilish, joylashtirish, inventarizatsiya va buyurtma yigʻish ilovalari hamda WMS yechimlari. Poson dasturni 1C yoki ERP bilan bogʻlab, ombor jarayonlaringizga moslaydi.',
                    'ru' => 'Мобильные приложения для ТСД и WMS-решения для приёмки, размещения, инвентаризации и сборки заказов. Poson свяжет программу с 1С или ERP и настроит под ваши складские процессы.',
                    'en' => 'Mobile apps for handhelds and WMS solutions covering receiving, putaway, stocktaking and order picking. Poson links the software to 1C or your ERP and tailors it to your warehouse processes.',
                ],
                'meta_title' => [
                    'uz' => 'Ombor hisobi dasturlari va WMS — Poson',
                    'ru' => 'ПО для склада и WMS — Poson',
                    'en' => 'Warehouse and inventory software — Poson',
                ],
                'meta_description' => [
                    'uz' => 'TSD uchun ombor ilovalari va WMS: qabul, joylashtirish, inventarizatsiya, buyurtma yigʻish. 1C va ERP bilan integratsiya, sozlash va oʻqitish.',
                    'ru' => 'Приложения для ТСД и WMS: приёмка, размещение, инвентаризация, сборка заказов. Интеграция с 1С и ERP, настройка процессов и обучение персонала.',
                    'en' => 'Mobile apps for handhelds and WMS for receiving, putaway, stocktaking and picking. 1C and ERP integration, process setup and staff training by Poson.',
                ],
                'children' => [],
            ],
            [
                'slug' => 'pos-software',
                'icon' => null,
                'sort' => 2,
                'name' => [
                    'uz' => 'Kassa dasturlari',
                    'ru' => 'Кассовые программы',
                    'en' => 'POS software',
                ],
                'description' => [
                    'uz' => 'Doʻkon, dorixona va HoReCa uchun kassa dasturlari: tovar hisobi, kassa operatsiyalari va hisobotlar. Poson dasturni POS uskunasi bilan birga oʻrnatadi va kassirlarni oʻqitadi.',
                    'ru' => 'Кассовые программы для магазинов, аптек и HoReCa: товарный учёт, кассовые операции и отчёты. Poson установит программу вместе с POS-оборудованием и обучит кассиров.',
                    'en' => 'POS software for shops, pharmacies and HoReCa covering stock records, checkout operations and reports. Poson installs it together with the POS hardware and trains your cashiers.',
                ],
                'meta_title' => [
                    'uz' => 'Kassa dasturlari — Poson',
                    'ru' => 'Кассовые программы — Poson',
                    'en' => 'POS software — Poson',
                ],
                'meta_description' => [
                    'uz' => 'Doʻkon, dorixona va HoReCa uchun kassa dasturlari: tovar hisobi va hisobotlar. POS uskunasi bilan oʻrnatish, sozlash, kassirlarni oʻqitish va yordam.',
                    'ru' => 'Кассовые программы для магазинов, аптек и HoReCa: товарный учёт и отчёты. Установка вместе с POS-оборудованием, настройка, обучение кассиров и поддержка.',
                    'en' => 'POS software for shops, pharmacies and HoReCa with stock records and reports. Installation with your POS hardware, setup and cashier training.',
                ],
                'children' => [],
            ],
            [
                'slug' => 'label-design-software',
                'icon' => null,
                'sort' => 3,
                'name' => [
                    'uz' => 'Yorliq dizayni dasturlari',
                    'ru' => 'Программы для дизайна этикеток',
                    'en' => 'Label design software',
                ],
                'description' => [
                    'uz' => 'Shtrix-kod, DataMatrix va maʼlumotlar bazasidan olinadigan maydonlar bilan yorliq shablonlarini yaratish va chop etish uchun dasturlar. Poson shablonlarni tayyorlaydi va dasturni hisob tizimingiz bilan bogʻlaydi.',
                    'ru' => 'Программы для создания и печати шаблонов этикеток со штрих-кодами, DataMatrix и полями из базы данных. Poson подготовит шаблоны и свяжет программу с вашей учётной системой.',
                    'en' => 'Software for designing and printing label templates with barcodes, DataMatrix and fields pulled from a database. Poson prepares the templates and links the software to your accounting system.',
                ],
                'meta_title' => [
                    'uz' => 'Yorliq dizayni dasturlari — Poson',
                    'ru' => 'Программы для дизайна этикеток — Poson',
                    'en' => 'Label design software — Poson',
                ],
                'meta_description' => [
                    'uz' => 'Shtrix-kod, DataMatrix va bazadan olingan maydonlar bilan yorliq yaratish dasturlari. Shablon tayyorlash, 1C bilan bogʻlash, litsenziya va oʻqitish.',
                    'ru' => 'Программы для дизайна этикеток со штрих-кодами, DataMatrix и полями из базы данных. Подготовка шаблонов, связь с 1С, лицензии и обучение сотрудников.',
                    'en' => 'Label design software with barcodes, DataMatrix and database-driven fields. Template preparation, 1C connection, licensing and training by Poson.',
                ],
                'children' => [],
            ],
            [
                'slug' => 'mdm-software',
                'icon' => null,
                'sort' => 4,
                'name' => [
                    'uz' => 'MDM — qurilmalarni boshqarish',
                    'ru' => 'MDM — управление устройствами',
                    'en' => 'MDM software',
                ],
                'description' => [
                    'uz' => 'TSD va planshetlar parkini markazlashgan holda boshqarish uchun MDM litsenziyalari: sozlamalar, ilovalar, yangilanishlar va kiosk rejimi. Poson qurilmalarni roʻyxatga oladi va boshqaruv siyosatlarini sozlaydi.',
                    'ru' => 'Лицензии MDM для централизованного управления парком ТСД и планшетов: настройки, приложения, обновления и режим киоска. Poson зарегистрирует устройства и настроит политики.',
                    'en' => 'MDM licences for central management of mobile computer and tablet fleets: settings, apps, updates and kiosk mode. Poson enrols the devices and configures the policies.',
                ],
                'meta_title' => [
                    'uz' => 'MDM: TSD parkini boshqarish — Poson',
                    'ru' => 'MDM: управление парком ТСД — Poson',
                    'en' => 'MDM for mobile computer fleets — Poson',
                ],
                'meta_description' => [
                    'uz' => 'TSD va planshetlar parkini markazlashgan boshqarish uchun MDM: sozlamalar, ilovalar, yangilanishlar, kiosk rejimi. Roʻyxatga olish va sozlash.',
                    'ru' => 'MDM для централизованного управления парком ТСД и планшетов: настройки, приложения, обновления, киоск-режим. Регистрация устройств и настройка.',
                    'en' => 'MDM for central management of mobile computer and tablet fleets: settings, apps, updates, kiosk mode. Device enrolment and policy setup by Poson.',
                ],
                'children' => [],
            ],
        ],
    ],

    // ─── 8. Services ────────────────────────────────────────────────────────
    [
        'slug' => 'services',
        'icon' => null,
        'sort' => 8,
        'name' => [
            'uz' => 'Servis xizmatlari',
            'ru' => 'Сервисные услуги',
            'en' => 'Services',
        ],
        'description' => [
            'uz' => 'Poson nafaqat uskuna yetkazib beradi, balki uning butun xizmat muddati davomida ishonchli ishlashini taʼminlaydi. Muhandislarimiz skanerlar, yorliq printerlari, TSD, RFID va POS uskunalarini oʻrnatadi, 1C, WMS va markirovka tizimlari bilan integratsiya qiladi hamda xodimlaringizni oʻqitadi. Oʻz servis markazimizda diagnostika, kafolatli va kafolatdan keyingi taʼmirlash hamda rejali profilaktika bajariladi, muddatlar esa oldindan kelishiladi.',
            'ru' => 'Poson не только поставляет оборудование, но и отвечает за его надёжную работу на всём сроке службы. Наши инженеры устанавливают сканеры, принтеры этикеток, ТСД, RFID- и POS-оборудование, интегрируют его с 1С, WMS и системами маркировки и обучают ваших сотрудников. В собственном сервисном центре мы проводим диагностику, гарантийный и постгарантийный ремонт и плановую профилактику, а сроки согласуем заранее.',
            'en' => 'Poson does more than supply equipment: we keep it running reliably for its whole service life. Our engineers install scanners, label printers, mobile computers, RFID and POS hardware, integrate it with 1C, WMS and marking systems and train your staff. Our own service centre handles diagnostics, in-warranty and post-warranty repair and scheduled preventive maintenance, with timelines agreed up front.',
        ],
        'meta_title' => [
            'uz' => 'Servis: oʻrnatish, sozlash va taʼmirlash — Poson',
            'ru' => 'Сервис: установка, настройка и ремонт — Poson',
            'en' => 'Services: setup, integration and repair — Poson',
        ],
        'meta_description' => [
            'uz' => 'Auto-ID uskunalari uchun Poson servisi: oʻrnatish, sozlash, 1C va WMS bilan integratsiya, diagnostika, taʼmirlash va oʻz servis markazimizda profilaktika.',
            'ru' => 'Сервис Poson для Auto-ID оборудования: установка, настройка, интеграция с 1С и WMS, диагностика, ремонт и обслуживание в собственном сервисном центре.',
            'en' => 'Poson service for Auto-ID equipment: installation, setup and integration with 1C and WMS, diagnostics, repair and maintenance in our own service centre.',
        ],
        'children' => [
            [
                'slug' => 'setup-integration',
                'icon' => null,
                'sort' => 1,
                'name' => [
                    'uz' => 'Oʻrnatish va integratsiya',
                    'ru' => 'Установка и интеграция',
                    'en' => 'Setup and integration',
                ],
                'description' => [
                    'uz' => 'Uskunani oʻrnatish, sozlash va hisob tizimingiz bilan bogʻlash: skanerlarni dasturlash, yorliq printerlarini kalibrlash va shablonlar, TSDga ilovalar va MDM, kassa joyini toʻliq ishga tushirish. Poson muhandislari ishni ofisingizda yoki masofadan bajaradi va xodimlarni oʻqitadi.',
                    'ru' => 'Установка, настройка и связь оборудования с вашей учётной системой: программирование сканеров, калибровка и шаблоны для принтеров этикеток, приложения и MDM для ТСД, запуск кассового места под ключ. Инженеры Poson работают у вас на месте или удалённо и обучают сотрудников.',
                    'en' => 'Installing, configuring and connecting equipment to your accounting system: scanner programming, label printer calibration and templates, apps and MDM for mobile computers, and complete checkout commissioning. Poson engineers work on site or remotely and train your staff.',
                ],
                'meta_title' => [
                    'uz' => 'Uskunani oʻrnatish va integratsiya — Poson',
                    'ru' => 'Установка и интеграция оборудования — Poson',
                    'en' => 'Equipment setup and integration — Poson',
                ],
                'meta_description' => [
                    'uz' => 'Skaner, yorliq printeri, TSD va POS uskunalarini Poson muhandislari oʻrnatadi: 1C, WMS va markirovka bilan integratsiya hamda xodimlarni oʻqitish.',
                    'ru' => 'Установка и настройка сканеров, принтеров этикеток, ТСД и POS инженерами Poson: интеграция с 1С, WMS и системой маркировки, обучение сотрудников.',
                    'en' => 'Installation and setup of scanners, label printers, mobile computers and POS by Poson engineers: integration with 1C, WMS and marking, plus staff training.',
                ],
                'children' => [],
            ],
            [
                'slug' => 'repair-maintenance',
                'icon' => null,
                'sort' => 2,
                'name' => [
                    'uz' => 'Taʼmirlash va texnik xizmat',
                    'ru' => 'Ремонт и обслуживание',
                    'en' => 'Repair and maintenance',
                ],
                'description' => [
                    'uz' => 'Skanerlar, printerlar, TSD va POS uskunalarini diagnostika qilish, kafolatli va kafolatdan keyingi taʼmirlash hamda rejali profilaktika. Poson servis markazi nosozlik sababini aniqlaydi, taʼmirlash rejasi va muddatlarini yozma ravishda beradi va almashtirish fondini tashkil qilishga yordam beradi.',
                    'ru' => 'Диагностика, гарантийный и постгарантийный ремонт и плановая профилактика сканеров, принтеров, ТСД и POS-оборудования. Сервисный центр Poson найдёт причину неисправности, письменно согласует план и сроки ремонта и поможет организовать подменный фонд.',
                    'en' => 'Diagnostics, in-warranty and post-warranty repair and scheduled preventive maintenance for scanners, printers, mobile computers and POS hardware. The Poson service centre finds the root cause, agrees the repair plan and timeline in writing and helps you set up a pool of swap devices.',
                ],
                'meta_title' => [
                    'uz' => 'Uskunani taʼmirlash va texnik xizmat — Poson',
                    'ru' => 'Ремонт и обслуживание оборудования — Poson',
                    'en' => 'Equipment repair and maintenance — Poson',
                ],
                'meta_description' => [
                    'uz' => 'Skaner, printer va TSD diagnostikasi, kafolatli va kafolatdan keyingi taʼmirlash hamda profilaktika Poson servis markazida. Muddatlar oldindan aytiladi.',
                    'ru' => 'Диагностика, гарантийный и постгарантийный ремонт и профилактика сканеров, принтеров и ТСД в сервисном центре Poson. Сроки ремонта называем заранее.',
                    'en' => 'Diagnostics, warranty and post-warranty repair and preventive maintenance of scanners, printers and mobile computers at the Poson service centre.',
                ],
                'children' => [],
            ],
        ],
    ],

];
