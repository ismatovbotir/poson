<?php

/**
 * Real products, group "scanners" (scanners, TSD, RFID TSD, accessories).
 * Source: .claude/docs/pm/import/products-base.json (group = scanners), 48 rows.
 *
 * Row shape = products.php plus `has_missing_specs` (bool).
 * Prices are whole soʻm (the seeder converts to tiyin); null = price on request.
 * Specs are limited to what manufacturer/reseller pages or the 1C name prove;
 * unverified attributes are left out and the row gets has_missing_specs = true.
 *
 * @return list<array<string, mixed>>
 */
$k = static fn (mixed $value): array => ['value' => $value, 'key' => true];

$sym2d = ['1D (EAN, UPC, Code 128)', 'QR Code', 'DataMatrix', 'PDF417', 'GS1 DataBar', 'Aztec'];

$service = [
    'scanner' => [
        'uz' => 'Poson skanerni kassa dasturi yoki 1C ga ulaydi, kerakli kod turlari, prefiks va suffikslarni sozlaydi. Kafolat xizmati va taʼmirlash — oʻz servis markazimizda.',
        'ru' => 'Poson подключит сканер к кассовой программе или 1С и настроит нужные типы кодов, префиксы и суффиксы. Гарантийное обслуживание и ремонт — в нашем сервисном центре.',
        'en' => 'Poson connects the scanner to your POS software or 1C and configures symbologies, prefixes and suffixes. Warranty service and repair are handled by our own service center.',
    ],
    'tsd' => [
        'uz' => 'Poson terminallarni Wi-Fi tarmogʻiga ulaydi, ombor ilovasini oʻrnatadi va 1C bilan almashinuvni sozlaydi. Kafolat xizmati va taʼmirlash — oʻz servis markazimizda.',
        'ru' => 'Poson подключит терминалы к Wi-Fi, установит складское приложение и настроит обмен с 1С. Гарантийное обслуживание и ремонт — в нашем сервисном центре.',
        'en' => 'Poson connects the terminals to Wi-Fi, installs your warehouse app and sets up data exchange with 1C. Warranty service and repair are handled by our own service center.',
    ],
    'accessory' => [
        'uz' => 'Poson aksessuarning qurilma modeliga mosligini tekshiradi, kerak boʻlsa oʻrnatish va sozlashda yordam beradi hamda keyingi servisni taʼminlaydi.',
        'ru' => 'Poson проверит совместимость аксессуара с вашей моделью, при необходимости поможет с установкой и настройкой и обеспечит дальнейший сервис.',
        'en' => 'Poson checks that the accessory fits your device model, helps with installation and setup when needed, and provides ongoing service.',
    ],
];

$common = [
    'wired' => [
        'uz' => ['<strong>Chakana savdo:</strong> kassada tovarlarni tez skanerlash.', '<strong>Dorixona va ombor:</strong> qabul qilish va markirovka kodlarini oʻqish.'],
        'ru' => ['<strong>Розница:</strong> быстрое сканирование товара на кассе.', '<strong>Аптека и склад:</strong> приёмка и считывание кодов маркировки.'],
        'en' => ['<strong>Retail:</strong> fast item scanning at the checkout.', '<strong>Pharmacy and warehouse:</strong> receiving and reading marking codes.'],
    ],
    'wireless' => [
        'uz' => ['<strong>Chakana savdo:</strong> zalda va yirik tovarlar yonida sim bilan cheklanmasdan ishlash.', '<strong>Ombor:</strong> qabul qilish, inventarizatsiya va tovar joylashtirish.'],
        'ru' => ['<strong>Розница:</strong> работа в зале и у крупногабаритного товара без привязки к проводу.', '<strong>Склад:</strong> приёмка, инвентаризация и размещение товара.'],
        'en' => ['<strong>Retail:</strong> work on the shop floor and next to bulky goods without a cable.', '<strong>Warehouse:</strong> receiving, stocktaking and putaway.'],
    ],
    'pres' => [
        'uz' => ['<strong>Kassa:</strong> mahsulotni skaner oynasi ostiga tutish kifoya, qoʻlda ushlash shart emas.', '<strong>Dorixona va xizmat koʻrsatish:</strong> doimiy oqimda tez ishlash.'],
        'ru' => ['<strong>Касса:</strong> достаточно поднести товар к окну сканера, держать его в руке не нужно.', '<strong>Аптека и сервисные точки:</strong> быстрая работа при постоянном потоке покупателей.'],
        'en' => ['<strong>Checkout:</strong> just present the item to the scan window, no need to hold the scanner.', '<strong>Pharmacy and service counters:</strong> quick handling of a steady flow of customers.'],
    ],
    'ring' => [
        'uz' => ['<strong>Ombor:</strong> qoʻllar band boʻlganda tovar qabul qilish va komplektatsiya.', '<strong>Logistika:</strong> pochta va posilkalarni tez skanerlash.'],
        'ru' => ['<strong>Склад:</strong> приёмка и комплектация, когда обе руки заняты.', '<strong>Логистика:</strong> быстрое сканирование посылок и отправлений.'],
        'en' => ['<strong>Warehouse:</strong> receiving and picking when both hands are busy.', '<strong>Logistics:</strong> quick scanning of parcels and shipments.'],
    ],
    'generic' => [
        'uz' => ['<strong>Chakana savdo:</strong> kassa va zalda tovarlarni skanerlash.', '<strong>Ombor:</strong> tovar qabuli va hisobi.'],
        'ru' => ['<strong>Розница:</strong> сканирование товара на кассе и в зале.', '<strong>Склад:</strong> приёмка и учёт товара.'],
        'en' => ['<strong>Retail:</strong> scanning items at the till and on the floor.', '<strong>Warehouse:</strong> receiving and stock control.'],
    ],
    'tsd' => [
        'uz' => ['<strong>Ombor:</strong> qabul qilish, joylashtirish, buyurtma yigʻish va inventarizatsiya.', '<strong>Chakana savdo va dorixona:</strong> qoldiqlarni sanash va markirovka kodlarini skanerlash.'],
        'ru' => ['<strong>Склад:</strong> приёмка, размещение, сборка заказов и инвентаризация.', '<strong>Розница и аптека:</strong> подсчёт остатков и сканирование кодов маркировки.'],
        'en' => ['<strong>Warehouse:</strong> receiving, putaway, order picking and stocktaking.', '<strong>Retail and pharmacy:</strong> stock counts and scanning marking codes.'],
    ],
];

$make = static function (array $o) use ($service, $common): array {
    $price = $o['price'] ?? null;
    $kind = $o['kind'];
    $isAccessory = $kind === 'accessory';
    $suffix = [
        'uz' => ' Sozlash va servis — Poson.',
        'ru' => ' Настройка и сервис — Poson.',
        'en' => ' Setup and service by Poson.',
    ];
    $description = [];
    $metaTitle = [];
    $metaDescription = [];
    foreach (['uz', 'ru', 'en'] as $lang) {
        $bullets = array_merge($isAccessory ? [] : $common[$o['variant']][$lang], $o['bullets'][$lang]);
        $description[$lang] = '<p>'.$o['intro'][$lang].'</p><ul><li>'.implode('</li><li>', $bullets).'</li></ul><p>'.$service[$kind][$lang].'</p>';
        $metaTitle[$lang] = mb_strlen($o['name'][$lang].' — Poson') > 60 ? $o['name'][$lang] : $o['name'][$lang].' — Poson';
        $metaDescription[$lang] = $o['short'][$lang].$suffix[$lang];
    }

    return [
        'sku' => $o['sku'],
        'model' => $o['model'],
        'brand' => $o['brand'],
        'category' => $o['category'],
        'type' => 'hardware',
        'unit' => 'piece',
        'min_qty' => 1,
        'sale_mode' => $price === null ? 'quote' : ($isAccessory ? 'cart' : 'both'),
        'stock_status' => 'on_order',
        'warranty_months' => $kind === 'accessory' ? null : 1,
        'badge' => null,
        'is_featured' => false,
        'price' => $price,
        'price_dealer' => null,
        'price_wholesale' => null,
        'name' => $o['name'],
        'short_description' => $o['short'],
        'description' => $description,
        'meta_title' => $metaTitle,
        'meta_description' => $metaDescription,
        'specs' => $o['specs'] ?? [],
        'related' => $o['related'] ?? [],
        'has_missing_specs' => $o['missing'] ?? false,
    ];
};

return [

    // ─── Handheld scanners ──────────────────────────────────────────────────
    $make([
        'sku' => '257', 'model' => 'DS2208', 'brand' => 'Zebra', 'category' => 'handheld-scanners', 'price' => 1250000,
        'kind' => 'scanner', 'variant' => 'wired',
        'name' => ['uz' => 'Zebra DS2208 2D skaneri (podstavkasiz)', 'ru' => 'Сканер Zebra DS2208 2D (без подставки)', 'en' => 'Zebra DS2208 2D scanner (no stand)'],
        'short' => ['uz' => 'Simli 2D skaner kassa uchun: qogʻoz va ekrandagi kodlar, DataMatrix. Podstavkasiz variant.', 'ru' => 'Проводной 2D-сканер для кассы: коды с бумаги и экрана, DataMatrix. Вариант без подставки.', 'en' => 'Corded 2D checkout scanner reading paper and screen codes, DataMatrix included. Version without a stand.'],
        'intro' => ['uz' => '<strong>Zebra DS2208</strong> — kunlik kassa ishi uchun simli 2D imidjer. Chiziqli kodlar, QR va DataMatrix kodlarini qogʻozdan ham, telefon ekranidan ham oʻqiydi. Bu podstavkasiz komplekt.', 'ru' => '<strong>Zebra DS2208</strong> — проводной 2D-имиджер для ежедневной работы на кассе. Читает линейные коды, QR и DataMatrix с бумаги и с экрана телефона. Это комплект без подставки.', 'en' => 'The <strong>Zebra DS2208</strong> is a corded 2D imager for everyday checkout work. It reads linear codes, QR and DataMatrix from paper and phone screens. This is the version without a stand.'],
        'bullets' => ['uz' => ['USB va RS232 interfeyslari, IP52 himoya, 1,5 m balandlikdan tushishga chidamlilik.'], 'ru' => ['Интерфейсы USB и RS232, защита IP52, устойчивость к падениям с 1,5 м.'], 'en' => ['USB and RS232 interfaces, IP52 sealing, 1.5 m drop rating.']],
        'specs' => ['scan_engine' => $k('2D imidjer'), 'symbologies' => $k($sym2d), 'interface' => $k(['USB', 'RS232']), 'ip_rating' => $k('IP52'), 'drop_spec' => 1.5],
        'related' => [['sku' => '6', 'type' => 'alternative'], ['sku' => '253', 'type' => 'accessory']],
    ]),
    $make([
        'sku' => '6', 'model' => 'DS2208', 'brand' => 'Zebra', 'category' => 'handheld-scanners', 'price' => 1375000,
        'kind' => 'scanner', 'variant' => 'wired',
        'name' => ['uz' => 'Zebra DS2208 2D skaneri (podstavka bilan)', 'ru' => 'Сканер Zebra DS2208 2D (с подставкой)', 'en' => 'Zebra DS2208 2D scanner (with stand)'],
        'short' => ['uz' => 'Simli 2D skaner podstavka bilan: qoʻlda ham, stol ustida avtomatik ham ishlaydi, DataMatrix oʻqiydi.', 'ru' => 'Проводной 2D-сканер с подставкой: работает в руке и на столе в автоматическом режиме, читает DataMatrix.', 'en' => 'Corded 2D scanner with a stand for handheld or hands-free use at the counter, DataMatrix included.'],
        'intro' => ['uz' => '<strong>Zebra DS2208</strong> podstavka bilan kassa ishi uchun qulay: skanerni qoʻlda ushlash yoki podstavkaga qoʻyib, mahsulotni ostiga tutish mumkin. QR va DataMatrix kodlarini qogʻozdan va ekrandan oʻqiydi.', 'ru' => '<strong>Zebra DS2208</strong> с подставкой удобен на кассе: сканер можно держать в руке или поставить на подставку и подносить товар. Читает QR и DataMatrix с бумаги и экрана.', 'en' => 'The <strong>Zebra DS2208</strong> with a stand suits the checkout: use it in hand or place it on the stand and present items. It reads QR and DataMatrix from paper and screens.'],
        'bullets' => ['uz' => ['USB va RS232, IP52 himoya, 1,5 m dan tushishga chidamlilik.'], 'ru' => ['USB и RS232, защита IP52, устойчивость к падениям с 1,5 м.'], 'en' => ['USB and RS232, IP52 sealing, 1.5 m drop rating.']],
        'specs' => ['scan_engine' => $k('2D imidjer'), 'symbologies' => $k($sym2d), 'interface' => $k(['USB', 'RS232']), 'ip_rating' => $k('IP52'), 'drop_spec' => 1.5],
        'related' => [['sku' => '257', 'type' => 'alternative'], ['sku' => '253', 'type' => 'accessory']],
    ]),
    $make([
        'sku' => '162', 'model' => 'CL-2210', 'brand' => 'Mertech', 'category' => 'handheld-scanners', 'price' => 550000,
        'kind' => 'scanner', 'variant' => 'wireless',
        'name' => ['uz' => 'Mertech CL-2210 simsiz 2D skaner', 'ru' => 'Беспроводной 2D-сканер Mertech CL-2210', 'en' => 'Mertech CL-2210 wireless 2D scanner'],
        'short' => ['uz' => 'Simsiz 2D skaner USB-qabul qilgich va Bluetooth BLE bilan, 2600 mAh akkumulyator.', 'ru' => 'Беспроводной 2D-сканер с USB-приёмником и Bluetooth BLE, аккумулятор 2600 мАч.', 'en' => 'Wireless 2D scanner with a USB receiver and Bluetooth BLE, 2600 mAh battery.'],
        'intro' => ['uz' => '<strong>Mertech CL-2210</strong> — USB-qabul qilgich yoki Bluetooth BLE orqali ulanadigan simsiz 2D skaner. 1D va 2D kodlarni, jumladan DataMatrix va QR ni oʻqiydi.', 'ru' => '<strong>Mertech CL-2210</strong> — беспроводной 2D-сканер, подключается через USB-приёмник или Bluetooth BLE. Читает 1D и 2D коды, включая DataMatrix и QR.', 'en' => 'The <strong>Mertech CL-2210</strong> is a wireless 2D scanner that connects through a USB receiver or Bluetooth BLE. It reads 1D and 2D codes including DataMatrix and QR.'],
        'bullets' => ['uz' => ['Radiokanal diapazoni ochiq joyda 100 m gacha (ishlab chiqaruvchi maʼlumoti), 2600 mAh akkumulyator.'], 'ru' => ['Дальность связи до 100 м на открытом пространстве (по данным производителя), аккумулятор 2600 мАч.'], 'en' => ['Radio range up to 100 m in open space (per the maker), 2600 mAh battery.']],
        'specs' => ['scan_engine' => $k('2D imidjer'), 'symbologies' => $sym2d, 'interface' => $k(['USB', 'Bluetooth'])],
    ]),
    $make([
        'sku' => '150', 'model' => 'CL-2310', 'brand' => 'Mertech', 'category' => 'handheld-scanners', 'price' => 1200000,
        'kind' => 'scanner', 'variant' => 'wireless',
        'name' => ['uz' => 'Mertech CL-2310 simsiz 2D skaner', 'ru' => 'Беспроводной 2D-сканер Mertech CL-2310', 'en' => 'Mertech CL-2310 wireless 2D scanner'],
        'short' => ['uz' => 'Simsiz 2D skaner USB-qabul qilgich va Bluetooth BLE 5.0 bilan: DataMatrix va QR kodlarni oʻqiydi.', 'ru' => 'Беспроводной 2D-сканер с USB-приёмником и Bluetooth BLE 5.0: читает DataMatrix и QR.', 'en' => 'Wireless 2D scanner with a USB receiver and Bluetooth BLE 5.0 that reads DataMatrix and QR codes.'],
        'intro' => ['uz' => '<strong>Mertech CL-2310</strong> — simsiz 2D skaner, kompyuterga USB-qabul qilgich orqali ulanadi, USB-HID va USB-COM emulyatsiyasini qoʻllaydi.', 'ru' => '<strong>Mertech CL-2310</strong> — беспроводной 2D-сканер, подключается к компьютеру через USB-приёмник, поддерживает эмуляцию USB-HID и USB-COM.', 'en' => 'The <strong>Mertech CL-2310</strong> is a wireless 2D scanner that links to a computer through a USB receiver and supports USB-HID and USB-COM emulation.'],
        'bullets' => ['uz' => ['Bluetooth BLE 5.0 va USB-qabul qilgich orqali kassa yoki kompyuterga ulanadi.'], 'ru' => ['Bluetooth BLE 5.0 и USB-приёмник для подключения к кассе или компьютеру.'], 'en' => ['Bluetooth BLE 5.0 and a USB receiver for connecting to a till or PC.']],
        'specs' => ['scan_engine' => $k('2D imidjer'), 'symbologies' => $sym2d, 'interface' => $k(['USB', 'Bluetooth'])],
    ]),
    $make([
        'sku' => '207', 'model' => 'CL-2320', 'brand' => 'Mertech', 'category' => 'handheld-scanners', 'price' => 1200000,
        'kind' => 'scanner', 'variant' => 'wireless',
        'name' => ['uz' => 'Mertech CL-2320 simsiz 2D skaner', 'ru' => 'Беспроводной 2D-сканер Mertech CL-2320', 'en' => 'Mertech CL-2320 wireless 2D scanner'],
        'short' => ['uz' => 'Simsiz 2D skaner USB-qabul qilgich bilan, Bluetooth BLE, 2600 mAh akkumulyator, DataMatrix va QR.', 'ru' => 'Беспроводной 2D-сканер с USB-приёмником, Bluetooth BLE и аккумулятором 2600 мАч, читает DataMatrix и QR.', 'en' => 'Wireless 2D scanner with a USB receiver, Bluetooth BLE and a 2600 mAh battery, reading DataMatrix and QR.'],
        'intro' => ['uz' => '<strong>Mertech CL-2320</strong> — doʻkon, ombor va buyurtma yigʻish uchun simsiz 2D skaner. Maʼlumotlarni Bluetooth Low Energy orqali USB-qabul qilgichga uzatadi.', 'ru' => '<strong>Mertech CL-2320</strong> — беспроводной 2D-сканер для магазина, склада и сборки заказов. Передаёт данные по Bluetooth Low Energy на USB-приёмник.', 'en' => 'The <strong>Mertech CL-2320</strong> is a wireless 2D scanner for shops, warehouses and order picking. It sends data over Bluetooth Low Energy to a USB receiver.'],
        'bullets' => ['uz' => ['Komplektda USB-qabul qilgich va kabel; 2600 mAh akkumulyator.'], 'ru' => ['В комплекте USB-приёмник и кабель; аккумулятор 2600 мАч.'], 'en' => ['USB receiver and cable included; 2600 mAh battery.']],
        'specs' => ['scan_engine' => $k('2D imidjer'), 'symbologies' => $sym2d, 'interface' => $k(['USB', 'Bluetooth'])],
    ]),
    $make([
        'sku' => '264', 'model' => 'CL-2410', 'brand' => 'Mertech', 'category' => 'handheld-scanners', 'price' => 850000,
        'kind' => 'scanner', 'variant' => 'wireless',
        'name' => ['uz' => 'Mertech CL-2410 simsiz 2D skaner', 'ru' => 'Беспроводной 2D-сканер Mertech CL-2410', 'en' => 'Mertech CL-2410 wireless 2D scanner'],
        'short' => ['uz' => 'Simsiz 2D skaner USB-qabul qilgich va Bluetooth BLE bilan, 2600 mAh akkumulyator, avtomatik skanerlash.', 'ru' => 'Беспроводной 2D-сканер с USB-приёмником и Bluetooth BLE, аккумулятор 2600 мАч, автоскан.', 'en' => 'Wireless 2D scanner with a USB receiver and Bluetooth BLE, 2600 mAh battery and auto-scan mode.'],
        'intro' => ['uz' => '<strong>Mertech CL-2410</strong> — USB-qabul qilgich orqali ulanadigan simsiz 2D skaner. USB-HID va USB-COM emulyatsiyasi, Bluetooth BLE va avtomatik skanerlash rejimini qoʻllaydi.', 'ru' => '<strong>Mertech CL-2410</strong> — беспроводной 2D-сканер с USB-приёмником. Поддерживает эмуляцию USB-HID и USB-COM, Bluetooth BLE и режим автоматического сканирования.', 'en' => 'The <strong>Mertech CL-2410</strong> is a wireless 2D scanner with a USB receiver. It supports USB-HID and USB-COM emulation, Bluetooth BLE and an automatic scanning mode.'],
        'bullets' => ['uz' => ['Akkumulyator 2600 mAh — butun smena davomida ishlash uchun.'], 'ru' => ['Аккумулятор 2600 мАч для работы в течение смены.'], 'en' => ['2600 mAh battery for working through a shift.']],
        'specs' => ['scan_engine' => $k('2D imidjer'), 'symbologies' => $sym2d, 'interface' => $k(['USB', 'Bluetooth'])],
    ]),
    $make([
        'sku' => '36', 'model' => 'H500', 'brand' => 'Henex', 'category' => 'handheld-scanners', 'price' => 1815000,
        'kind' => 'scanner', 'variant' => 'ring',
        'name' => ['uz' => 'Henex H500 barmoqqa taqiladigan 2D skaner', 'ru' => 'Сканер-кольцо Henex H500 2D', 'en' => 'Henex H500 wearable 2D ring scanner'],
        'short' => ['uz' => 'Barmoqqa taqiladigan simsiz 2D skaner: Bluetooth LE yoki USB-C, 1500 mAh akkumulyator.', 'ru' => 'Носимый беспроводной 2D-сканер на палец: Bluetooth LE или USB-C, аккумулятор 1500 мАч.', 'en' => 'Finger-worn wireless 2D scanner with Bluetooth LE or USB-C and a 1500 mAh battery.'],
        'intro' => ['uz' => '<strong>Henex H500</strong> — qoʻlni band qilmaydigan kiyiladigan 2D skaner. 1D va 2D kodlarni, shu jumladan telefon ekranidagilarni oʻqiydi.', 'ru' => '<strong>Henex H500</strong> — носимый 2D-сканер, который не занимает руку. Читает 1D и 2D коды, в том числе с экранов телефонов.', 'en' => 'The <strong>Henex H500</strong> is a wearable 2D scanner that keeps the hand free. It reads 1D and 2D codes, including those on phone screens.'],
        'bullets' => ['uz' => ['Bluetooth LE va USB-C, IP54 himoya, akkumulyator taxminan 6 soatga yetadi.'], 'ru' => ['Bluetooth LE и USB-C, защита IP54, автономность около 6 часов.'], 'en' => ['Bluetooth LE and USB-C, IP54 sealing, around 6 hours of runtime.']],
        'specs' => ['scan_engine' => $k('2D imidjer'), 'interface' => $k(['USB', 'Bluetooth']), 'ip_rating' => 'IP54', 'symbologies' => $sym2d],
        'related' => [['sku' => '247', 'type' => 'accessory']],
    ]),

    // ─── Wireless scanners ──────────────────────────────────────────────────
    $make([
        'sku' => '2', 'model' => 'Voyager XP 1472g', 'brand' => 'Honeywell', 'category' => 'wireless-scanners', 'price' => 3025000,
        'kind' => 'scanner', 'variant' => 'wireless',
        'name' => ['uz' => 'Honeywell 1472g simsiz 2D skaneri', 'ru' => 'Беспроводной сканер Honeywell 1472g 2D', 'en' => 'Honeywell 1472g wireless 2D scanner'],
        'short' => ['uz' => 'Bluetooth orqali ishlaydigan 2D skaner zaryadlash bazasi bilan: kassa, ombor va dorixona uchun.', 'ru' => '2D-сканер по Bluetooth с зарядной базой: для кассы, склада и аптеки.', 'en' => 'Bluetooth 2D scanner with a charge base, built for checkouts, warehouses and pharmacies.'],
        'intro' => ['uz' => '<strong>Honeywell Voyager 1472g</strong> — Bluetooth simsiz 2D skaner. Bazaga qoʻyilganda zaryadlanadi va ulanadi; 1,8 m balandlikdan tushishga chidamli deb eʼlon qilingan.', 'ru' => '<strong>Honeywell Voyager 1472g</strong> — беспроводной 2D-сканер с Bluetooth. Заряжается и подключается на базе; заявлена устойчивость к падениям с 1,8 м.', 'en' => 'The <strong>Honeywell Voyager 1472g</strong> is a Bluetooth wireless 2D scanner. It charges and pairs on its base and is rated for 1.8 m drops.'],
        'bullets' => ['uz' => ['Zaryadlash va aloqa bazasi bilan; IP darajasi variantga qarab farq qiladi.'], 'ru' => ['Поставляется с зарядно-коммуникационной базой; степень IP зависит от версии.'], 'en' => ['Supplied with a charge and communication base; IP rating depends on the version.']],
        'specs' => ['scan_engine' => $k('2D imidjer'), 'symbologies' => $sym2d, 'interface' => $k(['USB', 'Bluetooth']), 'drop_spec' => 1.8],
    ]),
    $make([
        'sku' => '62', 'model' => 'H1200R', 'brand' => 'Henex', 'category' => 'wireless-scanners', 'price' => 550000,
        'kind' => 'scanner', 'variant' => 'generic', 'missing' => true,
        'name' => ['uz' => 'Henex H1200R 2D skaneri', 'ru' => 'Сканер Henex H1200R 2D', 'en' => 'Henex H1200R 2D scanner'],
        'short' => ['uz' => 'Henex H1200R 2D shtrix-kod skaneri. Aloqa turi va texnik tafsilotlar buyurtma paytida aniqlashtiriladi.', 'ru' => '2D-сканер штрих-кода Henex H1200R. Тип связи и детали уточняются при заказе.', 'en' => 'Henex H1200R 2D barcode scanner. Connectivity and details are confirmed when you order.'],
        'intro' => ['uz' => '<strong>Henex H1200R</strong> — 2D shtrix-kod skaneri. Aniq interfeys va himoya parametrlarini Poson menejeri buyurtmadan oldin tasdiqlaydi.', 'ru' => '<strong>Henex H1200R</strong> — 2D-сканер штрих-кода. Точный интерфейс и параметры защиты менеджер Poson подтвердит перед заказом.', 'en' => 'The <strong>Henex H1200R</strong> is a 2D barcode scanner. A Poson manager confirms the exact interface and protection ratings before you order.'],
        'bullets' => ['uz' => ['Texnik xususiyatlar buyurtma paytida tasdiqlanadi.'], 'ru' => ['Технические характеристики подтверждаются при заказе.'], 'en' => ['Technical specifications are confirmed when you order.']],
        'specs' => ['scan_engine' => $k('2D imidjer')],
    ]),
    $make([
        'sku' => '44', 'model' => 'QuickScan QBT2500', 'brand' => 'Datalogic', 'category' => 'wireless-scanners', 'price' => 3630000,
        'kind' => 'scanner', 'variant' => 'wireless',
        'name' => ['uz' => 'Datalogic QuickScan QBT2500 simsiz 2D skaneri', 'ru' => 'Беспроводной сканер Datalogic QuickScan QBT2500 2D', 'en' => 'Datalogic QuickScan QBT2500 wireless 2D scanner'],
        'short' => ['uz' => 'Bluetooth 2D skaner bazasi bilan: IP52, 1,5 m dan tushishga chidamli, doʻkon va ombor uchun.', 'ru' => 'Bluetooth 2D-сканер с базой: IP52, устойчив к падениям с 1,5 м, для магазина и склада.', 'en' => 'Bluetooth 2D scanner with a base: IP52, 1.5 m drop rating, for shops and warehouses.'],
        'intro' => ['uz' => '<strong>Datalogic QuickScan QBT2500</strong> — Bluetooth orqali ishlaydigan 2D imidjer. USB-baza orqali kompyuterga ulanadi va zaryadlanadi.', 'ru' => '<strong>Datalogic QuickScan QBT2500</strong> — 2D-имиджер с Bluetooth. Подключается к компьютеру и заряжается через USB-базу.', 'en' => 'The <strong>Datalogic QuickScan QBT2500</strong> is a Bluetooth 2D imager that connects to a computer and charges through its USB base.'],
        'bullets' => ['uz' => ['IP52 va 1,5 m; Bluetooth masofasi variantga qarab eʼlon qilinadi.'], 'ru' => ['IP52 и 1,5 м; дальность Bluetooth зависит от версии.'], 'en' => ['IP52 and 1.5 m drop; Bluetooth range depends on version.']],
        'specs' => ['scan_engine' => $k('2D imidjer'), 'symbologies' => $sym2d, 'interface' => $k(['USB', 'Bluetooth']), 'ip_rating' => $k('IP52'), 'drop_spec' => 1.5],
    ]),
    $make([
        'sku' => '205', 'model' => 'AW-9208H WBT', 'brand' => 'Asianwell', 'category' => 'wireless-scanners', 'price' => 850000,
        'kind' => 'scanner', 'variant' => 'generic', 'missing' => true,
        'name' => ['uz' => 'Asianwell AW-9208H WBT skaneri', 'ru' => 'Сканер Asianwell AW-9208H WBT', 'en' => 'Asianwell AW-9208H WBT scanner'],
        'short' => ['uz' => 'Asianwell AW-9208H WBT shtrix-kod skaneri. Texnik tafsilotlar buyurtma paytida tasdiqlanadi.', 'ru' => 'Сканер штрих-кода Asianwell AW-9208H WBT. Технические детали подтверждаются при заказе.', 'en' => 'Asianwell AW-9208H WBT barcode scanner. Technical details are confirmed when you order.'],
        'intro' => ['uz' => '<strong>Asianwell AW-9208H WBT</strong> — simsiz toifadagi shtrix-kod skaneri. Aloqa turi, kod turlari va himoya darajasi Poson menejeri tomonidan buyurtmadan oldin aniqlanadi.', 'ru' => '<strong>Asianwell AW-9208H WBT</strong> — сканер штрих-кода из беспроводной категории. Тип связи, считываемые коды и степень защиты менеджер Poson уточнит перед заказом.', 'en' => 'The <strong>Asianwell AW-9208H WBT</strong> is a barcode scanner from our wireless range. A Poson manager confirms connectivity, symbologies and protection rating before you order.'],
        'bullets' => ['uz' => ['Texnik xususiyatlar buyurtma paytida tasdiqlanadi.'], 'ru' => ['Технические характеристики подтверждаются при заказе.'], 'en' => ['Technical specifications are confirmed when you order.']],
    ]),
    $make([
        'sku' => '54', 'model' => 'AW-U4WB', 'brand' => 'Asianwell', 'category' => 'wireless-scanners', 'price' => 420000,
        'kind' => 'scanner', 'variant' => 'generic', 'missing' => true,
        'name' => ['uz' => 'Asianwell AW-U4WB skaneri', 'ru' => 'Сканер Asianwell AW-U4WB', 'en' => 'Asianwell AW-U4WB scanner'],
        'short' => ['uz' => 'Asianwell AW-U4WB shtrix-kod skaneri. Texnik tafsilotlar buyurtma paytida tasdiqlanadi.', 'ru' => 'Сканер штрих-кода Asianwell AW-U4WB. Технические детали подтверждаются при заказе.', 'en' => 'Asianwell AW-U4WB barcode scanner. Technical details are confirmed when you order.'],
        'intro' => ['uz' => '<strong>Asianwell AW-U4WB</strong> — simsiz toifadagi shtrix-kod skaneri. Aniq parametrlarni Poson menejeri buyurtmadan oldin tasdiqlaydi.', 'ru' => '<strong>Asianwell AW-U4WB</strong> — сканер штрих-кода из беспроводной категории. Точные параметры менеджер Poson подтвердит перед заказом.', 'en' => 'The <strong>Asianwell AW-U4WB</strong> is a barcode scanner from our wireless range. A Poson manager confirms the exact parameters before you order.'],
        'bullets' => ['uz' => ['Texnik xususiyatlar buyurtma paytida tasdiqlanadi.'], 'ru' => ['Технические характеристики подтверждаются при заказе.'], 'en' => ['Technical specifications are confirmed when you order.']],
    ]),
    $make([
        'sku' => '206', 'model' => 'AW-X10WT', 'brand' => 'Asianwell', 'category' => 'wireless-scanners', 'price' => 2000000,
        'kind' => 'scanner', 'variant' => 'generic', 'missing' => true,
        'name' => ['uz' => 'Asianwell AW-X10WT skaneri', 'ru' => 'Сканер Asianwell AW-X10WT', 'en' => 'Asianwell AW-X10WT scanner'],
        'short' => ['uz' => 'Asianwell AW-X10WT shtrix-kod skaneri. Texnik tafsilotlar buyurtma paytida tasdiqlanadi.', 'ru' => 'Сканер штрих-кода Asianwell AW-X10WT. Технические детали подтверждаются при заказе.', 'en' => 'Asianwell AW-X10WT barcode scanner. Technical details are confirmed when you order.'],
        'intro' => ['uz' => '<strong>Asianwell AW-X10WT</strong> — simsiz toifadagi shtrix-kod skaneri. Aloqa turi va kod turlarini Poson menejeri buyurtmadan oldin aniqlaydi.', 'ru' => '<strong>Asianwell AW-X10WT</strong> — сканер штрих-кода из беспроводной категории. Тип связи и считываемые коды менеджер Poson уточнит перед заказом.', 'en' => 'The <strong>Asianwell AW-X10WT</strong> is a barcode scanner from our wireless range. A Poson manager confirms connectivity and symbologies before you order.'],
        'bullets' => ['uz' => ['Texnik xususiyatlar buyurtma paytida tasdiqlanadi.'], 'ru' => ['Технические характеристики подтверждаются при заказе.'], 'en' => ['Technical specifications are confirmed when you order.']],
    ]),
    $make([
        'sku' => '7', 'model' => 'DS2278', 'brand' => 'Zebra', 'category' => 'wireless-scanners', 'price' => 2900000,
        'kind' => 'scanner', 'variant' => 'wireless',
        'name' => ['uz' => 'Zebra DS2278 simsiz 2D skaneri', 'ru' => 'Беспроводной сканер Zebra DS2278 2D', 'en' => 'Zebra DS2278 wireless 2D scanner'],
        'short' => ['uz' => 'Bluetooth 2D skaner zaryadlash bazasi bilan: DataMatrix va QR, kassa, dorixona va ombor uchun.', 'ru' => 'Bluetooth 2D-сканер с зарядной базой: DataMatrix и QR, для кассы, аптеки и склада.', 'en' => 'Bluetooth 2D scanner with a charging cradle that reads DataMatrix and QR for checkouts, pharmacies and warehouses.'],
        'intro' => ['uz' => '<strong>Zebra DS2278</strong> — Bluetooth simsiz 2D imidjer. Baza zaryadlash va kompyuterga ulanish vazifasini bajaradi; chiziqli kodlar, QR va DataMatrix oʻqiladi.', 'ru' => '<strong>Zebra DS2278</strong> — беспроводной 2D-имиджер с Bluetooth. База заряжает сканер и связывает его с компьютером; читает линейные коды, QR и DataMatrix.', 'en' => 'The <strong>Zebra DS2278</strong> is a Bluetooth wireless 2D imager. The cradle charges it and links it to the computer; it reads linear codes, QR and DataMatrix.'],
        'bullets' => ['uz' => ['IP42 va 1,5 m dan tushishga chidamlilik.'], 'ru' => ['IP42 и устойчивость к падениям с 1,5 м.'], 'en' => ['IP42 and 1.5 m drop rating.']],
        'specs' => ['scan_engine' => $k('2D imidjer'), 'symbologies' => $sym2d, 'interface' => $k(['USB', 'Bluetooth']), 'ip_rating' => $k('IP42'), 'drop_spec' => 1.5],
    ]),
    $make([
        'sku' => '13', 'model' => 'HC-3208R', 'brand' => 'Henex', 'category' => 'wireless-scanners', 'price' => 1331000,
        'kind' => 'scanner', 'variant' => 'generic', 'missing' => true,
        'name' => ['uz' => 'Henex HC-3208R simsiz 2D skaneri', 'ru' => 'Беспроводной 2D-сканер Henex HC-3208R', 'en' => 'Henex HC-3208R wireless 2D scanner'],
        'short' => ['uz' => 'Henex HC-3208R simsiz 2D skaneri, USB qabul qilgich bilan. Texnik tafsilotlar buyurtma paytida tasdiqlanadi.', 'ru' => 'Беспроводной 2D-сканер Henex HC-3208R с USB-приёмником. Технические детали подтверждаются при заказе.', 'en' => 'Henex HC-3208R wireless 2D scanner with a USB receiver. Technical details are confirmed when you order.'],
        'intro' => ['uz' => '<strong>Henex HC-3208R</strong> — USB qabul qilgichli simsiz 2D qoʻl skaneri. Aloqa turi va kod turlarini Poson menejeri buyurtmadan oldin tasdiqlaydi.', 'ru' => '<strong>Henex HC-3208R</strong> — беспроводной ручной 2D-сканер с USB-приёмником. Тип связи и считываемые коды менеджер Poson подтвердит перед заказом.', 'en' => 'The <strong>Henex HC-3208R</strong> is a wireless 2D handheld scanner with a USB receiver. A Poson manager confirms the connection type and symbologies before you order.'],
        'bullets' => ['uz' => ['Simsiz 2D qoʻl skaneri, USB qabul qilgich.'], 'ru' => ['Беспроводной ручной 2D-сканер, USB-приёмник.'], 'en' => ['Wireless 2D handheld scanner, USB receiver.']],
        'specs' => ['scan_engine' => $k('2D imidjer'), 'interface' => $k(['USB'])],
    ]),
    $make([
        'sku' => '16', 'model' => 'HW-3890B', 'brand' => 'Honorway', 'category' => 'wireless-scanners', 'price' => 720000,
        'kind' => 'scanner', 'variant' => 'generic', 'missing' => true,
        'name' => ['uz' => 'Honorway HW-3890B skaneri', 'ru' => 'Сканер Honorway HW-3890B', 'en' => 'Honorway HW-3890B scanner'],
        'short' => ['uz' => 'Honorway HW-3890B shtrix-kod skaneri. Texnik tafsilotlar buyurtma paytida tasdiqlanadi.', 'ru' => 'Сканер штрих-кода Honorway HW-3890B. Технические детали подтверждаются при заказе.', 'en' => 'Honorway HW-3890B barcode scanner. Technical details are confirmed when you order.'],
        'intro' => ['uz' => '<strong>Honorway HW-3890B</strong> — simsiz toifadagi shtrix-kod skaneri. Aloqa turi va kod turlarini Poson menejeri buyurtmadan oldin aniqlaydi.', 'ru' => '<strong>Honorway HW-3890B</strong> — сканер штрих-кода из беспроводной категории. Тип связи и считываемые коды менеджер Poson уточнит перед заказом.', 'en' => 'The <strong>Honorway HW-3890B</strong> is a barcode scanner from our wireless range. A Poson manager confirms connectivity and symbologies before you order.'],
        'bullets' => ['uz' => ['Texnik xususiyatlar buyurtma paytida tasdiqlanadi.'], 'ru' => ['Технические характеристики подтверждаются при заказе.'], 'en' => ['Technical specifications are confirmed when you order.']],
    ]),
    $make([
        'sku' => '31', 'model' => 'HW-6680', 'brand' => 'Honorway', 'category' => 'wireless-scanners', 'price' => 726000,
        'kind' => 'scanner', 'variant' => 'generic', 'missing' => true,
        'name' => ['uz' => 'Honorway HW-6680 mobil skaneri', 'ru' => 'Мобильный сканер Honorway HW-6680', 'en' => 'Honorway HW-6680 mobile scanner'],
        'short' => ['uz' => 'Honorway HW-6680 mobil shtrix-kod skaneri. Texnik tafsilotlar buyurtma paytida tasdiqlanadi.', 'ru' => 'Мобильный сканер штрих-кода Honorway HW-6680. Технические детали подтверждаются при заказе.', 'en' => 'Honorway HW-6680 mobile barcode scanner. Technical details are confirmed when you order.'],
        'intro' => ['uz' => '<strong>Honorway HW-6680</strong> — mobil (simsiz) toifadagi shtrix-kod skaneri. Aloqa turi va parametrlarni Poson menejeri buyurtmadan oldin tasdiqlaydi.', 'ru' => '<strong>Honorway HW-6680</strong> — мобильный сканер штрих-кода из беспроводной категории. Тип связи и параметры менеджер Poson подтвердит перед заказом.', 'en' => 'The <strong>Honorway HW-6680</strong> is a mobile barcode scanner from our wireless range. A Poson manager confirms connectivity and parameters before you order.'],
        'bullets' => ['uz' => ['Texnik xususiyatlar buyurtma paytida tasdiqlanadi.'], 'ru' => ['Технические характеристики подтверждаются при заказе.'], 'en' => ['Technical specifications are confirmed when you order.']],
    ]),
    $make([
        'sku' => '32', 'model' => 'R70', 'brand' => 'Urovo', 'category' => 'wireless-scanners', 'price' => 2238500,
        'kind' => 'scanner', 'variant' => 'ring',
        'name' => ['uz' => 'Urovo R70 barmoqqa taqiladigan 2D skaner', 'ru' => 'Сканер-кольцо Urovo R70 2D', 'en' => 'Urovo R70 2D ring scanner'],
        'short' => ['uz' => 'Simsiz 2D halqa-skaner: Bluetooth va 2.4 GHz, 1000 mAh akkumulyator, ombor va logistika uchun.', 'ru' => 'Беспроводное 2D-кольцо: Bluetooth и 2.4 GHz, аккумулятор 1000 мАч, для склада и логистики.', 'en' => 'Wireless 2D ring scanner with Bluetooth and 2.4 GHz and a 1000 mAh battery for warehouses and logistics.'],
        'intro' => ['uz' => '<strong>Urovo R70</strong> — barmoqqa taqiladigan simsiz 2D skaner. Bluetooth va 2.4 GHz orqali ulanadi, Android, iOS va Windows bilan ishlaydi.', 'ru' => '<strong>Urovo R70</strong> — носимый беспроводной 2D-сканер-кольцо. Подключается по Bluetooth и 2.4 GHz, работает с Android, iOS и Windows.', 'en' => 'The <strong>Urovo R70</strong> is a wearable wireless 2D ring scanner. It connects over Bluetooth and 2.4 GHz and works with Android, iOS and Windows.'],
        'bullets' => ['uz' => ['Bluetooth masofasi taxminan 10 m, 2.4 GHz da 50 m dan ortiq (ishlab chiqaruvchi maʼlumoti); akkumulyator 1000 mAh.'], 'ru' => ['Дальность Bluetooth около 10 м, по 2.4 GHz более 50 м (данные производителя); аккумулятор 1000 мАч.'], 'en' => ['About 10 m over Bluetooth and over 50 m on 2.4 GHz (maker data); 1000 mAh battery.']],
        'specs' => ['scan_engine' => $k('2D imidjer'), 'symbologies' => $sym2d, 'interface' => $k(['Bluetooth', '2.4 GHz']), 'battery' => 1000],
    ]),

    // ─── Presentation scanners ──────────────────────────────────────────────
    $make([
        'sku' => '63', 'model' => 'H2200', 'brand' => 'Henex', 'category' => 'presentation-scanners', 'price' => 550000,
        'kind' => 'scanner', 'variant' => 'pres',
        'name' => ['uz' => 'Henex H2200 stol usti 2D skaneri', 'ru' => 'Настольный 2D-сканер Henex H2200', 'en' => 'Henex H2200 desktop 2D scanner'],
        'short' => ['uz' => 'Stol usti 2D skaner, USB-C ulanish, IP54: kassa va xizmat koʻrsatish nuqtalari uchun.', 'ru' => 'Настольный 2D-сканер с USB-C и защитой IP54 для касс и сервисных точек.', 'en' => 'Desktop 2D scanner with USB-C and IP54 sealing for checkouts and service counters.'],
        'intro' => ['uz' => '<strong>Henex H2200</strong> — 640×480 CMOS-sensorli stol usti 2D skaner. Mahsulot oynaga tutilganda avtomatik ishlaydi.', 'ru' => '<strong>Henex H2200</strong> — настольный 2D-сканер с CMOS-сенсором 640×480. Срабатывает автоматически, когда товар подносят к окну.', 'en' => 'The <strong>Henex H2200</strong> is a desktop 2D scanner with a 640×480 CMOS sensor. It triggers automatically when an item is presented to the window.'],
        'bullets' => ['uz' => ['USB-C orqali ulanish va quvvat, IP54.'], 'ru' => ['Подключение и питание по USB-C, IP54.'], 'en' => ['USB-C for data and power, IP54.']],
        'specs' => ['scan_engine' => $k('2D imidjer'), 'symbologies' => $sym2d, 'interface' => $k(['USB']), 'ip_rating' => $k('IP54')],
    ]),
    $make([
        'sku' => '8', 'model' => 'DS9308', 'brand' => 'Zebra', 'category' => 'presentation-scanners', 'price' => 2057000,
        'kind' => 'scanner', 'variant' => 'pres',
        'name' => ['uz' => 'Zebra DS9308 stol usti 2D skaneri', 'ru' => 'Стационарный 2D-сканер Zebra DS9308', 'en' => 'Zebra DS9308 presentation 2D scanner'],
        'short' => ['uz' => 'Hands-free 2D skaner: IP52, 1,5 m dan tushishga chidamli, USB va RS232 interfeyslari.', 'ru' => 'Стационарный 2D-сканер: IP52, падения с 1,5 м, интерфейсы USB и RS232.', 'en' => 'Hands-free 2D presentation scanner with IP52, a 1.5 m drop rating and USB and RS232 interfaces.'],
        'intro' => ['uz' => '<strong>Zebra DS9308</strong> — kassa va xizmat koʻrsatish nuqtalari uchun qoʻlsiz ishlaydigan 2D skaner. Qogʻoz va ekrandagi chiziqli hamda 2D kodlarni oʻqiydi.', 'ru' => '<strong>Zebra DS9308</strong> — стационарный 2D-сканер для касс и сервисных точек. Читает линейные и 2D коды с бумаги и экранов.', 'en' => 'The <strong>Zebra DS9308</strong> is a hands-free 2D scanner for checkouts and service counters. It reads linear and 2D codes from paper and screens.'],
        'bullets' => ['uz' => ['Multi-interfeys: USB, RS232 (klaviatura rejimi variantga qarab).'], 'ru' => ['Мультиинтерфейс: USB, RS232 (клавиатурный режим зависит от версии).'], 'en' => ['Multi-interface: USB, RS232 (keyboard wedge depends on version).']],
        'specs' => ['scan_engine' => $k('2D imidjer'), 'symbologies' => $sym2d, 'interface' => $k(['USB', 'RS232']), 'ip_rating' => $k('IP52'), 'drop_spec' => 1.5],
    ]),
    $make([
        'sku' => '12', 'model' => 'H8000', 'brand' => 'Henex', 'category' => 'presentation-scanners', 'price' => 1694000,
        'kind' => 'scanner', 'variant' => 'pres',
        'name' => ['uz' => 'Henex H8000 stol usti 2D skaneri', 'ru' => 'Настольный 2D-сканер Henex H8000', 'en' => 'Henex H8000 desktop 2D scanner'],
        'short' => ['uz' => 'Stol usti 2D skaner: USB-C, IP54, soniyasiga 200 gacha skanerlash, QR va DataMatrix.', 'ru' => 'Настольный 2D-сканер: USB-C, IP54, до 200 сканирований в секунду, QR и DataMatrix.', 'en' => 'Desktop 2D scanner with USB-C, IP54, 200 scans per second, QR and DataMatrix.'],
        'intro' => ['uz' => '<strong>Henex H8000</strong> — stol usti 2D skaner, komplektda podstavka va 1,8 m USB-C kabel. QR, DataMatrix, PDF417, UPC va EAN kodlarini oʻqiydi.', 'ru' => '<strong>Henex H8000</strong> — настольный 2D-сканер, в комплекте подставка и кабель USB-C 1,8 м. Читает QR, DataMatrix, PDF417, UPC и EAN.', 'en' => 'The <strong>Henex H8000</strong> is a desktop 2D scanner supplied with a cradle and a 1.8 m USB-C cable. It reads QR, DataMatrix, PDF417, UPC and EAN.'],
        'bullets' => ['uz' => ['IP54 himoya.'], 'ru' => ['Защита IP54.'], 'en' => ['IP54 sealing.']],
        'specs' => ['scan_engine' => $k('2D imidjer'), 'symbologies' => $sym2d, 'interface' => $k(['USB']), 'ip_rating' => $k('IP54')],
    ]),
    $make([
        'sku' => '64', 'model' => 'HC-6052', 'brand' => 'Henex', 'category' => 'presentation-scanners', 'price' => 720000,
        'kind' => 'scanner', 'variant' => 'pres',
        'name' => ['uz' => 'Henex HC-6052 stol usti 2D skaneri', 'ru' => 'Настольный 2D-сканер Henex HC-6052', 'en' => 'Henex HC-6052 desktop 2D scanner'],
        'short' => ['uz' => 'Stol usti 2D skaner USB bilan: 640×480 sensor, IP54, 1D va 2D kodlarni oʻqiydi.', 'ru' => 'Настольный 2D-сканер с USB: сенсор 640×480, IP54, читает 1D и 2D коды.', 'en' => 'Desktop 2D scanner with USB, a 640×480 sensor and IP54, reading 1D and 2D codes.'],
        'intro' => ['uz' => '<strong>Henex HC-6052</strong> — avtomatik rejimda ishlaydigan stol usti 2D skaner, vazni taxminan 290 g.', 'ru' => '<strong>Henex HC-6052</strong> — настольный 2D-сканер с автоматическим режимом, вес около 290 г.', 'en' => 'The <strong>Henex HC-6052</strong> is a desktop 2D scanner with an automatic mode, weighing about 290 g.'],
        'bullets' => ['uz' => ['USB-C, IP54.'], 'ru' => ['USB-C, IP54.'], 'en' => ['USB-C, IP54.']],
        'specs' => ['scan_engine' => $k('2D imidjer'), 'symbologies' => $sym2d, 'interface' => $k(['USB']), 'ip_rating' => $k('IP54')],
    ]),
    $make([
        'sku' => '14', 'model' => 'HC-666', 'brand' => 'Henex', 'category' => 'presentation-scanners', 'price' => 907500,
        'kind' => 'scanner', 'variant' => 'pres', 'missing' => true,
        'name' => ['uz' => 'Henex HC-666 stol usti skaneri', 'ru' => 'Настольный сканер Henex HC-666', 'en' => 'Henex HC-666 desktop scanner'],
        'short' => ['uz' => 'Stol usti shtrix-kod skaneri podstavka va USB-C kabel bilan. Skanerlash moduli turi tasdiqlanadi.', 'ru' => 'Настольный сканер штрих-кода с подставкой и кабелем USB-C. Тип сканирующего модуля уточняется.', 'en' => 'Desktop barcode scanner with a cradle and USB-C cable. The scan engine type is confirmed on request.'],
        'intro' => ['uz' => '<strong>Henex HC-666</strong> — stol usti skaner, komplektda podstavka va 1,8 m USB-C kabel. Skanerlash moduli va kod turlarini Poson menejeri tasdiqlaydi.', 'ru' => '<strong>Henex HC-666</strong> — настольный сканер, в комплекте подставка и кабель USB-C 1,8 м. Тип модуля и считываемые коды подтвердит менеджер Poson.', 'en' => 'The <strong>Henex HC-666</strong> is a desktop scanner supplied with a cradle and a 1.8 m USB-C cable. A Poson manager confirms the scan engine and symbologies.'],
        'bullets' => ['uz' => ['Texnik xususiyatlar buyurtma paytida tasdiqlanadi.'], 'ru' => ['Технические характеристики подтверждаются при заказе.'], 'en' => ['Technical specifications are confirmed when you order.']],
        'specs' => ['interface' => $k(['USB'])],
    ]),
    $make([
        'sku' => '15', 'model' => 'HC-8288', 'brand' => 'Henex', 'category' => 'presentation-scanners', 'price' => 1089000,
        'kind' => 'scanner', 'variant' => 'pres',
        'name' => ['uz' => 'Henex HC-8288 stol usti 2D skaneri', 'ru' => 'Настольный 2D-сканер Henex HC-8288', 'en' => 'Henex HC-8288 desktop 2D scanner'],
        'short' => ['uz' => 'Stol usti 2D skaner: LED nishon va signal, USB-C, IP54, ekran va qogʻozdagi kodlarni oʻqiydi.', 'ru' => 'Настольный 2D-сканер: LED-прицел и зуммер, USB-C, IP54, читает коды с экрана и бумаги.', 'en' => 'Desktop 2D scanner with LED aimer and buzzer, USB-C and IP54, reading codes from screens and paper.'],
        'intro' => ['uz' => '<strong>Henex HC-8288</strong> — 640×480 CMOS-sensorli stol usti 2D skaner, komplektda podstavka va 1,8 m USB-C kabel.', 'ru' => '<strong>Henex HC-8288</strong> — настольный 2D-сканер с CMOS-сенсором 640×480, в комплекте подставка и кабель USB-C 1,8 м.', 'en' => 'The <strong>Henex HC-8288</strong> is a desktop 2D scanner with a 640×480 CMOS sensor, supplied with a cradle and a 1.8 m USB-C cable.'],
        'bullets' => ['uz' => ['IP54 himoya, soniyasiga kamida 200 skanerlash.'], 'ru' => ['Защита IP54, не менее 200 сканирований в секунду.'], 'en' => ['IP54 sealing, at least 200 scans per second.']],
        'specs' => ['scan_engine' => $k('2D imidjer'), 'symbologies' => $sym2d, 'interface' => $k(['USB']), 'ip_rating' => $k('IP54')],
    ]),
    $make([
        'sku' => '254', 'model' => 'HF680', 'brand' => 'Honeywell', 'category' => 'presentation-scanners', 'price' => 1450000,
        'kind' => 'scanner', 'variant' => 'pres',
        'name' => ['uz' => 'Honeywell HF680 stol usti 2D skaneri', 'ru' => 'Настольный 2D-сканер Honeywell HF680', 'en' => 'Honeywell HF680 desktop 2D scanner'],
        'short' => ['uz' => 'Stol usti 2D skaner USB va RS232 interfeyslari bilan, 1 m dan tushishga chidamli.', 'ru' => 'Настольный 2D-сканер с интерфейсами USB и RS232, устойчив к падениям с 1 м.', 'en' => 'Desktop 2D scanner with USB and RS232 interfaces and a 1 m drop rating.'],
        'intro' => ['uz' => '<strong>Honeywell HF680</strong> — qoʻlsiz ishlaydigan stol usti 2D skaner. Interfeys (USB yoki RS232) komplektatsiya kodiga bogʻliq.', 'ru' => '<strong>Honeywell HF680</strong> — настольный 2D-сканер для работы без рук. Интерфейс (USB или RS232) зависит от кода комплектации.', 'en' => 'The <strong>Honeywell HF680</strong> is a hands-free desktop 2D scanner. The interface (USB or RS232) depends on the kit part number.'],
        'bullets' => ['uz' => ['IP darajasi rasmiy varaqda tekshiriladi.'], 'ru' => ['Степень IP сверяется с официальным листом.'], 'en' => ['IP rating to be checked against the official datasheet.']],
        'specs' => ['scan_engine' => $k('2D imidjer'), 'symbologies' => $sym2d, 'interface' => $k(['USB', 'RS232']), 'drop_spec' => 1],
    ]),
    $make([
        'sku' => '141', 'model' => 'NT-2055', 'brand' => 'Netum', 'category' => 'presentation-scanners', 'price' => 671000,
        'kind' => 'scanner', 'variant' => 'pres', 'missing' => true,
        'name' => ['uz' => 'Netum NT-2055 stol usti 2D skaneri', 'ru' => 'Настольный 2D-сканер Netum NT-2055', 'en' => 'Netum NT-2055 desktop 2D scanner'],
        'short' => ['uz' => 'Qoʻlsiz ishlaydigan stol usti 2D skaner: 1D va 2D kodlarni qogʻoz va ekrandan oʻqiydi.', 'ru' => 'Настольный 2D-сканер для работы без рук: читает 1D и 2D коды с бумаги и экрана.', 'en' => 'Hands-free desktop 2D scanner reading 1D and 2D codes from paper and screens.'],
        'intro' => ['uz' => '<strong>Netum NT-2055</strong> — kassa uchun stol usti 2D skaner. Interfeys va himoya darajasi Poson menejeri tomonidan buyurtmadan oldin tasdiqlanadi.', 'ru' => '<strong>Netum NT-2055</strong> — настольный 2D-сканер для кассы. Интерфейс и степень защиты менеджер Poson подтвердит перед заказом.', 'en' => 'The <strong>Netum NT-2055</strong> is a desktop 2D scanner for checkouts. A Poson manager confirms the interface and protection rating before you order.'],
        'bullets' => ['uz' => ['Texnik xususiyatlar buyurtma paytida tasdiqlanadi.'], 'ru' => ['Технические характеристики подтверждаются при заказе.'], 'en' => ['Technical specifications are confirmed when you order.']],
        'specs' => ['scan_engine' => $k('2D imidjer')],
    ]),

    // ─── Android mobile computers ───────────────────────────────────────────
    $make([
        'sku' => '1', 'model' => 'P8100', 'brand' => 'Urovo', 'category' => 'android-mobile-computers', 'price' => 5445000,
        'kind' => 'tsd', 'variant' => 'tsd', 'missing' => true,
        'name' => ['uz' => 'Urovo P8100 himoyalangan Android planshet', 'ru' => 'Защищённый Android-планшет Urovo P8100', 'en' => 'Urovo P8100 rugged Android tablet'],
        'short' => ['uz' => 'Himoyalangan Android planshet: IP67, 1,2 m dan tushishga chidamli, ichki shtrix-kod skaneri.', 'ru' => 'Защищённый Android-планшет: IP67, падения с 1,2 м, встроенный сканер штрих-кодов.', 'en' => 'Rugged Android tablet with IP67, a 1.2 m drop rating and a built-in barcode scanner.'],
        'intro' => ['uz' => '<strong>Urovo P8100</strong> — ombor va logistika uchun himoyalangan planshet. Ekran oʻlchami (8 yoki 10,1 dyuym), Android versiyasi va skaner turi aniq komplektatsiyaga bogʻliq.', 'ru' => '<strong>Urovo P8100</strong> — защищённый планшет для склада и логистики. Размер экрана (8 или 10,1 дюйма), версия Android и тип сканера зависят от комплектации.', 'en' => 'The <strong>Urovo P8100</strong> is a rugged tablet for warehouses and logistics. Screen size (8 or 10.1 inch), Android version and scanner type depend on the exact configuration.'],
        'bullets' => ['uz' => ['IP67 himoya, 1,2 m dan tushishga chidamlilik.'], 'ru' => ['Защита IP67, устойчивость к падениям с 1,2 м.'], 'en' => ['IP67 sealing, 1.2 m drop rating.']],
        'specs' => ['ip_rating' => $k('IP67'), 'drop_spec' => 1.2],
    ]),
    $make([
        'sku' => '132', 'model' => 'DT40', 'brand' => 'Urovo', 'category' => 'android-mobile-computers', 'price' => 5667871,
        'kind' => 'tsd', 'variant' => 'tsd', 'missing' => true,
        'name' => ['uz' => 'Urovo DT40 Android maʼlumot yigʻish terminali (2/16 GB)', 'ru' => 'ТСД Urovo DT40 на Android (2/16 ГБ)', 'en' => 'Urovo DT40 Android mobile computer (2/16 GB)'],
        'short' => ['uz' => 'Klaviaturali Android TSD: 4 dyuymli ekran, 2D skaner, IP67, 1,5 m dan tushishga chidamli.', 'ru' => 'Android-ТСД с клавиатурой: экран 4 дюйма, 2D-сканер, IP67, падения с 1,5 м.', 'en' => 'Android mobile computer with a keypad, 4-inch screen, 2D scanner, IP67 and a 1.5 m drop rating.'],
        'intro' => ['uz' => '<strong>Urovo DT40</strong> — fizik klaviaturali ixcham Android terminal. Ushbu komplektatsiya 2 GB operativ va 16 GB doimiy xotira bilan.', 'ru' => '<strong>Urovo DT40</strong> — компактный Android-терминал с физической клавиатурой. Эта комплектация: 2 ГБ оперативной и 16 ГБ встроенной памяти.', 'en' => 'The <strong>Urovo DT40</strong> is a compact Android terminal with a physical keypad. This configuration has 2 GB RAM and 16 GB storage.'],
        'bullets' => ['uz' => ['4 dyuymli ekran, IP67, akkumulyator 4500 mAh.'], 'ru' => ['Экран 4 дюйма, IP67, аккумулятор 4500 мАч.'], 'en' => ['4-inch screen, IP67, 4500 mAh battery.']],
        'specs' => ['screen_size' => $k(4), 'scan_engine' => $k('2D imidjer'), 'ip_rating' => $k('IP67'), 'drop_spec' => 1.5, 'ram' => 2, 'storage' => 16, 'battery' => 4500],
        'related' => [['sku' => '174', 'type' => 'accessory']],
    ]),
    $make([
        'sku' => '134', 'model' => 'CT48', 'brand' => 'Urovo', 'category' => 'android-mobile-computers', 'price' => 4200000,
        'kind' => 'tsd', 'variant' => 'tsd',
        'name' => ['uz' => 'Urovo CT48 Android maʼlumot yigʻish terminali', 'ru' => 'ТСД Urovo CT48 на Android', 'en' => 'Urovo CT48 Android mobile computer'],
        'short' => ['uz' => 'Android 12 TSD: 4 dyuymli ekran, 2D skaner, IP65, 5000 mAh almashtiriladigan akkumulyator.', 'ru' => 'ТСД на Android 12: экран 4 дюйма, 2D-сканер, IP65, сменный аккумулятор 5000 мАч.', 'en' => 'Android 12 mobile computer with a 4-inch screen, 2D scanner, IP65 and a swappable 5000 mAh battery.'],
        'intro' => ['uz' => '<strong>Urovo CT48</strong> — Android 12 asosidagi yengil terminal. 4 dyuymli ekran, 2D skaner va 5000 mAh akkumulyator kundalik ombor va savdo vazifalariga mos.', 'ru' => '<strong>Urovo CT48</strong> — лёгкий терминал на Android 12. Экран 4 дюйма, 2D-сканер и аккумулятор 5000 мАч подходят для повседневных задач склада и торговли.', 'en' => 'The <strong>Urovo CT48</strong> is a lightweight Android 12 terminal. A 4-inch screen, 2D scanner and 5000 mAh battery suit everyday warehouse and retail tasks.'],
        'bullets' => ['uz' => ['IP65, 1,5 m dan tushishga chidamlilik.'], 'ru' => ['IP65, устойчивость к падениям с 1,5 м.'], 'en' => ['IP65, 1.5 m drop rating.']],
        'specs' => ['os' => $k('Android 12'), 'screen_size' => $k(4), 'scan_engine' => $k('2D imidjer'), 'ip_rating' => $k('IP65'), 'drop_spec' => 1.5, 'battery' => 5000],
        'related' => [['sku' => '177', 'type' => 'accessory'], ['sku' => '176', 'type' => 'accessory']],
    ]),
    $make([
        'sku' => '133', 'model' => 'CT58', 'brand' => 'Urovo', 'category' => 'android-mobile-computers', 'price' => 4778025,
        'kind' => 'tsd', 'variant' => 'tsd',
        'name' => ['uz' => 'Urovo CT58 Android maʼlumot yigʻish terminali', 'ru' => 'ТСД Urovo CT58 на Android', 'en' => 'Urovo CT58 Android mobile computer'],
        'short' => ['uz' => 'Android 12 TSD: 5,5 dyuymli ekran, 4/64 GB, 2D skaner, IP65, 5000 mAh akkumulyator.', 'ru' => 'ТСД на Android 12: экран 5,5 дюйма, 4/64 ГБ, 2D-сканер, IP65, аккумулятор 5000 мАч.', 'en' => 'Android 12 mobile computer with a 5.5-inch screen, 4/64 GB, 2D scanner, IP65 and a 5000 mAh battery.'],
        'intro' => ['uz' => '<strong>Urovo CT58</strong> — 5,5 dyuymli ekranli Android 12 terminal. 4 GB operativ va 64 GB doimiy xotira microSD orqali kengaytiriladi.', 'ru' => '<strong>Urovo CT58</strong> — терминал на Android 12 с экраном 5,5 дюйма. 4 ГБ оперативной и 64 ГБ встроенной памяти, расширение через microSD.', 'en' => 'The <strong>Urovo CT58</strong> is an Android 12 terminal with a 5.5-inch screen. It has 4 GB RAM and 64 GB storage, expandable by microSD.'],
        'bullets' => ['uz' => ['IP65, 1,5 m dan tushishga chidamlilik, vazni taxminan 265 g.'], 'ru' => ['IP65, устойчивость к падениям с 1,5 м, вес около 265 г.'], 'en' => ['IP65, 1.5 m drop rating, about 265 g.']],
        'specs' => ['os' => $k('Android 12'), 'screen_size' => $k(5.5), 'scan_engine' => $k('2D imidjer'), 'ip_rating' => $k('IP65'), 'drop_spec' => 1.5, 'ram' => 4, 'storage' => 64, 'battery' => 5000],
    ]),
    $make([
        'sku' => '59', 'model' => 'DT50', 'brand' => 'Urovo', 'category' => 'android-mobile-computers', 'price' => 7500000,
        'kind' => 'tsd', 'variant' => 'tsd',
        'name' => ['uz' => 'Urovo DT50 Android maʼlumot yigʻish terminali', 'ru' => 'ТСД Urovo DT50 на Android', 'en' => 'Urovo DT50 Android mobile computer'],
        'short' => ['uz' => 'Himoyalangan Android TSD: 5,7 dyuymli ekran, 4/64 GB, 2D skaner, IP67, 5000 mAh.', 'ru' => 'Защищённый Android-ТСД: экран 5,7 дюйма, 4/64 ГБ, 2D-сканер, IP67, 5000 мАч.', 'en' => 'Rugged Android mobile computer with a 5.7-inch screen, 4/64 GB, 2D scanner, IP67 and 5000 mAh.'],
        'intro' => ['uz' => '<strong>Urovo DT50</strong> — ombor va savdo uchun himoyalangan Android terminal. Standart konfiguratsiya: Android 11, 4 GB RAM, 64 GB xotira.', 'ru' => '<strong>Urovo DT50</strong> — защищённый Android-терминал для склада и торговли. Базовая конфигурация: Android 11, 4 ГБ ОЗУ, 64 ГБ памяти.', 'en' => 'The <strong>Urovo DT50</strong> is a rugged Android terminal for warehouses and retail. Base configuration: Android 11, 4 GB RAM, 64 GB storage.'],
        'bullets' => ['uz' => ['IP67, 1,5 m dan tushishga chidamlilik; Wi-Fi, Bluetooth va 4G.'], 'ru' => ['IP67, падения с 1,5 м; Wi-Fi, Bluetooth и 4G.'], 'en' => ['IP67, 1.5 m drop rating; Wi-Fi, Bluetooth and 4G.']],
        'specs' => ['os' => $k('Android 11'), 'screen_size' => $k(5.7), 'scan_engine' => $k('2D imidjer'), 'ip_rating' => $k('IP67'), 'interface' => ['USB', 'Bluetooth', 'Wi-Fi', 'LTE'], 'drop_spec' => 1.5, 'ram' => 4, 'storage' => 64, 'battery' => 5000],
        'related' => [['sku' => '61', 'type' => 'accessory'], ['sku' => '60', 'type' => 'accessory']],
    ]),

    // ─── RFID mobile computers ──────────────────────────────────────────────
    $make([
        'sku' => '238', 'model' => 'DT50P', 'brand' => 'Urovo', 'category' => 'rfid-mobile-computers', 'price' => 12000000,
        'kind' => 'tsd', 'variant' => 'tsd', 'missing' => true,
        'name' => ['uz' => 'Urovo DT50P UHF RFID terminali', 'ru' => 'ТСД Urovo DT50P с UHF RFID', 'en' => 'Urovo DT50P UHF RFID mobile computer'],
        'short' => ['uz' => 'Android TSD UHF RFID bilan: 5,7 dyuymli ekran, 2D skaner, IP67, 20 m dan ortiq oʻqish masofasi.', 'ru' => 'Android-ТСД с UHF RFID: экран 5,7 дюйма, 2D-сканер, IP67, дальность чтения свыше 20 м.', 'en' => 'Android mobile computer with UHF RFID, 5.7-inch screen, 2D scanner, IP67 and over 20 m read range.'],
        'intro' => ['uz' => '<strong>Urovo DT50P</strong> — shtrix-kod skaneri va UHF RFID oʻquvchisini birlashtirgan terminal. Impinj chipli modul 865–868 va 902–928 MHz diapazonlarida ishlaydi.', 'ru' => '<strong>Urovo DT50P</strong> — терминал, объединяющий сканер штрих-кодов и UHF RFID-считыватель. Модуль на чипе Impinj работает в диапазонах 865–868 и 902–928 МГц.', 'en' => 'The <strong>Urovo DT50P</strong> combines a barcode scanner with a UHF RFID reader. The Impinj-based module covers the 865–868 and 902–928 MHz bands.'],
        'bullets' => ['uz' => ['IP67, 1,2 m dan tushishga chidamlilik; 4/64 GB.'], 'ru' => ['IP67, падения с 1,2 м; 4/64 ГБ.'], 'en' => ['IP67, 1.2 m drop rating; 4/64 GB.']],
        'specs' => ['rfid_frequency' => $k(['UHF 860–960 MHz']), 'screen_size' => $k(5.7), 'scan_engine' => $k('2D imidjer'), 'ip_rating' => $k('IP67'), 'drop_spec' => 1.2, 'ram' => 4, 'storage' => 64, 'read_range' => 20],
    ]),

    // ─── Accessories ────────────────────────────────────────────────────────
    $make([
        'sku' => '253', 'model' => 'DS22 USB kabeli', 'brand' => 'Zebra', 'category' => 'accessories', 'price' => 312000,
        'kind' => 'accessory', 'missing' => true,
        'name' => ['uz' => 'Zebra DS22 seriyasi skanerlari uchun kabel', 'ru' => 'Кабель для сканеров Zebra серии DS22', 'en' => 'Cable for Zebra DS22 series scanners'],
        'short' => ['uz' => 'Zebra DS22 seriyasi (masalan, DS2208) skanerlari uchun ulash kabeli.', 'ru' => 'Соединительный кабель для сканеров Zebra серии DS22, например DS2208.', 'en' => 'Connection cable for Zebra DS22 series scanners such as the DS2208.'],
        'intro' => ['uz' => 'Zebra DS22 seriyasi skanerlarini kompyuter yoki kassaga ulash uchun kabel.', 'ru' => 'Кабель для подключения сканеров Zebra серии DS22 к компьютеру или кассе.', 'en' => 'A cable for connecting Zebra DS22 series scanners to a computer or till.'],
        'bullets' => ['uz' => ['Mos qurilma: Zebra DS2208.'], 'ru' => ['Совместимо: Zebra DS2208.'], 'en' => ['Fits: Zebra DS2208.']],
        'related' => [['sku' => '257', 'type' => 'accessory'], ['sku' => '6', 'type' => 'accessory']],
    ]),
    $make([
        'sku' => '247', 'model' => 'H500 qoʻlqopi', 'brand' => 'Henex', 'category' => 'accessories', 'price' => 200000,
        'kind' => 'accessory', 'missing' => true,
        'name' => ['uz' => 'Henex H500 uchun qoʻlqop', 'ru' => 'Перчатка для сканера-кольца Henex H500', 'en' => 'Glove for the Henex H500 ring scanner'],
        'short' => ['uz' => 'Henex H500 halqa-skanerini qoʻlda mahkam ushlab turuvchi qoʻlqop.', 'ru' => 'Перчатка для надёжного крепления сканера-кольца Henex H500 на руке.', 'en' => 'Glove that holds the Henex H500 ring scanner securely on the hand.'],
        'intro' => ['uz' => 'Henex H500 barmoqqa taqiladigan skaneri uchun qoʻlqop.', 'ru' => 'Перчатка для носимого сканера Henex H500.', 'en' => 'A glove for the wearable Henex H500 scanner.'],
        'bullets' => ['uz' => ['Mos qurilma: Henex H500.'], 'ru' => ['Совместимо: Henex H500.'], 'en' => ['Fits: Henex H500.']],
        'related' => [['sku' => '36', 'type' => 'accessory']],
    ]),
    $make([
        'sku' => '177', 'model' => 'CT48 kredl', 'brand' => 'Urovo', 'category' => 'accessories', 'price' => 750000,
        'kind' => 'accessory', 'missing' => true,
        'name' => ['uz' => 'Urovo CT48 uchun zaryadlash kredli', 'ru' => 'Зарядная подставка (кредл) для Urovo CT48', 'en' => 'Charging cradle for the Urovo CT48'],
        'short' => ['uz' => 'Urovo CT48 terminalini zaryadlash uchun stol usti kredl.', 'ru' => 'Настольная зарядная подставка для терминала Urovo CT48.', 'en' => 'Desktop charging cradle for the Urovo CT48 terminal.'],
        'intro' => ['uz' => 'Urovo CT48 terminalini smena oxirida zaryadlash uchun kredl.', 'ru' => 'Кредл для зарядки терминала Urovo CT48 в конце смены.', 'en' => 'A cradle for charging the Urovo CT48 at the end of a shift.'],
        'bullets' => ['uz' => ['Mos qurilma: Urovo CT48.'], 'ru' => ['Совместимо: Urovo CT48.'], 'en' => ['Fits: Urovo CT48.']],
        'related' => [['sku' => '134', 'type' => 'accessory']],
    ]),
    $make([
        'sku' => '241', 'model' => 'CT58S kredl', 'brand' => 'Urovo', 'category' => 'accessories', 'price' => 750000,
        'kind' => 'accessory', 'missing' => true,
        'name' => ['uz' => 'Urovo CT58S uchun zaryadlash kredli', 'ru' => 'Зарядная подставка (кредл) для Urovo CT58S', 'en' => 'Charging cradle for the Urovo CT58S'],
        'short' => ['uz' => 'Urovo CT58S terminalini zaryadlash uchun stol usti kredl.', 'ru' => 'Настольная зарядная подставка для терминала Urovo CT58S.', 'en' => 'Desktop charging cradle for the Urovo CT58S terminal.'],
        'intro' => ['uz' => 'Urovo CT58S terminali uchun kredl. CT58 bilan mosligi tasdiqlanadi.', 'ru' => 'Кредл для терминала Urovo CT58S. Совместимость с CT58 уточняется.', 'en' => 'A cradle for the Urovo CT58S terminal. Compatibility with the CT58 is to be confirmed.'],
        'bullets' => ['uz' => ['Mos qurilma: Urovo CT58S.'], 'ru' => ['Совместимо: Urovo CT58S.'], 'en' => ['Fits: Urovo CT58S.']],
    ]),
    $make([
        'sku' => '174', 'model' => 'DT40 kredl', 'brand' => 'Urovo', 'category' => 'accessories', 'price' => 750000,
        'kind' => 'accessory', 'missing' => true,
        'name' => ['uz' => 'Urovo DT40 uchun zaryadlash kredli', 'ru' => 'Зарядная подставка (кредл) для Urovo DT40', 'en' => 'Charging cradle for the Urovo DT40'],
        'short' => ['uz' => 'Urovo DT40 terminalini zaryadlash uchun stol usti kredl.', 'ru' => 'Настольная зарядная подставка для терминала Urovo DT40.', 'en' => 'Desktop charging cradle for the Urovo DT40 terminal.'],
        'intro' => ['uz' => 'Urovo DT40 terminalini zaryadlash va saqlash uchun kredl.', 'ru' => 'Кредл для зарядки и хранения терминала Urovo DT40.', 'en' => 'A cradle for charging and docking the Urovo DT40 terminal.'],
        'bullets' => ['uz' => ['Mos qurilma: Urovo DT40.'], 'ru' => ['Совместимо: Urovo DT40.'], 'en' => ['Fits: Urovo DT40.']],
        'related' => [['sku' => '132', 'type' => 'accessory']],
    ]),
    $make([
        'sku' => '61', 'model' => 'DT50 kredl', 'brand' => 'Urovo', 'category' => 'accessories', 'price' => null,
        'kind' => 'accessory', 'missing' => true,
        'name' => ['uz' => 'Urovo DT50 uchun zaryadlash kredli', 'ru' => 'Зарядная подставка (кредл) для Urovo DT50', 'en' => 'Charging cradle for the Urovo DT50'],
        'short' => ['uz' => 'Urovo DT50 terminalini zaryadlash uchun stol usti kredl. Narx soʻrov boʻyicha.', 'ru' => 'Настольная зарядная подставка для терминала Urovo DT50. Стоимость по запросу.', 'en' => 'Desktop charging cradle for the Urovo DT50 terminal. Quote on request.'],
        'intro' => ['uz' => 'Urovo DT50 terminalini zaryadlash uchun kredl.', 'ru' => 'Кредл для зарядки терминала Urovo DT50.', 'en' => 'A cradle for charging the Urovo DT50 terminal.'],
        'bullets' => ['uz' => ['Mos qurilma: Urovo DT50.'], 'ru' => ['Совместимо: Urovo DT50.'], 'en' => ['Fits: Urovo DT50.']],
        'related' => [['sku' => '59', 'type' => 'accessory']],
    ]),
    $make([
        'sku' => '240', 'model' => 'DT50B kredl', 'brand' => 'Urovo', 'category' => 'accessories', 'price' => 750000,
        'kind' => 'accessory', 'missing' => true,
        'name' => ['uz' => 'Urovo DT50B uchun zaryadlash kredli', 'ru' => 'Зарядная подставка (кредл) для Urovo DT50B', 'en' => 'Charging cradle for the Urovo DT50B'],
        'short' => ['uz' => 'Urovo DT50B terminalini zaryadlash uchun stol usti kredl.', 'ru' => 'Настольная зарядная подставка для терминала Urovo DT50B.', 'en' => 'Desktop charging cradle for the Urovo DT50B terminal.'],
        'intro' => ['uz' => 'Urovo DT50B terminali uchun kredl. DT50 bilan mosligi tasdiqlanadi.', 'ru' => 'Кредл для терминала Urovo DT50B. Совместимость с DT50 уточняется.', 'en' => 'A cradle for the Urovo DT50B terminal. Compatibility with the DT50 is to be confirmed.'],
        'bullets' => ['uz' => ['Mos qurilma: Urovo DT50B.'], 'ru' => ['Совместимо: Urovo DT50B.'], 'en' => ['Fits: Urovo DT50B.']],
    ]),
    $make([
        'sku' => '176', 'model' => 'CT48 dastasi', 'brand' => 'Urovo', 'category' => 'accessories', 'price' => null,
        'kind' => 'accessory', 'missing' => true,
        'name' => ['uz' => 'Urovo CT48 uchun pistolet dastasi', 'ru' => 'Пистолетная рукоятка для Urovo CT48', 'en' => 'Pistol-grip handle for the Urovo CT48'],
        'short' => ['uz' => 'Urovo CT48 terminalini uzoq skanerlash uchun qulay ushlash dastasi. Narx soʻrov boʻyicha.', 'ru' => 'Рукоятка для удобного длительного сканирования терминалом Urovo CT48. Стоимость по запросу.', 'en' => 'Handle for comfortable extended scanning with the Urovo CT48. Quote on request.'],
        'intro' => ['uz' => 'Urovo CT48 terminaliga ulanadigan dasta (rukoyatka).', 'ru' => 'Рукоятка для терминала Urovo CT48.', 'en' => 'A handle for the Urovo CT48 terminal.'],
        'bullets' => ['uz' => ['Mos qurilma: Urovo CT48.'], 'ru' => ['Совместимо: Urovo CT48.'], 'en' => ['Fits: Urovo CT48.']],
        'related' => [['sku' => '134', 'type' => 'accessory']],
    ]),
    $make([
        'sku' => '60', 'model' => 'DT50 dastasi', 'brand' => 'Urovo', 'category' => 'accessories', 'price' => null,
        'kind' => 'accessory', 'missing' => true,
        'name' => ['uz' => 'Urovo DT50 uchun pistolet dastasi', 'ru' => 'Пистолетная рукоятка для Urovo DT50', 'en' => 'Pistol-grip handle for the Urovo DT50'],
        'short' => ['uz' => 'Urovo DT50 terminalini uzoq skanerlash uchun qulay ushlash dastasi. Narx soʻrov boʻyicha.', 'ru' => 'Рукоятка для удобного длительного сканирования терминалом Urovo DT50. Стоимость по запросу.', 'en' => 'Handle for comfortable extended scanning with the Urovo DT50. Quote on request.'],
        'intro' => ['uz' => 'Urovo DT50 terminaliga ulanadigan dasta (rukoyatka).', 'ru' => 'Рукоятка для терминала Urovo DT50.', 'en' => 'A handle for the Urovo DT50 terminal.'],
        'bullets' => ['uz' => ['Mos qurilma: Urovo DT50.'], 'ru' => ['Совместимо: Urovo DT50.'], 'en' => ['Fits: Urovo DT50.']],
        'related' => [['sku' => '59', 'type' => 'accessory']],
    ]),
    $make([
        'sku' => '175', 'model' => 'TR40 dastasi', 'brand' => 'Urovo', 'category' => 'accessories', 'price' => 750000,
        'kind' => 'accessory', 'missing' => true,
        'name' => ['uz' => 'Urovo TR40 uchun pistolet dastasi', 'ru' => 'Пистолетная рукоятка для Urovo TR40', 'en' => 'Pistol-grip handle for the Urovo TR40'],
        'short' => ['uz' => 'Urovo TR40 uchun ushlash dastasi (rukoyatka). Texnik tafsilotlar tasdiqlanadi.', 'ru' => 'Рукоятка для Urovo TR40. Технические детали уточняются.', 'en' => 'Handle for the Urovo TR40. Technical details are confirmed on request.'],
        'intro' => ['uz' => 'Urovo TR40 uchun dasta (rukoyatka).', 'ru' => 'Рукоятка для Urovo TR40.', 'en' => 'A handle for the Urovo TR40. The TR40 device is not in the catalog yet.'],
        'bullets' => ['uz' => ['Mos qurilma: Urovo TR40.'], 'ru' => ['Совместимо: Urovo TR40.'], 'en' => ['Fits: Urovo TR40.']],
    ]),
    $make([
        'sku' => '239', 'model' => 'CUT-MB240', 'brand' => 'TSC', 'category' => 'accessories', 'price' => 4500000,
        'kind' => 'accessory',
        'name' => ['uz' => 'TSC CUT-MB240 kesish moduli', 'ru' => 'Модуль отрезчика TSC CUT-MB240', 'en' => 'TSC CUT-MB240 cutter module'],
        'short' => ['uz' => 'TSC MB240/MB241 seriyasi printerlari uchun kesish moduli: yorliqlarni chop etgandan keyin kesadi.', 'ru' => 'Модуль отрезки для принтеров TSC серий MB240/MB241: режет этикетки после печати.', 'en' => 'Cutter module for TSC MB240 and MB241 series printers that cuts labels after printing.'],
        'intro' => ['uz' => '<strong>TSC CUT-MB240</strong> — MB240/MB241 seriyasi etiketka printerlari uchun kesish moduli (1 toʻplam). Moslik printer modeli boʻyicha tasdiqlanadi.', 'ru' => '<strong>TSC CUT-MB240</strong> — модуль отрезчика для принтеров этикеток серий MB240/MB241 (1 комплект). Совместимость подтверждается по модели принтера.', 'en' => 'The <strong>TSC CUT-MB240</strong> is a cutter module for MB240 and MB241 series label printers (1 set). Fit is confirmed against your printer model.'],
        'bullets' => ['uz' => ['Foydalanuvchi oʻzi oʻrnatishi mumkin; Poson oʻrnatishda yordam beradi.'], 'ru' => ['Может устанавливаться пользователем; Poson поможет с установкой.'], 'en' => ['Can be user-installed; Poson can assist with installation.']],
    ]),
    $make([
        'sku' => '53', 'model' => 'NG A-6', 'brand' => 'Nishon', 'category' => 'accessories', 'price' => 1920004,
        'kind' => 'accessory', 'missing' => true,
        'name' => ['uz' => 'Yorliq qayta oʻrash qurilmasi NG A-6', 'ru' => 'Перемотчик этикеток NG A-6', 'en' => 'NG A-6 label rewinder'],
        'short' => ['uz' => 'Yorliq lentasini qayta oʻrash qurilmasi: rulonlarni tayyorlash va qayta oʻrash uchun.', 'ru' => 'Перемотчик этикеточной ленты для подготовки и перемотки рулонов.', 'en' => 'Label rewinder for preparing and rewinding label rolls.'],
        'intro' => ['uz' => '<strong>NG A-6</strong> — yorliq rulonlarini qayta oʻrash qurilmasi. 1C da brend Poson/Nishon sifatida koʻrsatilgan.', 'ru' => '<strong>NG A-6</strong> — перемотчик рулонов этикеток. В 1С бренд указан как Poson/Nishon.', 'en' => 'The <strong>NG A-6</strong> is a label roll rewinder. The brand is listed as Poson/Nishon in 1C.'],
        'bullets' => ['uz' => ['Etiketka rulonlari bilan ishlovchi ombor va ishlab chiqarish uchun.'], 'ru' => ['Для складов и производств, работающих с рулонами этикеток.'], 'en' => ['For warehouses and production lines handling label rolls.']],
    ]),
    $make([
        'sku' => '34', 'model' => 'Postavka 1', 'brand' => 'Gainscha', 'category' => 'accessories', 'price' => 199336,
        'kind' => 'accessory', 'missing' => true,
        'name' => ['uz' => 'Gainscha rulon uchun tayanch (Postavka 1)', 'ru' => 'Подставка под рулон Gainscha (Postavka 1)', 'en' => 'Gainscha roll holder (Postavka 1)'],
        'short' => ['uz' => 'Gainscha printerlari uchun yorliq yoki chek rulonini ushlab turuvchi tayanch. Moslik tasdiqlanadi.', 'ru' => 'Подставка под рулон этикеток или чековой ленты для принтеров Gainscha. Совместимость уточняется.', 'en' => 'Roll holder for label or receipt rolls on Gainscha printers. Fit is confirmed on request.'],
        'intro' => ['uz' => 'Gainscha printerlari uchun rulon tayanchi.', 'ru' => 'Подставка под рулон для принтеров Gainscha.', 'en' => 'A roll holder for Gainscha printers.'],
        'bullets' => ['uz' => ['Rulonni printer yonida barqaror ushlab turadi.'], 'ru' => ['Удерживает рулон рядом с принтером.'], 'en' => ['Keeps the roll steady next to the printer.']],
    ]),
    $make([
        'sku' => '258', 'model' => 'GP-M322 akkumulyatori', 'brand' => 'Gainscha', 'category' => 'accessories', 'price' => null,
        'kind' => 'accessory', 'missing' => true,
        'name' => ['uz' => 'Gainscha GP-M322 mobil printeri uchun akkumulyator', 'ru' => 'Аккумулятор для мобильного принтера Gainscha GP-M322', 'en' => 'Battery for the Gainscha GP-M322 mobile printer'],
        'short' => ['uz' => 'Gainscha GP-M322 mobil printeri uchun almashtiriladigan akkumulyator. Narx soʻrov boʻyicha.', 'ru' => 'Сменный аккумулятор для мобильного принтера Gainscha GP-M322. Стоимость по запросу.', 'en' => 'Replacement battery for the Gainscha GP-M322 mobile printer. Quote on request.'],
        'intro' => ['uz' => 'Gainscha GP-M322 mobil printeri uchun akkumulyator.', 'ru' => 'Аккумулятор для мобильного принтера Gainscha GP-M322.', 'en' => 'A battery for the Gainscha GP-M322 mobile printer.'],
        'bullets' => ['uz' => ['Mos qurilma: Gainscha GP-M322.'], 'ru' => ['Совместимо: Gainscha GP-M322.'], 'en' => ['Fits: Gainscha GP-M322.']],
    ]),
    $make([
        'sku' => '229', 'model' => 'DBR 39', 'brand' => 'EPG', 'category' => 'accessories', 'price' => 11000,
        'kind' => 'accessory',
        'name' => ['uz' => 'EPG DBR 39 yelim qatlamli javon narx kartasi ushlagichi', 'ru' => 'Ценникодержатель полочный самоклеящийся EPG DBR 39', 'en' => 'EPG DBR 39 self-adhesive shelf price-tag holder'],
        'short' => ['uz' => 'Javonga yopishtiriladigan narx kartasi ushlagichi, balandligi 39 mm.', 'ru' => 'Самоклеящийся полочный ценникодержатель высотой 39 мм.', 'en' => 'Self-adhesive shelf price-tag holder, 39 mm high.'],
        'intro' => ['uz' => '<strong>EPG DBR 39</strong> — javon chetiga yopishtiriladigan, balandligi 39 mm boʻlgan narx kartasi ushlagichi. Doʻkon javonlarida yorliqlarni tartibli joylashtirish uchun.', 'ru' => '<strong>EPG DBR 39</strong> — самоклеящийся полочный ценникодержатель высотой 39 мм. Помогает аккуратно разместить этикетки на полках магазина.', 'en' => 'The <strong>EPG DBR 39</strong> is a self-adhesive shelf price-tag holder, 39 mm high, for neatly displaying labels on store shelves.'],
        'bullets' => ['uz' => ['Chakana savdo: javon yorliqlarini yagona koʻrinishda joylashtirish.'], 'ru' => ['Розница: единообразное размещение полочных этикеток.'], 'en' => ['Retail: consistent placement of shelf labels.']],
    ]),
    $make([
        'sku' => '215', 'model' => 'ERGO Pro', 'brand' => 'Pospole', 'category' => 'accessories', 'price' => 5000000,
        'kind' => 'accessory', 'missing' => true,
        'name' => ['uz' => 'Pospole ERGO Pro ergonomik kassa tayanchi', 'ru' => 'Эргономическая стойка Pospole ERGO Pro', 'en' => 'Pospole ERGO Pro ergonomic stand'],
        'short' => ['uz' => 'Kassa ish oʻrni uchun ergonomik tayanch (stoyka). Texnik tafsilotlar tasdiqlanadi.', 'ru' => 'Эргономическая стойка для кассового рабочего места. Технические детали уточняются.', 'en' => 'Ergonomic stand for a checkout workstation. Technical details are confirmed on request.'],
        'intro' => ['uz' => '<strong>Pospole ERGO Pro</strong> — kassa ish oʻrni uchun ergonomik stoyka.', 'ru' => '<strong>Pospole ERGO Pro</strong> — эргономическая стойка для кассового рабочего места.', 'en' => '<strong>Pospole ERGO Pro</strong> is an ergonomic stand for a checkout workstation.'],
        'bullets' => ['uz' => ['Chakana savdo: kassir uchun qulay joylashuv.'], 'ru' => ['Розница: удобное размещение оборудования для кассира.'], 'en' => ['Retail: comfortable equipment positioning for the cashier.']],
    ]),
];
