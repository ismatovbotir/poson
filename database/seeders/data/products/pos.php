<?php

/**
 * Real catalog data, group "pos" (from docs/pm/import/products-base.json, 1C export).
 *
 * Row shape = products.php plus two internal keys:
 * - `has_missing_specs`: true when a required spec could not be verified (1C name only).
 *
 * `sku` is the 1C code (string). `price` = retail whole soʻm (null = price on request).
 * Warranty term is not confirmed for any item, so `warranty_months` is null.
 * Uzbek is Latin: oʻ, gʻ use ʻ (U+02BB); the tutuq belgisi uses ʼ (U+02BC).
 *
 * @return list<array<string, mixed>>
 */
$li = static fn (string ...$items): string => '<li>'.implode('</li><li>', $items).'</li>';

$desc = static function (array $lead, array $items, array $service): array {
    $out = [];
    foreach (['uz', 'ru', 'en'] as $locale) {
        $out[$locale] = '<p>'.$lead[$locale].'</p><ul>'.$items[$locale].'</ul><p>'.$service[$locale].'</p>';
    }

    return $out;
};

$svc = [
    'printer' => [
        'uz' => 'Poson printerni oʻrnatadi, drayver va chek shablonini sozlaydi, kerak boʻlsa pul yashigini ulaydi va kassa dasturi yoki 1C bilan ishlashini tekshiradi. Kafolatli taʼmirlash oʻz servis markazimizda bajariladi.',
        'ru' => 'Poson установит принтер, настроит драйвер и шаблон чека, при необходимости подключит денежный ящик и проверит работу с кассовой программой или 1С. Гарантийный ремонт выполняется в нашем сервисном центре.',
        'en' => 'Poson installs the printer, sets up the driver and receipt layout, connects a cash drawer if needed and checks it with your POS software or 1C. Warranty repairs are handled in our own service center.',
    ],
    'drawer' => [
        'uz' => 'Poson yashikni chek printeriga ulaydi, kerak boʻlsa kabelni printer modeliga moslaydi va chek chop etilganda avtomatik ochilishni sozlaydi. Kafolatli servis — oʻz servis markazimizda.',
        'ru' => 'Poson подключит ящик к чековому принтеру, при необходимости подберёт кабель под модель принтера и настроит автоматическое открытие при печати чека. Гарантийный сервис — в нашем сервисном центре.',
        'en' => 'Poson connects the drawer to your receipt printer, matches the cable to the printer model if needed and sets the drawer to open automatically when a receipt prints. Warranty service is handled in our own service center.',
    ],
    'terminal' => [
        'uz' => 'Poson qurilmani yetkazib beradi, operatsion tizim va kassa dasturini oʻrnatadi, printer, skaner va pul yashigini ulaydi hamda 1C bilan integratsiyani sozlaydi. Kafolat va servis — oʻz servis markazimizda.',
        'ru' => 'Poson поставит устройство, установит систему и кассовую программу, подключит принтер, сканер и денежный ящик и настроит интеграцию с 1С. Гарантия и сервис — в нашем сервисном центре.',
        'en' => 'Poson delivers the device, installs the OS and POS software, connects the printer, scanner and cash drawer and sets up the 1C integration. Warranty and service are handled in our own service center.',
    ],
    'scale' => [
        'uz' => 'Poson tarozini oʻrnatadi, mahsulot bazasi (PLU) va yorliq shablonini sozlaydi, kerak boʻlsa kassa dasturi yoki 1C bilan bogʻlaydi. Kafolatli servis — oʻz servis markazimizda.',
        'ru' => 'Poson установит весы, настроит базу товаров (PLU) и шаблон этикетки и при необходимости свяжет их с кассовой программой или 1С. Гарантийный сервис — в нашем сервисном центре.',
        'en' => 'Poson installs the scale, sets up the product (PLU) database and label layout and, if needed, links it to your POS software or 1C. Warranty service is handled in our own service center.',
    ],
];

$make = static function (array $r): array {
    return [
        'sku' => $r['sku'],
        'model' => $r['model'],
        'brand' => $r['brand'],
        'category' => $r['category'],
        'type' => 'hardware',
        'unit' => 'piece',
        'min_qty' => 1,
        'sale_mode' => $r['price'] === null ? 'quote' : 'both',
        'stock_status' => 'on_order',
        'warranty_months' => null,
        'badge' => null,
        'is_featured' => false,
        'price' => $r['price'] === null ? null : (int) round($r['price']),
        'price_dealer' => null,
        'price_wholesale' => null,
        'name' => $r['name'],
        'short_description' => $r['short'],
        'description' => $r['desc'],
        'meta_title' => $r['mt'],
        'meta_description' => $r['md'],
        'specs' => $r['specs'],
        'related' => [],
        'has_missing_specs' => $r['missing'],
    ];
};

return [

    // ─── Receipt printers ───────────────────────────────────────────────────
    $make([
        'sku' => '138', 'model' => 'XP-58IIT', 'brand' => 'Xprinter', 'category' => 'receipt-printers', 'price' => 278300.0,
        'name' => [
            'uz' => 'Xprinter XP-58IIT chek printeri, 58 mm',
            'ru' => 'Чековый принтер Xprinter XP-58IIT, 58 мм',
            'en' => 'Xprinter XP-58IIT receipt printer, 58 mm',
        ],
        'short' => [
            'uz' => 'Ixcham 58 mm termo chek printeri: kichik doʻkon, kafe va xizmat koʻrsatish nuqtalari uchun, oʻrnatish va sozlash bilan.',
            'ru' => 'Компактный термопринтер чеков 58 мм для небольших магазинов, кафе и сервисных точек, с установкой и настройкой.',
            'en' => 'Compact 58 mm thermal receipt printer for small shops, cafés and service counters, delivered with installation and setup.',
        ],
        'desc' => $desc(
            [
                'uz' => '<strong>Xprinter XP-58IIT</strong> — kassa stolida kam joy egallaydigan ixcham 58 mm termo chek printeri. Cheklar oqimi oʻrtacha boʻlgan savdo nuqtalari uchun mos.',
                'ru' => '<strong>Xprinter XP-58IIT</strong> — компактный термопринтер чеков 58 мм, который занимает минимум места на кассе. Подходит для точек со средним потоком чеков.',
                'en' => 'The <strong>Xprinter XP-58IIT</strong> is a compact 58 mm thermal receipt printer that takes up very little counter space. It suits outlets with moderate receipt volumes.',
            ],
            [
                'uz' => $li('<strong>Kichik doʻkonlar:</strong> kassa cheklari.', '<strong>Kafe:</strong> buyurtma va hisob cheklari.', '<strong>Xizmat nuqtalari:</strong> qabul kvitansiyalari va talonlar.', '58 mm termo lenta.'),
                'ru' => $li('<strong>Небольшие магазины:</strong> кассовые чеки.', '<strong>Кафе:</strong> чеки заказов и счета.', '<strong>Сервисные точки:</strong> квитанции приёма и талоны.', 'Термолента 58 мм.'),
                'en' => $li('<strong>Small shops:</strong> till receipts.', '<strong>Cafés:</strong> order receipts and bills.', '<strong>Service counters:</strong> drop-off slips and tickets.', '58 mm thermal paper.'),
            ],
            $svc['printer'],
        ),
        'mt' => [
            'uz' => 'Xprinter XP-58IIT chek printeri 58 mm — Poson',
            'ru' => 'Чековый принтер Xprinter XP-58IIT 58 мм — Poson',
            'en' => 'Xprinter XP-58IIT 58 mm receipt printer — Poson',
        ],
        'md' => [
            'uz' => 'Xprinter XP-58IIT ixcham 58 mm chek printeri: kichik doʻkon, kafe va xizmat nuqtalari uchun. Kassaga ulash, sozlash, kafolat va servis.',
            'ru' => 'Компактный чековый принтер Xprinter XP-58IIT 58 мм для магазинов, кафе и сервисных точек. Подключение к кассе, настройка, гарантия и сервис.',
            'en' => 'Xprinter XP-58IIT compact 58 mm receipt printer for shops, cafés and service counters. POS connection, setup, warranty and service.',
        ],
        'specs' => ['paper_width' => ['value' => '58', 'key' => true]],
        'missing' => true,
    ]),
    $make([
        'sku' => '234', 'model' => 'XP-58IIA (USB)', 'brand' => 'Xprinter', 'category' => 'receipt-printers', 'price' => 278300.0,
        'name' => [
            'uz' => 'Xprinter XP-58IIA chek printeri, 58 mm, USB',
            'ru' => 'Чековый принтер Xprinter XP-58IIA, 58 мм, USB',
            'en' => 'Xprinter XP-58IIA receipt printer, 58 mm, USB',
        ],
        'short' => [
            'uz' => 'USB orqali ulanadigan ixcham 58 mm chek printeri: kichik doʻkon, kafe va xizmat nuqtalari uchun.',
            'ru' => 'Компактный чековый принтер 58 мм с USB для небольших магазинов, кафе и сервисных точек.',
            'en' => 'Compact 58 mm USB receipt printer for small shops, cafés and service counters.',
        ],
        'desc' => $desc(
            [
                'uz' => '<strong>Xprinter XP-58IIA</strong> — USB interfeysli ixcham 58 mm termo chek printeri. Kassa kompyuteriga oson ulanadi va kam joy egallaydi.',
                'ru' => '<strong>Xprinter XP-58IIA</strong> — компактный термопринтер чеков 58 мм с интерфейсом USB. Легко подключается к кассовому компьютеру и занимает мало места.',
                'en' => 'The <strong>Xprinter XP-58IIA</strong> is a compact 58 mm thermal receipt printer with a USB interface. It connects easily to a checkout PC and takes up little space.',
            ],
            [
                'uz' => $li('<strong>Kichik doʻkonlar:</strong> kassa cheklari.', '<strong>Kafe va ekspress-nuqtalar:</strong> hisob va buyurtma cheklari.', '<strong>Xizmat nuqtalari:</strong> kvitansiya va talonlar.', '58 mm lenta, USB.'),
                'ru' => $li('<strong>Небольшие магазины:</strong> кассовые чеки.', '<strong>Кафе и экспресс-точки:</strong> счета и чеки заказов.', '<strong>Сервисные точки:</strong> квитанции и талоны.', 'Лента 58 мм, USB.'),
                'en' => $li('<strong>Small shops:</strong> till receipts.', '<strong>Cafés and kiosks:</strong> bills and order receipts.', '<strong>Service counters:</strong> slips and tickets.', '58 mm paper, USB.'),
            ],
            $svc['printer'],
        ),
        'mt' => [
            'uz' => 'Xprinter XP-58IIA chek printeri 58 mm USB — Poson',
            'ru' => 'Чековый принтер Xprinter XP-58IIA 58 мм USB — Poson',
            'en' => 'Xprinter XP-58IIA 58 mm USB receipt printer — Poson',
        ],
        'md' => [
            'uz' => 'Xprinter XP-58IIA: USB interfeysli ixcham 58 mm chek printeri kichik doʻkon, kafe va xizmat nuqtalari uchun. Ulash, sozlash, kafolat, servis.',
            'ru' => 'Xprinter XP-58IIA: компактный чековый принтер 58 мм с USB для небольших магазинов, кафе и сервисных точек. Подключение, настройка, гарантия.',
            'en' => 'Xprinter XP-58IIA compact 58 mm USB receipt printer for small shops, cafés and service counters. Connection, setup, warranty and service.',
        ],
        'specs' => [
            'paper_width' => ['value' => '58', 'key' => true],
            'interface' => ['value' => ['USB'], 'key' => true],
        ],
        'missing' => false,
    ]),
    $make([
        'sku' => '235', 'model' => 'XP-58IIA (USB + BT)', 'brand' => 'Xprinter', 'category' => 'receipt-printers', 'price' => 326700.0,
        'name' => [
            'uz' => 'Xprinter XP-58IIA chek printeri, 58 mm, USB + Bluetooth',
            'ru' => 'Чековый принтер Xprinter XP-58IIA, 58 мм, USB + Bluetooth',
            'en' => 'Xprinter XP-58IIA receipt printer, 58 mm, USB + Bluetooth',
        ],
        'short' => [
            'uz' => 'USB va Bluetooth interfeysli 58 mm chek printeri: kompyuter yoki planshetdan simsiz chop etish uchun.',
            'ru' => 'Чековый принтер 58 мм с USB и Bluetooth: печать с компьютера или планшета без проводов.',
            'en' => '58 mm receipt printer with USB and Bluetooth for wired or wireless printing from a PC or tablet.',
        ],
        'desc' => $desc(
            [
                'uz' => '<strong>Xprinter XP-58IIA</strong> USB va Bluetooth varianti kompyuterga simli, planshet yoki telefonga simsiz ulanadi. Ixcham 58 mm termo printer.',
                'ru' => 'Вариант <strong>Xprinter XP-58IIA</strong> с USB и Bluetooth подключается к компьютеру по проводу, а к планшету или телефону — без проводов. Компактный термопринтер 58 мм.',
                'en' => 'The <strong>Xprinter XP-58IIA</strong> USB and Bluetooth version connects to a PC by cable and to a tablet or phone wirelessly. A compact 58 mm thermal printer.',
            ],
            [
                'uz' => $li('<strong>Planshetli kassa:</strong> Bluetooth orqali chek chop etish.', '<strong>Kafe:</strong> zalda buyurtma va hisob cheklari.', '<strong>Yetkazib berish va koʻchma savdo:</strong> ixcham printer.', '58 mm lenta, USB va Bluetooth.'),
                'ru' => $li('<strong>Планшетная касса:</strong> печать чеков по Bluetooth.', '<strong>Кафе:</strong> чеки заказов и счета в зале.', '<strong>Доставка и выездная торговля:</strong> компактный принтер.', 'Лента 58 мм, USB и Bluetooth.'),
                'en' => $li('<strong>Tablet-based tills:</strong> receipts over Bluetooth.', '<strong>Cafés:</strong> order receipts and bills on the floor.', '<strong>Delivery and pop-up retail:</strong> a compact printer.', '58 mm paper, USB and Bluetooth.'),
            ],
            $svc['printer'],
        ),
        'mt' => [
            'uz' => 'Xprinter XP-58IIA chek printeri USB + Bluetooth — Poson',
            'ru' => 'Чековый принтер Xprinter XP-58IIA USB + BT — Poson',
            'en' => 'Xprinter XP-58IIA USB + Bluetooth receipt printer — Poson',
        ],
        'md' => [
            'uz' => 'Xprinter XP-58IIA 58 mm chek printeri USB va Bluetooth bilan: kompyuter va planshetdan chop etish. Kassaga ulash, sozlash, kafolat va servis.',
            'ru' => 'Xprinter XP-58IIA 58 мм с USB и Bluetooth: печать чеков с компьютера и планшета. Подключение к кассе, настройка, гарантия и сервис.',
            'en' => 'Xprinter XP-58IIA 58 mm receipt printer with USB and Bluetooth for PC and tablet tills. POS connection, setup, warranty and service.',
        ],
        'specs' => [
            'paper_width' => ['value' => '58', 'key' => true],
            'interface' => ['value' => ['USB', 'Bluetooth'], 'key' => true],
        ],
        'missing' => false,
    ]),
    $make([
        'sku' => '22', 'model' => 'GA-E200I (USB + LAN)', 'brand' => 'Gainscha', 'category' => 'receipt-printers', 'price' => 438988.0,
        'name' => [
            'uz' => 'Gainscha GA-E200I chek printeri, USB + LAN',
            'ru' => 'Чековый принтер Gainscha GA-E200I, USB + LAN',
            'en' => 'Gainscha GA-E200I receipt printer, USB + LAN',
        ],
        'short' => [
            'uz' => 'Termo chek printeri USB va Ethernet interfeysli: bir nechta kassani tarmoq orqali bitta printerga ulash mumkin.',
            'ru' => 'Термопринтер чеков с интерфейсами USB и Ethernet: к нему можно подключить несколько касс по сети.',
            'en' => 'Thermal receipt printer with USB and Ethernet, so several tills can share it over the network.',
        ],
        'desc' => $desc(
            [
                'uz' => '<strong>Gainscha GA-E200I</strong> — USB va Ethernet (LAN) interfeysli termo chek printeri. Tarmoq porti printerni bir nechta kassa yoki oshxona bilan birga ishlatishga imkon beradi.',
                'ru' => '<strong>Gainscha GA-E200I</strong> — термопринтер чеков с интерфейсами USB и Ethernet (LAN). Сетевой порт позволяет использовать принтер сразу с несколькими кассами или на кухне.',
                'en' => 'The <strong>Gainscha GA-E200I</strong> is a thermal receipt printer with USB and Ethernet (LAN). The network port lets several tills or the kitchen share one printer.',
            ],
            [
                'uz' => $li('<strong>Chakana savdo:</strong> kassa cheklari.', '<strong>HoReCa:</strong> oshxona va bar uchun buyurtma chiptalari.', '<strong>Dorixona:</strong> cheklar va talonlar.', 'USB va Ethernet.'),
                'ru' => $li('<strong>Розница:</strong> кассовые чеки.', '<strong>HoReCa:</strong> чеки заказов для кухни и бара.', '<strong>Аптека:</strong> чеки и талоны.', 'USB и Ethernet.'),
                'en' => $li('<strong>Retail:</strong> till receipts.', '<strong>HoReCa:</strong> kitchen and bar order tickets.', '<strong>Pharmacy:</strong> receipts and tickets.', 'USB and Ethernet.'),
            ],
            $svc['printer'],
        ),
        'mt' => [
            'uz' => 'Gainscha GA-E200I chek printeri USB + LAN — Poson',
            'ru' => 'Чековый принтер Gainscha GA-E200I USB + LAN — Poson',
            'en' => 'Gainscha GA-E200I USB + LAN receipt printer — Poson',
        ],
        'md' => [
            'uz' => 'Gainscha GA-E200I termo chek printeri USB va Ethernet bilan: kassa va oshxona uchun. Tarmoqqa ulash, kassa dasturi va 1C sozlamasi, kafolat, servis.',
            'ru' => 'Чековый термопринтер Gainscha GA-E200I с USB и Ethernet для кассы и кухни. Подключение к сети, настройка с кассой и 1С, гарантия, сервис.',
            'en' => 'Gainscha GA-E200I thermal receipt printer with USB and Ethernet for tills and kitchens. Network setup, POS and 1C configuration, warranty, service.',
        ],
        'specs' => ['interface' => ['value' => ['USB', 'Ethernet'], 'key' => true]],
        'missing' => true,
    ]),
    $make([
        'sku' => '23', 'model' => 'GA-E250I (USB + LAN)', 'brand' => 'Gainscha', 'category' => 'receipt-printers', 'price' => 847000.0,
        'name' => [
            'uz' => 'Gainscha GA-E250I chek printeri, 80 mm, USB + LAN',
            'ru' => 'Чековый принтер Gainscha GA-E250I, 80 мм, USB + LAN',
            'en' => 'Gainscha GA-E250I receipt printer, 80 mm, USB + LAN',
        ],
        'short' => [
            'uz' => '80 mm termo chek printeri USB va Ethernet bilan: tez-tez chop etiladigan kassa va oshxona uchun.',
            'ru' => 'Термопринтер чеков 80 мм с USB и Ethernet для кассы и кухни с высокой нагрузкой.',
            'en' => '80 mm thermal receipt printer with USB and Ethernet for busy checkouts and kitchens.',
        ],
        'desc' => $desc(
            [
                'uz' => '<strong>Gainscha GA-E250I</strong> — 80 mm kenglikdagi termo chek printeri, USB va Ethernet (LAN) interfeysli. Keng lenta kassa cheklari va oshxona chiptalari uchun qulay.',
                'ru' => '<strong>Gainscha GA-E250I</strong> — термопринтер чеков шириной 80 мм с интерфейсами USB и Ethernet (LAN). Широкая лента удобна для кассовых чеков и кухонных бегунков.',
                'en' => 'The <strong>Gainscha GA-E250I</strong> is an 80 mm thermal receipt printer with USB and Ethernet (LAN). The wide paper suits till receipts and kitchen tickets.',
            ],
            [
                'uz' => $li('<strong>Supermarket va doʻkonlar:</strong> kassa cheklari.', '<strong>HoReCa:</strong> oshxona buyurtmalari tarmoq orqali.', '<strong>Dorixona:</strong> cheklar va talonlar.', '80 mm lenta, USB va Ethernet.'),
                'ru' => $li('<strong>Супермаркеты и магазины:</strong> кассовые чеки.', '<strong>HoReCa:</strong> заказы на кухню по сети.', '<strong>Аптека:</strong> чеки и талоны.', 'Лента 80 мм, USB и Ethernet.'),
                'en' => $li('<strong>Supermarkets and shops:</strong> till receipts.', '<strong>HoReCa:</strong> kitchen orders over the network.', '<strong>Pharmacy:</strong> receipts and tickets.', '80 mm paper, USB and Ethernet.'),
            ],
            $svc['printer'],
        ),
        'mt' => [
            'uz' => 'Gainscha GA-E250I chek printeri 80 mm — Poson',
            'ru' => 'Чековый принтер Gainscha GA-E250I 80 мм — Poson',
            'en' => 'Gainscha GA-E250I 80 mm receipt printer — Poson',
        ],
        'md' => [
            'uz' => 'Gainscha GA-E250I 80 mm chek printeri: USB va Ethernet, kassa va oshxona uchun. Tarmoqqa ulash, kassa dasturi va 1C sozlamasi, kafolat, servis.',
            'ru' => 'Чековый принтер Gainscha GA-E250I 80 мм: USB и Ethernet, для кассы и кухни. Подключение к сети, настройка с кассой и 1С, гарантия, сервис.',
            'en' => 'Gainscha GA-E250I 80 mm receipt printer with USB and Ethernet for tills and kitchens. Network setup, POS and 1C configuration, warranty, service.',
        ],
        'specs' => [
            'paper_width' => ['value' => '80', 'key' => true],
            'interface' => ['value' => ['USB', 'Ethernet'], 'key' => true],
        ],
        'missing' => false,
    ]),
    $make([
        'sku' => '149', 'model' => 'GP-C180I (USB)', 'brand' => 'Gainscha', 'category' => 'receipt-printers', 'price' => 420000.0,
        'name' => [
            'uz' => 'Gainscha GP-C180I chek printeri, USB',
            'ru' => 'Чековый принтер Gainscha GP-C180I, USB',
            'en' => 'Gainscha GP-C180I receipt printer, USB',
        ],
        'short' => [
            'uz' => 'USB orqali ulanadigan Gainscha termo chek printeri kassa va xizmat nuqtalari uchun; oʻrnatish va sozlash bilan.',
            'ru' => 'Термопринтер чеков Gainscha с USB для кассы и сервисных точек, с установкой и настройкой.',
            'en' => 'Gainscha thermal receipt printer with USB for checkouts and service counters, delivered with installation and setup.',
        ],
        'desc' => $desc(
            [
                'uz' => '<strong>Gainscha GP-C180I</strong> — USB interfeysli termo chek printeri. Kassa kompyuteriga ulanib, cheklar va talonlarni chop etadi.',
                'ru' => '<strong>Gainscha GP-C180I</strong> — термопринтер чеков с интерфейсом USB. Подключается к кассовому компьютеру и печатает чеки и талоны.',
                'en' => 'The <strong>Gainscha GP-C180I</strong> is a thermal receipt printer with a USB interface. It connects to a checkout PC and prints receipts and tickets.',
            ],
            [
                'uz' => $li('<strong>Chakana savdo:</strong> kassa cheklari.', '<strong>Kafe:</strong> hisob va buyurtma cheklari.', '<strong>Xizmat nuqtalari:</strong> kvitansiya va talonlar.', 'USB interfeys.'),
                'ru' => $li('<strong>Розница:</strong> кассовые чеки.', '<strong>Кафе:</strong> счета и чеки заказов.', '<strong>Сервисные точки:</strong> квитанции и талоны.', 'Интерфейс USB.'),
                'en' => $li('<strong>Retail:</strong> till receipts.', '<strong>Cafés:</strong> bills and order receipts.', '<strong>Service counters:</strong> slips and tickets.', 'USB interface.'),
            ],
            $svc['printer'],
        ),
        'mt' => [
            'uz' => 'Gainscha GP-C180I chek printeri USB — Poson',
            'ru' => 'Чековый принтер Gainscha GP-C180I USB — Poson',
            'en' => 'Gainscha GP-C180I USB receipt printer — Poson',
        ],
        'md' => [
            'uz' => 'Gainscha GP-C180I termo chek printeri, USB: kassa va xizmat nuqtalari uchun. Oʻrnatish, drayver va chek shablonini sozlash, kafolat va servis.',
            'ru' => 'Чековый термопринтер Gainscha GP-C180I с USB для кассы и сервисных точек. Установка, настройка драйвера и шаблона чека, гарантия, сервис.',
            'en' => 'Gainscha GP-C180I thermal receipt printer with USB for checkouts and service counters. Installation, driver and layout setup, warranty, service.',
        ],
        'specs' => ['interface' => ['value' => ['USB'], 'key' => true]],
        'missing' => true,
    ]),
    $make([
        'sku' => '24', 'model' => 'SP-POS8811A (USB + LAN)', 'brand' => 'SPRT', 'category' => 'receipt-printers', 'price' => 544500.0,
        'name' => [
            'uz' => 'SPRT SP-POS8811A chek printeri, 80 mm, USB + LAN',
            'ru' => 'Чековый принтер SPRT SP-POS8811A, 80 мм, USB + LAN',
            'en' => 'SPRT SP-POS8811A receipt printer, 80 mm, USB + LAN',
        ],
        'short' => [
            'uz' => '80 mm termo chek printeri avtokesgich, USB va Ethernet bilan: doʻkon kassasi va oshxona uchun.',
            'ru' => 'Термопринтер чеков 80 мм с автоотрезчиком, USB и Ethernet для кассы магазина и кухни.',
            'en' => '80 mm thermal receipt printer with auto-cutter, USB and Ethernet for shop checkouts and kitchens.',
        ],
        'desc' => $desc(
            [
                'uz' => '<strong>SPRT SP-POS8811A</strong> — 80 mm termo chek printeri. Avtokesgich chekni avtomatik kesadi, USB va Ethernet portlari esa kassaga ham, tarmoqqa ham ulanish imkonini beradi.',
                'ru' => '<strong>SPRT SP-POS8811A</strong> — термопринтер чеков 80 мм. Автоотрезчик отрезает чек автоматически, а порты USB и Ethernet позволяют подключить принтер к кассе или к сети.',
                'en' => 'The <strong>SPRT SP-POS8811A</strong> is an 80 mm thermal receipt printer. The auto-cutter trims each receipt, and the USB and Ethernet ports connect it to a till or to the network.',
            ],
            [
                'uz' => $li('<strong>Chakana savdo:</strong> kassa cheklari.', '<strong>HoReCa:</strong> oshxona buyurtmalari tarmoq orqali.', '<strong>Dorixona:</strong> cheklar va talonlar.', '80 mm lenta, avtokesgich, USB va Ethernet.'),
                'ru' => $li('<strong>Розница:</strong> кассовые чеки.', '<strong>HoReCa:</strong> заказы на кухню по сети.', '<strong>Аптека:</strong> чеки и талоны.', 'Лента 80 мм, автоотрезчик, USB и Ethernet.'),
                'en' => $li('<strong>Retail:</strong> till receipts.', '<strong>HoReCa:</strong> kitchen orders over the network.', '<strong>Pharmacy:</strong> receipts and tickets.', '80 mm paper, auto-cutter, USB and Ethernet.'),
            ],
            $svc['printer'],
        ),
        'mt' => [
            'uz' => 'SPRT SP-POS8811A chek printeri 80 mm — Poson',
            'ru' => 'Чековый принтер SPRT SP-POS8811A 80 мм — Poson',
            'en' => 'SPRT SP-POS8811A 80 mm receipt printer — Poson',
        ],
        'md' => [
            'uz' => 'SPRT SP-POS8811A 80 mm chek printeri: avtokesgich, USB va Ethernet. Kassa va oshxona uchun. Tarmoqqa ulash, sozlash, kafolat va servis.',
            'ru' => 'Чековый принтер SPRT SP-POS8811A 80 мм: автоотрезчик, USB и Ethernet. Для кассы и кухни. Подключение к сети, настройка, гарантия и сервис.',
            'en' => 'SPRT SP-POS8811A 80 mm receipt printer with auto-cutter, USB and Ethernet for tills and kitchens. Network setup, warranty and service.',
        ],
        'specs' => [
            'paper_width' => ['value' => '80', 'key' => true],
            'auto_cutter' => ['value' => true, 'key' => true],
            'interface' => ['value' => ['USB', 'Ethernet'], 'key' => true],
        ],
        'missing' => false,
    ]),
    $make([
        'sku' => '25', 'model' => 'SP-POS892E', 'brand' => 'Afanda', 'category' => 'receipt-printers', 'price' => 968000.0,
        'name' => [
            'uz' => 'Afanda SP-POS892E chek printeri, 80 mm',
            'ru' => 'Чековый принтер Afanda SP-POS892E, 80 мм',
            'en' => 'Afanda SP-POS892E receipt printer, 80 mm',
        ],
        'short' => [
            'uz' => '80 mm termo chek printeri kassa va oshxona uchun: Poson uni oʻrnatadi, sozlaydi va kafolatli servis koʻrsatadi.',
            'ru' => 'Термопринтер чеков 80 мм для кассы и кухни: Poson установит, настроит и обеспечит гарантийный сервис.',
            'en' => '80 mm thermal receipt printer for checkouts and kitchens, installed, configured and serviced under warranty by Poson.',
        ],
        'desc' => $desc(
            [
                'uz' => '<strong>Afanda SP-POS892E</strong> — kassa va oshxona uchun 80 mm termo chek printeri. Keng lenta cheklar va buyurtma chiptalarini oʻqishga qulay qiladi.',
                'ru' => '<strong>Afanda SP-POS892E</strong> — термопринтер чеков 80 мм для кассы и кухни. Широкая лента делает чеки и бегунки заказов удобными для чтения.',
                'en' => 'The <strong>Afanda SP-POS892E</strong> is an 80 mm thermal receipt printer for checkouts and kitchens. The wide paper keeps receipts and order tickets easy to read.',
            ],
            [
                'uz' => $li('<strong>Chakana savdo:</strong> kassa cheklari.', '<strong>HoReCa:</strong> oshxona va bar uchun buyurtma chiptalari.', '<strong>Dorixona:</strong> cheklar va talonlar.', '80 mm termo lenta.'),
                'ru' => $li('<strong>Розница:</strong> кассовые чеки.', '<strong>HoReCa:</strong> бегунки заказов для кухни и бара.', '<strong>Аптека:</strong> чеки и талоны.', 'Термолента 80 мм.'),
                'en' => $li('<strong>Retail:</strong> till receipts.', '<strong>HoReCa:</strong> kitchen and bar order tickets.', '<strong>Pharmacy:</strong> receipts and tickets.', '80 mm thermal paper.'),
            ],
            $svc['printer'],
        ),
        'mt' => [
            'uz' => 'Afanda SP-POS892E chek printeri 80 mm — Poson',
            'ru' => 'Чековый принтер Afanda SP-POS892E 80 мм — Poson',
            'en' => 'Afanda SP-POS892E 80 mm receipt printer — Poson',
        ],
        'md' => [
            'uz' => 'Afanda SP-POS892E 80 mm termo chek printeri kassa va oshxona uchun. Poson oʻrnatadi, drayver va chek shablonini sozlaydi, kafolat va servis beradi.',
            'ru' => 'Термопринтер чеков Afanda SP-POS892E 80 мм для кассы и кухни. Poson установит, настроит драйвер и шаблон чека, обеспечит гарантию и сервис.',
            'en' => 'Afanda SP-POS892E 80 mm thermal receipt printer for tills and kitchens. Poson installs it, sets up the driver and layout, and provides warranty.',
        ],
        'specs' => ['paper_width' => ['value' => '80', 'key' => true]],
        'missing' => true,
    ]),
    $make([
        'sku' => '26', 'model' => 'TP80K-L (USB + LAN)', 'brand' => 'HPRT', 'category' => 'receipt-printers', 'price' => 907500.0,
        'name' => [
            'uz' => 'HPRT TP80K-L chek printeri, 80 mm, USB + LAN',
            'ru' => 'Чековый принтер HPRT TP80K-L, 80 мм, USB + LAN',
            'en' => 'HPRT TP80K-L receipt printer, 80 mm, USB + LAN',
        ],
        'short' => [
            'uz' => '80 mm termo chek printeri avtokesgich, USB va Ethernet bilan; xatodan keyin chekni qayta chop etish funksiyasi bor.',
            'ru' => 'Термопринтер чеков 80 мм с автоотрезчиком, USB и Ethernet; умеет допечатать чек после сбоя.',
            'en' => '80 mm thermal receipt printer with auto-cutter, USB and Ethernet that can reprint a receipt after a print error.',
        ],
        'desc' => $desc(
            [
                'uz' => '<strong>HPRT TP80K-L</strong> — 80 mm termo chek printeri, avtokesgich, USB va Ethernet bilan. Ishlab chiqaruvchi maʼlumotiga koʻra, printer xato yoki qogʻoz tiqilganidan keyin chekni qayta chop eta oladi.',
                'ru' => '<strong>HPRT TP80K-L</strong> — термопринтер чеков 80 мм с автоотрезчиком, USB и Ethernet. По данным производителя, после ошибки или замятия бумаги принтер может перепечатать чек.',
                'en' => 'The <strong>HPRT TP80K-L</strong> is an 80 mm thermal receipt printer with an auto-cutter, USB and Ethernet. According to the manufacturer, it can reprint a receipt after an error or paper jam.',
            ],
            [
                'uz' => $li('<strong>Supermarket va doʻkonlar:</strong> kassa cheklari.', '<strong>HoReCa:</strong> oshxona buyurtmalari tarmoq orqali.', '<strong>Dorixona:</strong> cheklar va talonlar.', '80 mm lenta, avtokesgich, USB va Ethernet.'),
                'ru' => $li('<strong>Супермаркеты и магазины:</strong> кассовые чеки.', '<strong>HoReCa:</strong> заказы на кухню по сети.', '<strong>Аптека:</strong> чеки и талоны.', 'Лента 80 мм, автоотрезчик, USB и Ethernet.'),
                'en' => $li('<strong>Supermarkets and shops:</strong> till receipts.', '<strong>HoReCa:</strong> kitchen orders over the network.', '<strong>Pharmacy:</strong> receipts and tickets.', '80 mm paper, auto-cutter, USB and Ethernet.'),
            ],
            $svc['printer'],
        ),
        'mt' => [
            'uz' => 'HPRT TP80K-L chek printeri 80 mm — Poson',
            'ru' => 'Чековый принтер HPRT TP80K-L 80 мм — Poson',
            'en' => 'HPRT TP80K-L 80 mm receipt printer — Poson',
        ],
        'md' => [
            'uz' => 'HPRT TP80K-L 80 mm chek printeri: avtokesgich, USB va Ethernet, kassa va oshxona uchun. Tarmoqqa ulash, sozlash, kafolat va servis.',
            'ru' => 'Чековый принтер HPRT TP80K-L 80 мм: автоотрезчик, USB и Ethernet, для кассы и кухни. Подключение к сети, настройка, гарантия и сервис.',
            'en' => 'HPRT TP80K-L 80 mm receipt printer with auto-cutter, USB and Ethernet for tills and kitchens. Network setup, warranty and service.',
        ],
        'specs' => [
            'paper_width' => ['value' => '80', 'key' => true],
            'auto_cutter' => ['value' => true, 'key' => true],
            'print_speed' => 230,
            'interface' => ['value' => ['USB', 'Ethernet'], 'key' => true],
        ],
        'missing' => false,
    ]),
    $make([
        'sku' => '73', 'model' => 'XP-Q807K (USB + LAN)', 'brand' => 'Xprinter', 'category' => 'receipt-printers', 'price' => 484000.0,
        'name' => [
            'uz' => 'Xprinter XP-Q807K chek printeri, 80 mm, USB + LAN',
            'ru' => 'Чековый принтер Xprinter XP-Q807K, 80 мм, USB + LAN',
            'en' => 'Xprinter XP-Q807K receipt printer, 80 mm, USB + LAN',
        ],
        'short' => [
            'uz' => '80 mm termo chek printeri: 230 mm/s gacha tezlik, avtokesgich, USB va Ethernet, kassa va oshxona uchun.',
            'ru' => 'Термопринтер чеков 80 мм: скорость до 230 мм/с, автоотрезчик, USB и Ethernet для кассы и кухни.',
            'en' => '80 mm thermal receipt printer with up to 230 mm/s, auto-cutter, USB and Ethernet for checkouts and kitchens.',
        ],
        'desc' => $desc(
            [
                'uz' => '<strong>Xprinter XP-Q807K</strong> — 80 mm kenglikdagi tezkor termo chek printeri. Avtokesgich chekni kesadi, Ethernet porti esa printerni tarmoq orqali bir nechta kassa yoki oshxona bilan ishlatish imkonini beradi.',
                'ru' => '<strong>Xprinter XP-Q807K</strong> — быстрый термопринтер чеков шириной 80 мм. Автоотрезчик отрезает чек, а порт Ethernet позволяет использовать принтер по сети с несколькими кассами или на кухне.',
                'en' => 'The <strong>Xprinter XP-Q807K</strong> is a fast 80 mm thermal receipt printer. The auto-cutter trims each receipt, and the Ethernet port lets several tills or the kitchen share the printer.',
            ],
            [
                'uz' => $li('<strong>Chakana savdo:</strong> kassa cheklari.', '<strong>HoReCa:</strong> oshxona va bar uchun buyurtma chiptalari.', '<strong>Dorixona:</strong> cheklar va talonlar.', '80 mm lenta, 230 mm/s gacha, avtokesgich, USB va Ethernet.'),
                'ru' => $li('<strong>Розница:</strong> кассовые чеки.', '<strong>HoReCa:</strong> бегунки заказов для кухни и бара.', '<strong>Аптека:</strong> чеки и талоны.', 'Лента 80 мм, до 230 мм/с, автоотрезчик, USB и Ethernet.'),
                'en' => $li('<strong>Retail:</strong> till receipts.', '<strong>HoReCa:</strong> kitchen and bar order tickets.', '<strong>Pharmacy:</strong> receipts and tickets.', '80 mm paper, up to 230 mm/s, auto-cutter, USB and Ethernet.'),
            ],
            $svc['printer'],
        ),
        'mt' => [
            'uz' => 'Xprinter XP-Q807K chek printeri 80 mm — Poson',
            'ru' => 'Чековый принтер Xprinter XP-Q807K 80 мм — Poson',
            'en' => 'Xprinter XP-Q807K 80 mm receipt printer — Poson',
        ],
        'md' => [
            'uz' => 'Xprinter XP-Q807K 80 mm chek printeri: 230 mm/s, avtokesgich, USB va Ethernet. Kassa va oshxona uchun. Ulash, sozlash, kafolat va servis.',
            'ru' => 'Чековый принтер Xprinter XP-Q807K 80 мм: 230 мм/с, автоотрезчик, USB и Ethernet. Для кассы и кухни. Подключение, настройка, гарантия, сервис.',
            'en' => 'Xprinter XP-Q807K 80 mm receipt printer, 230 mm/s, auto-cutter, USB and Ethernet for tills and kitchens. Setup, warranty and service.',
        ],
        'specs' => [
            'paper_width' => ['value' => '80', 'key' => true],
            'auto_cutter' => ['value' => true, 'key' => true],
            'print_speed' => 230,
            'interface' => ['value' => ['USB', 'Ethernet'], 'key' => true],
        ],
        'missing' => false,
    ]),
    $make([
        'sku' => '27', 'model' => 'XP-T80Q (USB)', 'brand' => 'Xprinter', 'category' => 'receipt-printers', 'price' => 484000.0,
        'name' => [
            'uz' => 'Xprinter XP-T80Q chek printeri, 80 mm, USB',
            'ru' => 'Чековый принтер Xprinter XP-T80Q, 80 мм, USB',
            'en' => 'Xprinter XP-T80Q receipt printer, 80 mm, USB',
        ],
        'short' => [
            'uz' => '80 mm termo chek printeri avtokesgich va USB bilan: doʻkon va dorixona kassasi uchun ishonchli yechim.',
            'ru' => 'Термопринтер чеков 80 мм с автоотрезчиком и USB для кассы магазина и аптеки.',
            'en' => '80 mm thermal receipt printer with auto-cutter and USB for shop and pharmacy checkouts.',
        ],
        'desc' => $desc(
            [
                'uz' => '<strong>Xprinter XP-T80Q</strong> — 80 mm termo chek printeri, avtokesgich va USB interfeysli. Kassa kompyuteriga bevosita ulanadi.',
                'ru' => '<strong>Xprinter XP-T80Q</strong> — термопринтер чеков 80 мм с автоотрезчиком и интерфейсом USB. Подключается напрямую к кассовому компьютеру.',
                'en' => 'The <strong>Xprinter XP-T80Q</strong> is an 80 mm thermal receipt printer with an auto-cutter and a USB interface. It connects directly to a checkout PC.',
            ],
            [
                'uz' => $li('<strong>Chakana savdo:</strong> kassa cheklari.', '<strong>Dorixona:</strong> cheklar va talonlar.', '<strong>Kafe:</strong> hisob va buyurtma cheklari.', '80 mm lenta, avtokesgich, USB.'),
                'ru' => $li('<strong>Розница:</strong> кассовые чеки.', '<strong>Аптека:</strong> чеки и талоны.', '<strong>Кафе:</strong> счета и чеки заказов.', 'Лента 80 мм, автоотрезчик, USB.'),
                'en' => $li('<strong>Retail:</strong> till receipts.', '<strong>Pharmacy:</strong> receipts and tickets.', '<strong>Cafés:</strong> bills and order receipts.', '80 mm paper, auto-cutter, USB.'),
            ],
            $svc['printer'],
        ),
        'mt' => [
            'uz' => 'Xprinter XP-T80Q chek printeri 80 mm USB — Poson',
            'ru' => 'Чековый принтер Xprinter XP-T80Q 80 мм USB — Poson',
            'en' => 'Xprinter XP-T80Q 80 mm USB receipt printer — Poson',
        ],
        'md' => [
            'uz' => 'Xprinter XP-T80Q 80 mm chek printeri: avtokesgich va USB, doʻkon va dorixona kassasi uchun. Ulash, sozlash, kafolat va servis.',
            'ru' => 'Чековый принтер Xprinter XP-T80Q 80 мм: автоотрезчик и USB, для кассы магазина и аптеки. Подключение, настройка, гарантия и сервис.',
            'en' => 'Xprinter XP-T80Q 80 mm receipt printer with auto-cutter and USB for shop and pharmacy tills. Setup, warranty and service.',
        ],
        'specs' => [
            'paper_width' => ['value' => '80', 'key' => true],
            'auto_cutter' => ['value' => true, 'key' => true],
            'interface' => ['value' => ['USB'], 'key' => true],
        ],
        'missing' => false,
    ]),
    $make([
        'sku' => '28', 'model' => 'XP-T80Q (USB + LAN)', 'brand' => 'Xprinter', 'category' => 'receipt-printers', 'price' => 544500.0,
        'name' => [
            'uz' => 'Xprinter XP-T80Q chek printeri, 80 mm, USB + LAN',
            'ru' => 'Чековый принтер Xprinter XP-T80Q, 80 мм, USB + LAN',
            'en' => 'Xprinter XP-T80Q receipt printer, 80 mm, USB + LAN',
        ],
        'short' => [
            'uz' => '80 mm termo chek printeri avtokesgich, USB va Ethernet bilan: bir nechta kassa va oshxona tarmoq orqali ishlatadi.',
            'ru' => 'Термопринтер чеков 80 мм с автоотрезчиком, USB и Ethernet: работает с несколькими кассами и кухней по сети.',
            'en' => '80 mm thermal receipt printer with auto-cutter, USB and Ethernet, shared by several tills and the kitchen over the network.',
        ],
        'desc' => $desc(
            [
                'uz' => '<strong>Xprinter XP-T80Q</strong> USB va LAN varianti — 80 mm termo chek printeri, avtokesgich bilan. Ethernet porti printerni tarmoqqa ulab, bir nechta ish joyidan chop etishga imkon beradi.',
                'ru' => 'Вариант <strong>Xprinter XP-T80Q</strong> USB и LAN — термопринтер чеков 80 мм с автоотрезчиком. Порт Ethernet подключает принтер к сети, и печатать на нём можно с нескольких рабочих мест.',
                'en' => 'The <strong>Xprinter XP-T80Q</strong> USB and LAN version is an 80 mm thermal receipt printer with an auto-cutter. The Ethernet port puts it on the network so several workstations can print to it.',
            ],
            [
                'uz' => $li('<strong>Chakana savdo:</strong> bir nechta kassa uchun umumiy printer.', '<strong>HoReCa:</strong> oshxona buyurtmalari tarmoq orqali.', '<strong>Dorixona:</strong> cheklar va talonlar.', '80 mm lenta, avtokesgich, USB va Ethernet.'),
                'ru' => $li('<strong>Розница:</strong> общий принтер для нескольких касс.', '<strong>HoReCa:</strong> заказы на кухню по сети.', '<strong>Аптека:</strong> чеки и талоны.', 'Лента 80 мм, автоотрезчик, USB и Ethernet.'),
                'en' => $li('<strong>Retail:</strong> one printer shared by several tills.', '<strong>HoReCa:</strong> kitchen orders over the network.', '<strong>Pharmacy:</strong> receipts and tickets.', '80 mm paper, auto-cutter, USB and Ethernet.'),
            ],
            $svc['printer'],
        ),
        'mt' => [
            'uz' => 'Xprinter XP-T80Q chek printeri USB + LAN — Poson',
            'ru' => 'Чековый принтер Xprinter XP-T80Q USB + LAN — Poson',
            'en' => 'Xprinter XP-T80Q USB + LAN receipt printer — Poson',
        ],
        'md' => [
            'uz' => 'Xprinter XP-T80Q 80 mm chek printeri: avtokesgich, USB va Ethernet. Kassa va oshxona uchun. Tarmoqqa ulash, sozlash, kafolat va servis.',
            'ru' => 'Чековый принтер Xprinter XP-T80Q 80 мм: автоотрезчик, USB и Ethernet. Для кассы и кухни. Подключение к сети, настройка, гарантия, сервис.',
            'en' => 'Xprinter XP-T80Q 80 mm receipt printer with auto-cutter, USB and Ethernet for tills and kitchens. Network setup, warranty and service.',
        ],
        'specs' => [
            'paper_width' => ['value' => '80', 'key' => true],
            'auto_cutter' => ['value' => true, 'key' => true],
            'interface' => ['value' => ['USB', 'Ethernet'], 'key' => true],
        ],
        'missing' => false,
    ]),

    // ─── Cash drawers ───────────────────────────────────────────────────────
    $make([
        'sku' => '68', 'model' => 'CD2023', 'brand' => 'Citaq', 'category' => 'cash-drawers', 'price' => 936000.0,
        'name' => [
            'uz' => 'Citaq CD2023 pul yashigi',
            'ru' => 'Денежный ящик Citaq CD2023',
            'en' => 'Citaq CD2023 cash drawer',
        ],
        'short' => [
            'uz' => 'Kassa joyi uchun pul yashigi: chek printeri buyrugʻi bilan avtomatik ochiladi, Poson ulaydi va sozlaydi.',
            'ru' => 'Денежный ящик для кассового места: открывается по команде чекового принтера, Poson подключит и настроит.',
            'en' => 'Cash drawer for the checkout station that opens on a command from the receipt printer, connected and configured by Poson.',
        ],
        'desc' => $desc(
            [
                'uz' => '<strong>Citaq CD2023</strong> — kassa joyida naqd pulni tartibli saqlash uchun pul yashigi. Odatda chek printeriga ulanadi va chek chop etilganda ochiladi.',
                'ru' => '<strong>Citaq CD2023</strong> — денежный ящик для аккуратного хранения наличных на кассовом месте. Обычно подключается к чековому принтеру и открывается при печати чека.',
                'en' => 'The <strong>Citaq CD2023</strong> is a cash drawer for keeping cash organized at the checkout station. It is usually wired to a receipt printer and opens when a receipt prints.',
            ],
            [
                'uz' => $li('<strong>Chakana savdo:</strong> doʻkon kassasida naqd pul.', '<strong>Dorixona:</strong> ixcham kassa joyi.', '<strong>HoReCa:</strong> bar va kafe kassasi.', 'Chek printeri orqali avtomatik ochilish.'),
                'ru' => $li('<strong>Розница:</strong> наличные на кассе магазина.', '<strong>Аптека:</strong> компактное кассовое место.', '<strong>HoReCa:</strong> касса бара и кафе.', 'Автоматическое открытие через чековый принтер.'),
                'en' => $li('<strong>Retail:</strong> cash handling at the shop till.', '<strong>Pharmacy:</strong> a compact checkout station.', '<strong>HoReCa:</strong> bar and café tills.', 'Automatic opening through the receipt printer.'),
            ],
            $svc['drawer'],
        ),
        'mt' => [
            'uz' => 'Citaq CD2023 pul yashigi — Poson',
            'ru' => 'Денежный ящик Citaq CD2023 — Poson',
            'en' => 'Citaq CD2023 cash drawer — Poson',
        ],
        'md' => [
            'uz' => 'Citaq CD2023 pul yashigi kassa joyi uchun: chek printeri buyrugʻi bilan ochiladi. Poson ulaydi, avtomatik ochilishni sozlaydi, kafolat va servis beradi.',
            'ru' => 'Денежный ящик Citaq CD2023 для кассы: открывается по команде чекового принтера. Poson подключит, настроит автоматическое открытие, гарантия и сервис.',
            'en' => 'Citaq CD2023 cash drawer opened by the receipt printer. Poson connects it, sets up automatic opening and provides warranty and service.',
        ],
        'specs' => [],
        'missing' => true,
    ]),
    $make([
        'sku' => '256', 'model' => 'CD-410 (24 V)', 'brand' => 'Poson', 'category' => 'cash-drawers', 'price' => 860000.0,
        'name' => [
            'uz' => 'Poson CD-410 pul yashigi, 24 V',
            'ru' => 'Денежный ящик Poson CD-410, 24 В',
            'en' => 'Poson CD-410 cash drawer, 24 V',
        ],
        'short' => [
            'uz' => '24 V pul yashigi kassa joyi uchun: chek printeri buyrugʻi bilan ochiladi, Poson ulaydi va sozlaydi.',
            'ru' => 'Денежный ящик 24 В для кассового места: открывается по команде чекового принтера, Poson подключит и настроит.',
            'en' => '24 V cash drawer for the checkout station that opens on a receipt printer command, connected and configured by Poson.',
        ],
        'desc' => $desc(
            [
                'uz' => '<strong>Poson CD-410</strong> — 24 V versiyadagi pul yashigi. Kassa joyida naqd pulni tartibli saqlaydi va chek printeri orqali ochiladi.',
                'ru' => '<strong>Poson CD-410</strong> — денежный ящик в версии 24 В. Помогает аккуратно хранить наличные на кассе и открывается через чековый принтер.',
                'en' => 'The <strong>Poson CD-410</strong> is a 24 V cash drawer. It keeps cash organized at the checkout and opens through the receipt printer.',
            ],
            [
                'uz' => $li('<strong>Chakana savdo:</strong> doʻkon kassasi.', '<strong>Dorixona:</strong> ixcham kassa joyi.', '<strong>HoReCa:</strong> bar va kafe kassasi.', '24 V versiya: printer ham 24 V chiqishli boʻlishi kerak.'),
                'ru' => $li('<strong>Розница:</strong> касса магазина.', '<strong>Аптека:</strong> компактное кассовое место.', '<strong>HoReCa:</strong> касса бара и кафе.', 'Версия 24 В: принтер должен иметь выход 24 В.'),
                'en' => $li('<strong>Retail:</strong> the shop till.', '<strong>Pharmacy:</strong> a compact checkout station.', '<strong>HoReCa:</strong> bar and café tills.', '24 V version: your printer needs a matching 24 V drawer output.'),
            ],
            $svc['drawer'],
        ),
        'mt' => [
            'uz' => 'Poson CD-410 pul yashigi 24 V — Poson',
            'ru' => 'Денежный ящик Poson CD-410 24 В — Poson',
            'en' => 'Poson CD-410 24 V cash drawer — Poson',
        ],
        'md' => [
            'uz' => 'Poson CD-410 pul yashigi, 24 V: kassa joyi uchun, chek printeri orqali ochiladi. Printerga moslab ulash, avtomatik ochilishni sozlash, kafolat, servis.',
            'ru' => 'Денежный ящик Poson CD-410, 24 В, для кассы: открывается через чековый принтер. Подбор под принтер, настройка автооткрытия, гарантия, сервис.',
            'en' => 'Poson CD-410 24 V cash drawer for checkouts, opened through the receipt printer. Printer matching, auto-open setup, warranty and service.',
        ],
        'specs' => [],
        'missing' => true,
    ]),
    $make([
        'sku' => '255', 'model' => 'FT-460 WB (24 V)', 'brand' => 'Poson', 'category' => 'cash-drawers', 'price' => 860000.0,
        'name' => [
            'uz' => 'Poson FT-460 WB pul yashigi, 24 V',
            'ru' => 'Денежный ящик Poson FT-460 WB, 24 В',
            'en' => 'Poson FT-460 WB cash drawer, 24 V',
        ],
        'short' => [
            'uz' => '24 V pul yashigi kassa joyi uchun: chek printeri buyrugʻi bilan ochiladi, Poson ulaydi va sozlaydi.',
            'ru' => 'Денежный ящик 24 В для кассового места: открывается по команде чекового принтера, Poson подключит и настроит.',
            'en' => '24 V cash drawer for the checkout station that opens on a receipt printer command, connected and configured by Poson.',
        ],
        'desc' => $desc(
            [
                'uz' => '<strong>Poson FT-460 WB</strong> — 24 V versiyadagi pul yashigi. Kassa joyida naqd pulni tartibli saqlaydi va chek printeri orqali ochiladi.',
                'ru' => '<strong>Poson FT-460 WB</strong> — денежный ящик в версии 24 В. Помогает аккуратно хранить наличные на кассе и открывается через чековый принтер.',
                'en' => 'The <strong>Poson FT-460 WB</strong> is a 24 V cash drawer. It keeps cash organized at the checkout and opens through the receipt printer.',
            ],
            [
                'uz' => $li('<strong>Chakana savdo:</strong> doʻkon kassasi.', '<strong>Dorixona:</strong> kassa joyi.', '<strong>HoReCa:</strong> bar va kafe kassasi.', '24 V versiya: printer ham 24 V chiqishli boʻlishi kerak.'),
                'ru' => $li('<strong>Розница:</strong> касса магазина.', '<strong>Аптека:</strong> кассовое место.', '<strong>HoReCa:</strong> касса бара и кафе.', 'Версия 24 В: принтер должен иметь выход 24 В.'),
                'en' => $li('<strong>Retail:</strong> the shop till.', '<strong>Pharmacy:</strong> the checkout station.', '<strong>HoReCa:</strong> bar and café tills.', '24 V version: your printer needs a matching 24 V drawer output.'),
            ],
            $svc['drawer'],
        ),
        'mt' => [
            'uz' => 'Poson FT-460 WB pul yashigi 24 V — Poson',
            'ru' => 'Денежный ящик Poson FT-460 WB 24 В — Poson',
            'en' => 'Poson FT-460 WB 24 V cash drawer — Poson',
        ],
        'md' => [
            'uz' => 'Poson FT-460 WB pul yashigi, 24 V: kassa uchun, chek printeri orqali ochiladi. Printerga moslab ulash, avtomatik ochilishni sozlash, kafolat, servis.',
            'ru' => 'Денежный ящик Poson FT-460 WB, 24 В, для кассы: открывается через чековый принтер. Подбор под принтер, настройка автооткрытия, гарантия, сервис.',
            'en' => 'Poson FT-460 WB 24 V cash drawer for checkouts, opened through the receipt printer. Printer matching, auto-open setup, warranty and service.',
        ],
        'specs' => [],
        'missing' => true,
    ]),
    $make([
        'sku' => '243', 'model' => 'M-410', 'brand' => 'Poson', 'category' => 'cash-drawers', 'price' => 806247.29,
        'name' => [
            'uz' => 'Poson M-410 pul yashigi',
            'ru' => 'Денежный ящик Poson M-410',
            'en' => 'Poson M-410 cash drawer',
        ],
        'short' => [
            'uz' => 'Kassa joyi uchun pul yashigi: chek printeri buyrugʻi bilan ochiladi, Poson ulaydi va sozlaydi.',
            'ru' => 'Денежный ящик для кассового места: открывается по команде чекового принтера, Poson подключит и настроит.',
            'en' => 'Cash drawer for the checkout station that opens on a receipt printer command, connected and configured by Poson.',
        ],
        'desc' => $desc(
            [
                'uz' => '<strong>Poson M-410</strong> — kassa joyida naqd pulni tartibli saqlash uchun pul yashigi. Chek printeriga ulanadi va chek chop etilganda ochiladi.',
                'ru' => '<strong>Poson M-410</strong> — денежный ящик для аккуратного хранения наличных на кассовом месте. Подключается к чековому принтеру и открывается при печати чека.',
                'en' => 'The <strong>Poson M-410</strong> is a cash drawer for keeping cash organized at the checkout station. It connects to a receipt printer and opens when a receipt prints.',
            ],
            [
                'uz' => $li('<strong>Chakana savdo:</strong> doʻkon kassasi.', '<strong>Dorixona:</strong> kassa joyi.', '<strong>HoReCa:</strong> bar va kafe kassasi.', 'Chek printeri orqali avtomatik ochilish.'),
                'ru' => $li('<strong>Розница:</strong> касса магазина.', '<strong>Аптека:</strong> кассовое место.', '<strong>HoReCa:</strong> касса бара и кафе.', 'Автоматическое открытие через чековый принтер.'),
                'en' => $li('<strong>Retail:</strong> the shop till.', '<strong>Pharmacy:</strong> the checkout station.', '<strong>HoReCa:</strong> bar and café tills.', 'Automatic opening through the receipt printer.'),
            ],
            $svc['drawer'],
        ),
        'mt' => [
            'uz' => 'Poson M-410 pul yashigi — Poson',
            'ru' => 'Денежный ящик Poson M-410 — Poson',
            'en' => 'Poson M-410 cash drawer — Poson',
        ],
        'md' => [
            'uz' => 'Poson M-410 pul yashigi kassa joyi uchun: chek printeri buyrugʻi bilan ochiladi. Printerga ulash, avtomatik ochilishni sozlash, kafolat va servis.',
            'ru' => 'Денежный ящик Poson M-410 для кассы: открывается по команде чекового принтера. Подключение к принтеру, настройка автооткрытия, гарантия и сервис.',
            'en' => 'Poson M-410 cash drawer opened by the receipt printer. Printer connection, auto-open setup, warranty and service from Poson.',
        ],
        'specs' => [],
        'missing' => true,
    ]),

    // ─── POS terminals ──────────────────────────────────────────────────────
    $make([
        'sku' => '35', 'model' => 'E200 4/128', 'brand' => 'P2C', 'category' => 'pos-terminals', 'price' => 7502000.0,
        'name' => [
            'uz' => 'P2C E200 sensorli POS monoblok, 4/128 GB',
            'ru' => 'Сенсорный POS-моноблок P2C E200, 4/128 ГБ',
            'en' => 'P2C E200 touch POS all-in-one, 4/128 GB',
        ],
        'short' => [
            'uz' => 'Sensorli POS monoblok 4 GB operativ va 128 GB doimiy xotira bilan: kassa dasturi, printer va skaner uchun.',
            'ru' => 'Сенсорный POS-моноблок с 4 ГБ ОЗУ и 128 ГБ памяти для кассовой программы, принтера и сканера.',
            'en' => 'Touch POS all-in-one with 4 GB RAM and 128 GB storage for POS software, a printer and a scanner.',
        ],
        'desc' => $desc(
            [
                'uz' => '<strong>P2C E200</strong> — sensorli ekranli POS monoblok: kompyuter va ekran bitta korpusda. 4 GB operativ va 128 GB doimiy xotira kassa dasturi uchun yetarli zaxira beradi.',
                'ru' => '<strong>P2C E200</strong> — сенсорный POS-моноблок: компьютер и экран в одном корпусе. 4 ГБ оперативной и 128 ГБ встроенной памяти дают запас для кассовой программы.',
                'en' => 'The <strong>P2C E200</strong> is a touch POS all-in-one with the computer and screen in one housing. 4 GB RAM and 128 GB storage leave headroom for POS software.',
            ],
            [
                'uz' => $li('<strong>Chakana savdo:</strong> doʻkon va supermarket kassasi.', '<strong>HoReCa:</strong> zal va bar kassasi.', '<strong>Dorixona:</strong> retsept va sotuv kassasi.', '4 GB RAM, 128 GB xotira.'),
                'ru' => $li('<strong>Розница:</strong> касса магазина и супермаркета.', '<strong>HoReCa:</strong> касса зала и бара.', '<strong>Аптека:</strong> кассовое место для продаж.', '4 ГБ ОЗУ, 128 ГБ памяти.'),
                'en' => $li('<strong>Retail:</strong> shop and supermarket tills.', '<strong>HoReCa:</strong> dining-room and bar tills.', '<strong>Pharmacy:</strong> a checkout station.', '4 GB RAM, 128 GB storage.'),
            ],
            $svc['terminal'],
        ),
        'mt' => [
            'uz' => 'P2C E200 sensorli POS monoblok 4/128 — Poson',
            'ru' => 'POS-моноблок P2C E200 4/128 ГБ — Poson',
            'en' => 'P2C E200 touch POS all-in-one 4/128 GB — Poson',
        ],
        'md' => [
            'uz' => 'P2C E200 sensorli POS monoblok: 4 GB RAM, 128 GB xotira. Kassa dasturi, printer va skanerni oʻrnatish, 1C integratsiyasi, kafolat va servis.',
            'ru' => 'Сенсорный POS-моноблок P2C E200: 4 ГБ ОЗУ, 128 ГБ памяти. Установка кассовой программы, подключение периферии, интеграция с 1С, гарантия, сервис.',
            'en' => 'P2C E200 touch POS all-in-one with 4 GB RAM and 128 GB storage. POS software, peripherals, 1C integration, warranty and service.',
        ],
        'specs' => ['ram' => 4, 'storage' => 128],
        'missing' => true,
    ]),
    $make([
        'sku' => '221', 'model' => 'DBS-669 J4125 8/128', 'brand' => 'POS System', 'category' => 'pos-terminals', 'price' => 5500000.0,
        'name' => [
            'uz' => 'POS System DBS-669 sensorli monoblok, 15", J4125, 8/128 GB',
            'ru' => 'Сенсорный моноблок POS System DBS-669, 15", J4125, 8/128 ГБ',
            'en' => 'POS System DBS-669 touch all-in-one, 15", J4125, 8/128 GB',
        ],
        'short' => [
            'uz' => '15 dyuymli sensorli POS monoblok, J4125 protsessori, 8 GB operativ va 128 GB doimiy xotira bilan.',
            'ru' => '15-дюймовый сенсорный POS-моноблок на процессоре J4125 с 8 ГБ ОЗУ и 128 ГБ памяти.',
            'en' => '15-inch touch POS all-in-one with a J4125 processor, 8 GB RAM and 128 GB storage.',
        ],
        'desc' => $desc(
            [
                'uz' => '<strong>POS System DBS-669</strong> — 15 dyuymli sensorli POS monoblok, J4125 protsessori, 8 GB operativ va 128 GB doimiy xotirali. Kompyuter va ekran bitta korpusda, kassa joyida kam joy egallaydi.',
                'ru' => '<strong>POS System DBS-669</strong> — 15-дюймовый сенсорный POS-моноблок на процессоре J4125 с 8 ГБ оперативной и 128 ГБ встроенной памяти. Компьютер и экран в одном корпусе, на кассе занимает мало места.',
                'en' => 'The <strong>POS System DBS-669</strong> is a 15-inch touch POS all-in-one with a J4125 processor, 8 GB RAM and 128 GB storage. Computer and screen share one housing, saving counter space.',
            ],
            [
                'uz' => $li('<strong>Chakana savdo:</strong> doʻkon va supermarket kassasi.', '<strong>HoReCa:</strong> zal va bar kassasi.', '<strong>Dorixona:</strong> sotuv kassasi.', '15 dyuym, 8 GB RAM, 128 GB xotira.'),
                'ru' => $li('<strong>Розница:</strong> касса магазина и супермаркета.', '<strong>HoReCa:</strong> касса зала и бара.', '<strong>Аптека:</strong> кассовое место для продаж.', '15 дюймов, 8 ГБ ОЗУ, 128 ГБ памяти.'),
                'en' => $li('<strong>Retail:</strong> shop and supermarket tills.', '<strong>HoReCa:</strong> dining-room and bar tills.', '<strong>Pharmacy:</strong> a checkout station.', '15 inches, 8 GB RAM, 128 GB storage.'),
            ],
            $svc['terminal'],
        ),
        'mt' => [
            'uz' => 'POS System DBS-669 POS monoblok 15" 8/128 — Poson',
            'ru' => 'POS-моноблок POS System DBS-669 15" 8/128 — Poson',
            'en' => 'POS System DBS-669 15" POS all-in-one 8/128 — Poson',
        ],
        'md' => [
            'uz' => 'POS System DBS-669: 15 dyuymli sensorli POS monoblok, J4125, 8 GB RAM, 128 GB xotira. Kassa dasturini oʻrnatish, 1C integratsiyasi, kafolat va servis.',
            'ru' => 'POS System DBS-669: 15-дюймовый сенсорный POS-моноблок, J4125, 8 ГБ ОЗУ, 128 ГБ памяти. Установка кассовой программы, интеграция с 1С, гарантия, сервис.',
            'en' => 'POS System DBS-669 15-inch touch POS all-in-one, J4125, 8 GB RAM, 128 GB storage. POS software, 1C integration, warranty and service.',
        ],
        'specs' => ['screen_size' => ['value' => 15, 'key' => true], 'ram' => 8, 'storage' => 128],
        'missing' => true,
    ]),
    $make([
        'sku' => '219', 'model' => 'APEXA EL-1500 (G) J6412 4/128', 'brand' => 'Posbank', 'category' => 'pos-terminals', 'price' => 8700000.0,
        'name' => [
            'uz' => 'Posbank APEXA EL-1500 (G) POS monoblok, J6412, 4/128 GB',
            'ru' => 'POS-моноблок Posbank APEXA EL-1500 (G), J6412, 4/128 ГБ',
            'en' => 'Posbank APEXA EL-1500 (G) POS all-in-one, J6412, 4/128 GB',
        ],
        'short' => [
            'uz' => '15 dyuymli sensorli POS terminal Intel Celeron J6412, 4 GB operativ va 128 GB xotira bilan: ish joyi uchun ishonchli kassa.',
            'ru' => '15-дюймовый сенсорный POS-терминал на Intel Celeron J6412 с 4 ГБ ОЗУ и 128 ГБ памяти для надёжной кассы.',
            'en' => '15-inch touch POS terminal with Intel Celeron J6412, 4 GB RAM and 128 GB storage for a dependable checkout.',
        ],
        'desc' => $desc(
            [
                'uz' => '<strong>Posbank APEXA EL-1500</strong> — 15 dyuymli sensorli POS terminal. Ishlab chiqaruvchi maʼlumotiga koʻra, u Intel Celeron J6412 protsessorida ishlaydi, sensor ekrani koʻp barmoqli, Windows 10 IoT Enterprise LTSC bilan ishlashni qoʻllab-quvvatlaydi; I/O paneli yuqori tomonda joylashgan. Bu (G) varianti 4 GB operativ va 128 GB doimiy xotira bilan.',
                'ru' => '<strong>Posbank APEXA EL-1500</strong> — 15-дюймовый сенсорный POS-терминал. По данным производителя, он построен на Intel Celeron J6412, имеет мультитач-экран и поддерживает Windows 10 IoT Enterprise LTSC; панель разъёмов расположена сверху. Вариант (G) — с 4 ГБ ОЗУ и 128 ГБ встроенной памяти.',
                'en' => 'The <strong>Posbank APEXA EL-1500</strong> is a 15-inch touch POS terminal. According to the manufacturer it runs on an Intel Celeron J6412, has a multi-touch screen, supports Windows 10 IoT Enterprise LTSC and keeps its I/O panel on top. This (G) version comes with 4 GB RAM and 128 GB storage.',
            ],
            [
                'uz' => $li('<strong>Supermarket va doʻkonlar:</strong> kassa tugunlari.', '<strong>HoReCa:</strong> restoran va kafe kassasi.', '<strong>Dorixona:</strong> sotuv kassasi.', 'Koʻp barmoqli 15 dyuymli ekran, Gigabit Ethernet, chek printeri va pul yashigini ulash portlari.'),
                'ru' => $li('<strong>Супермаркеты и магазины:</strong> кассовые узлы.', '<strong>HoReCa:</strong> касса ресторана и кафе.', '<strong>Аптека:</strong> кассовое место для продаж.', 'Мультитач-экран 15 дюймов, Gigabit Ethernet, порты для чекового принтера и денежного ящика.'),
                'en' => $li('<strong>Supermarkets and shops:</strong> checkout lanes.', '<strong>HoReCa:</strong> restaurant and café tills.', '<strong>Pharmacy:</strong> a checkout station.', '15-inch multi-touch screen, Gigabit Ethernet, ports for a receipt printer and cash drawer.'),
            ],
            $svc['terminal'],
        ),
        'mt' => [
            'uz' => 'Posbank APEXA EL-1500 (G) POS terminal 4/128 — Poson',
            'ru' => 'POS-терминал Posbank APEXA EL-1500 (G) 4/128 — Poson',
            'en' => 'Posbank APEXA EL-1500 (G) POS terminal 4/128 — Poson',
        ],
        'md' => [
            'uz' => 'Posbank APEXA EL-1500 (G): 15 dyuymli sensorli POS terminal, Celeron J6412, 4 GB RAM, 128 GB. Kassa dasturi, 1C integratsiyasi, kafolat va servis.',
            'ru' => 'Posbank APEXA EL-1500 (G): 15-дюймовый сенсорный POS-терминал, Celeron J6412, 4 ГБ ОЗУ, 128 ГБ. Кассовая программа, интеграция с 1С, гарантия, сервис.',
            'en' => 'Posbank APEXA EL-1500 (G) 15-inch touch POS terminal, Celeron J6412, 4 GB RAM, 128 GB. POS software, 1C integration, warranty and service.',
        ],
        'specs' => [
            'os' => ['value' => 'Windows', 'key' => true],
            'screen_size' => ['value' => 15, 'key' => true],
            'ram' => 4,
            'storage' => 128,
        ],
        'missing' => false,
    ]),
    $make([
        'sku' => '218', 'model' => 'APEXA EL-1500 (W) J6412 4/128', 'brand' => 'Posbank', 'category' => 'pos-terminals', 'price' => 8700000.0,
        'name' => [
            'uz' => 'Posbank APEXA EL-1500 (W) POS monoblok, J6412, 4/128 GB',
            'ru' => 'POS-моноблок Posbank APEXA EL-1500 (W), J6412, 4/128 ГБ',
            'en' => 'Posbank APEXA EL-1500 (W) POS all-in-one, J6412, 4/128 GB',
        ],
        'short' => [
            'uz' => '15 dyuymli sensorli POS terminal Intel Celeron J6412, 4 GB operativ va 128 GB xotira bilan: ish joyi uchun ishonchli kassa.',
            'ru' => '15-дюймовый сенсорный POS-терминал на Intel Celeron J6412 с 4 ГБ ОЗУ и 128 ГБ памяти для надёжной кассы.',
            'en' => '15-inch touch POS terminal with Intel Celeron J6412, 4 GB RAM and 128 GB storage for a dependable checkout.',
        ],
        'desc' => $desc(
            [
                'uz' => '<strong>Posbank APEXA EL-1500</strong> — 15 dyuymli sensorli POS terminal. Ishlab chiqaruvchi maʼlumotiga koʻra, u Intel Celeron J6412 protsessorida ishlaydi, sensor ekrani koʻp barmoqli, Windows 10 IoT Enterprise LTSC bilan ishlashni qoʻllab-quvvatlaydi; I/O paneli yuqori tomonda joylashgan. Bu (W) varianti 4 GB operativ va 128 GB doimiy xotira bilan.',
                'ru' => '<strong>Posbank APEXA EL-1500</strong> — 15-дюймовый сенсорный POS-терминал. По данным производителя, он построен на Intel Celeron J6412, имеет мультитач-экран и поддерживает Windows 10 IoT Enterprise LTSC; панель разъёмов расположена сверху. Вариант (W) — с 4 ГБ ОЗУ и 128 ГБ встроенной памяти.',
                'en' => 'The <strong>Posbank APEXA EL-1500</strong> is a 15-inch touch POS terminal. According to the manufacturer it runs on an Intel Celeron J6412, has a multi-touch screen, supports Windows 10 IoT Enterprise LTSC and keeps its I/O panel on top. This (W) version comes with 4 GB RAM and 128 GB storage.',
            ],
            [
                'uz' => $li('<strong>Supermarket va doʻkonlar:</strong> kassa tugunlari.', '<strong>HoReCa:</strong> restoran va kafe kassasi.', '<strong>Dorixona:</strong> sotuv kassasi.', 'Koʻp barmoqli 15 dyuymli ekran, Gigabit Ethernet, chek printeri va pul yashigini ulash portlari.'),
                'ru' => $li('<strong>Супермаркеты и магазины:</strong> кассовые узлы.', '<strong>HoReCa:</strong> касса ресторана и кафе.', '<strong>Аптека:</strong> кассовое место для продаж.', 'Мультитач-экран 15 дюймов, Gigabit Ethernet, порты для чекового принтера и денежного ящика.'),
                'en' => $li('<strong>Supermarkets and shops:</strong> checkout lanes.', '<strong>HoReCa:</strong> restaurant and café tills.', '<strong>Pharmacy:</strong> a checkout station.', '15-inch multi-touch screen, Gigabit Ethernet, ports for a receipt printer and cash drawer.'),
            ],
            $svc['terminal'],
        ),
        'mt' => [
            'uz' => 'Posbank APEXA EL-1500 (W) POS terminal 4/128 — Poson',
            'ru' => 'POS-терминал Posbank APEXA EL-1500 (W) 4/128 — Poson',
            'en' => 'Posbank APEXA EL-1500 (W) POS terminal 4/128 — Poson',
        ],
        'md' => [
            'uz' => 'Posbank APEXA EL-1500 (W): 15 dyuymli sensorli POS terminal, Celeron J6412, 4 GB RAM, 128 GB. Kassa dasturi, 1C integratsiyasi, kafolat va servis.',
            'ru' => 'Posbank APEXA EL-1500 (W): 15-дюймовый сенсорный POS-терминал, Celeron J6412, 4 ГБ ОЗУ, 128 ГБ. Кассовая программа, интеграция с 1С, гарантия, сервис.',
            'en' => 'Posbank APEXA EL-1500 (W) 15-inch touch POS terminal, Celeron J6412, 4 GB RAM, 128 GB. POS software, 1C integration, warranty and service.',
        ],
        'specs' => [
            'os' => ['value' => 'Windows', 'key' => true],
            'screen_size' => ['value' => 15, 'key' => true],
            'ram' => 4,
            'storage' => 128,
        ],
        'missing' => false,
    ]),
    $make([
        'sku' => '220', 'model' => 'APEXA EL-1500 (G) J6412 8/128', 'brand' => 'Posbank', 'category' => 'pos-terminals', 'price' => 9650000.0,
        'name' => [
            'uz' => 'Posbank APEXA EL-1500 (G) POS monoblok, J6412, 8/128 GB',
            'ru' => 'POS-моноблок Posbank APEXA EL-1500 (G), J6412, 8/128 ГБ',
            'en' => 'Posbank APEXA EL-1500 (G) POS all-in-one, J6412, 8/128 GB',
        ],
        'short' => [
            'uz' => '15 dyuymli sensorli POS terminal Intel Celeron J6412, 8 GB operativ va 128 GB xotira bilan: katta tovar bazali kassalar uchun.',
            'ru' => '15-дюймовый сенсорный POS-терминал на Intel Celeron J6412 с 8 ГБ ОЗУ и 128 ГБ памяти для касс с большой базой товаров.',
            'en' => '15-inch touch POS terminal with Intel Celeron J6412, 8 GB RAM and 128 GB storage for tills with a large product base.',
        ],
        'desc' => $desc(
            [
                'uz' => '<strong>Posbank APEXA EL-1500</strong> — 15 dyuymli sensorli POS terminal. Ishlab chiqaruvchi maʼlumotiga koʻra, u Intel Celeron J6412 protsessorida ishlaydi, sensor ekrani koʻp barmoqli, Windows 10 IoT Enterprise LTSC bilan ishlashni qoʻllab-quvvatlaydi. Bu (G) varianti 8 GB operativ va 128 GB doimiy xotira bilan — katta tovar bazasi va koʻp vazifali kassa dasturlari uchun zaxira.',
                'ru' => '<strong>Posbank APEXA EL-1500</strong> — 15-дюймовый сенсорный POS-терминал. По данным производителя, он построен на Intel Celeron J6412, имеет мультитач-экран и поддерживает Windows 10 IoT Enterprise LTSC. Вариант (G) — с 8 ГБ ОЗУ и 128 ГБ встроенной памяти: запас для большой базы товаров и многозадачных кассовых программ.',
                'en' => 'The <strong>Posbank APEXA EL-1500</strong> is a 15-inch touch POS terminal. According to the manufacturer it runs on an Intel Celeron J6412, has a multi-touch screen and supports Windows 10 IoT Enterprise LTSC. This (G) version has 8 GB RAM and 128 GB storage, leaving headroom for large product databases and heavier POS software.',
            ],
            [
                'uz' => $li('<strong>Supermarket va doʻkonlar:</strong> katta assortimentli kassa tugunlari.', '<strong>HoReCa:</strong> restoran va kafe kassasi.', '<strong>Dorixona:</strong> sotuv kassasi.', '15 dyuymli koʻp barmoqli ekran, 8 GB RAM, 128 GB xotira.'),
                'ru' => $li('<strong>Супермаркеты и магазины:</strong> кассовые узлы с большим ассортиментом.', '<strong>HoReCa:</strong> касса ресторана и кафе.', '<strong>Аптека:</strong> кассовое место для продаж.', 'Мультитач-экран 15 дюймов, 8 ГБ ОЗУ, 128 ГБ памяти.'),
                'en' => $li('<strong>Supermarkets and shops:</strong> checkout lanes with a wide range.', '<strong>HoReCa:</strong> restaurant and café tills.', '<strong>Pharmacy:</strong> a checkout station.', '15-inch multi-touch screen, 8 GB RAM, 128 GB storage.'),
            ],
            $svc['terminal'],
        ),
        'mt' => [
            'uz' => 'Posbank APEXA EL-1500 (G) POS terminal 8/128 — Poson',
            'ru' => 'POS-терминал Posbank APEXA EL-1500 (G) 8/128 — Poson',
            'en' => 'Posbank APEXA EL-1500 (G) POS terminal 8/128 — Poson',
        ],
        'md' => [
            'uz' => 'Posbank APEXA EL-1500 (G): 15 dyuymli sensorli POS terminal, Celeron J6412, 8 GB RAM, 128 GB. Kassa dasturi, 1C integratsiyasi, kafolat va servis.',
            'ru' => 'Posbank APEXA EL-1500 (G): 15-дюймовый сенсорный POS-терминал, Celeron J6412, 8 ГБ ОЗУ, 128 ГБ. Кассовая программа, интеграция с 1С, гарантия, сервис.',
            'en' => 'Posbank APEXA EL-1500 (G) 15-inch touch POS terminal, Celeron J6412, 8 GB RAM, 128 GB. POS software, 1C integration, warranty and service.',
        ],
        'specs' => [
            'os' => ['value' => 'Windows', 'key' => true],
            'screen_size' => ['value' => 15, 'key' => true],
            'ram' => 8,
            'storage' => 128,
        ],
        'missing' => false,
    ]),
    $make([
        'sku' => '159', 'model' => 'N250 16/128 (N97)', 'brand' => 'P2C', 'category' => 'pos-terminals', 'price' => 8000000.0,
        'name' => [
            'uz' => 'P2C N250 sensorli POS monoblok, 16/128 GB',
            'ru' => 'Сенсорный POS-моноблок P2C N250, 16/128 ГБ',
            'en' => 'P2C N250 touch POS all-in-one, 16/128 GB',
        ],
        'short' => [
            'uz' => 'Sensorli POS monoblok 16 GB operativ va 128 GB doimiy xotira bilan: murakkab kassa va hisob dasturlari uchun.',
            'ru' => 'Сенсорный POS-моноблок с 16 ГБ ОЗУ и 128 ГБ памяти для требовательных кассовых и учётных программ.',
            'en' => 'Touch POS all-in-one with 16 GB RAM and 128 GB storage for demanding POS and accounting software.',
        ],
        'desc' => $desc(
            [
                'uz' => '<strong>P2C N250</strong> — sensorli ekranli POS monoblok: kompyuter va ekran bitta korpusda. 16 GB operativ va 128 GB doimiy xotira katta tovar bazasi va 1C bilan ishlash uchun katta zaxira beradi.',
                'ru' => '<strong>P2C N250</strong> — сенсорный POS-моноблок: компьютер и экран в одном корпусе. 16 ГБ оперативной и 128 ГБ встроенной памяти дают большой запас для крупной базы товаров и работы с 1С.',
                'en' => 'The <strong>P2C N250</strong> is a touch POS all-in-one with the computer and screen in one housing. 16 GB RAM and 128 GB storage leave plenty of headroom for a large product base and 1C.',
            ],
            [
                'uz' => $li('<strong>Chakana savdo:</strong> supermarket va doʻkon kassasi.', '<strong>HoReCa:</strong> zal va bar kassasi.', '<strong>Ombor va savdo:</strong> hisob dasturi bilan ish joyi.', '16 GB RAM, 128 GB xotira.'),
                'ru' => $li('<strong>Розница:</strong> касса супермаркета и магазина.', '<strong>HoReCa:</strong> касса зала и бара.', '<strong>Склад и торговля:</strong> рабочее место с учётной программой.', '16 ГБ ОЗУ, 128 ГБ памяти.'),
                'en' => $li('<strong>Retail:</strong> supermarket and shop tills.', '<strong>HoReCa:</strong> dining-room and bar tills.', '<strong>Warehouse and trade:</strong> a workstation with accounting software.', '16 GB RAM, 128 GB storage.'),
            ],
            $svc['terminal'],
        ),
        'mt' => [
            'uz' => 'P2C N250 sensorli POS monoblok 16/128 — Poson',
            'ru' => 'POS-моноблок P2C N250 16/128 ГБ — Poson',
            'en' => 'P2C N250 touch POS all-in-one 16/128 GB — Poson',
        ],
        'md' => [
            'uz' => 'P2C N250 sensorli POS monoblok: 16 GB RAM, 128 GB xotira. Kassa va hisob dasturini oʻrnatish, 1C integratsiyasi, kafolat va servis.',
            'ru' => 'Сенсорный POS-моноблок P2C N250: 16 ГБ ОЗУ, 128 ГБ памяти. Установка кассовой и учётной программы, интеграция с 1С, гарантия, сервис.',
            'en' => 'P2C N250 touch POS all-in-one with 16 GB RAM and 128 GB storage. POS and accounting software setup, 1C integration, warranty and service.',
        ],
        'specs' => ['ram' => 16, 'storage' => 128],
        'missing' => true,
    ]),
    $make([
        'sku' => '224', 'model' => '27IRH9 (i5-13420H, 8/512)', 'brand' => 'Lenovo', 'category' => 'pos-terminals', 'price' => 11000000.0,
        'name' => [
            'uz' => 'Lenovo AIO 27IRH9 monoblok kompyuter, i5-13420H, 8 GB, 512 GB',
            'ru' => 'Моноблок Lenovo AIO 27IRH9, i5-13420H, 8 ГБ, 512 ГБ',
            'en' => 'Lenovo AIO 27IRH9 all-in-one PC, i5-13420H, 8 GB, 512 GB',
        ],
        'short' => [
            'uz' => '27 dyuymli Lenovo monoblok kompyuter Intel Core i5-13420H, 8 GB operativ va 512 GB xotira bilan: ofis va kassa tugunlari uchun.',
            'ru' => '27-дюймовый моноблок Lenovo на Intel Core i5-13420H с 8 ГБ ОЗУ и 512 ГБ памяти для офиса и кассовых узлов.',
            'en' => '27-inch Lenovo all-in-one PC with Intel Core i5-13420H, 8 GB RAM and 512 GB storage for offices and back-office checkout lanes.',
        ],
        'desc' => $desc(
            [
                'uz' => '<strong>Lenovo AIO 27IRH9</strong> — 27 dyuymli monoblok kompyuter: Intel Core i5-13420H protsessori, 8 GB operativ va 512 GB doimiy xotira. Oddiy kompyuter sifatida ofis, ombor hisobi va menejer ish joyi uchun mos.',
                'ru' => '<strong>Lenovo AIO 27IRH9</strong> — 27-дюймовый моноблок на процессоре Intel Core i5-13420H с 8 ГБ оперативной и 512 ГБ встроенной памяти. Универсальный ПК для офиса, складского учёта и рабочего места менеджера.',
                'en' => 'The <strong>Lenovo AIO 27IRH9</strong> is a 27-inch all-in-one PC with an Intel Core i5-13420H, 8 GB RAM and 512 GB storage. A general-purpose computer for offices, stock accounting and manager workstations.',
            ],
            [
                'uz' => $li('<strong>Ofis:</strong> menejer va buxgalter ish joyi.', '<strong>Ombor:</strong> hisob dasturi bilan ish joyi.', '<strong>Savdo:</strong> yirik ekranli rahbar yoki administrator ish joyi.', '27 dyuym, 8 GB RAM, 512 GB xotira.'),
                'ru' => $li('<strong>Офис:</strong> рабочее место менеджера и бухгалтера.', '<strong>Склад:</strong> рабочее место с учётной программой.', '<strong>Торговля:</strong> рабочее место администратора с большим экраном.', '27 дюймов, 8 ГБ ОЗУ, 512 ГБ памяти.'),
                'en' => $li('<strong>Office:</strong> manager and accountant workstations.', '<strong>Warehouse:</strong> a workstation with stock-accounting software.', '<strong>Trade:</strong> an administrator desk with a large screen.', '27 inches, 8 GB RAM, 512 GB storage.'),
            ],
            [
                'uz' => 'Poson kompyuterni yetkazib beradi, kerakli dasturlarni (1C, ombor va kassa dasturi) oʻrnatadi, printer va skanerni ulaydi. Kafolat va servis — oʻz servis markazimizda.',
                'ru' => 'Poson поставит компьютер, установит нужные программы (1С, складскую и кассовую), подключит принтер и сканер. Гарантия и сервис — в нашем сервисном центре.',
                'en' => 'Poson delivers the PC, installs the software you need (1C, warehouse and POS programs) and connects the printer and scanner. Warranty and service are handled in our own service center.',
            ],
        ),
        'mt' => [
            'uz' => 'Lenovo AIO 27IRH9 monoblok i5-13420H — Poson',
            'ru' => 'Моноблок Lenovo AIO 27IRH9 i5-13420H — Poson',
            'en' => 'Lenovo AIO 27IRH9 all-in-one PC i5-13420H — Poson',
        ],
        'md' => [
            'uz' => 'Lenovo AIO 27IRH9 27 dyuymli monoblok: Core i5-13420H, 8 GB RAM, 512 GB. Ofis, ombor va savdo ish joylari uchun. Dasturlarni oʻrnatish, kafolat, servis.',
            'ru' => 'Моноблок Lenovo AIO 27IRH9 27": Core i5-13420H, 8 ГБ ОЗУ, 512 ГБ. Для офиса, склада и торговли. Установка программ, интеграция с 1С, гарантия, сервис.',
            'en' => 'Lenovo AIO 27IRH9 27-inch all-in-one: Core i5-13420H, 8 GB RAM, 512 GB. For offices, warehouses and trade. Software setup, warranty and service.',
        ],
        'specs' => ['screen_size' => ['value' => 27, 'key' => true], 'ram' => 8, 'storage' => 512],
        'missing' => true,
    ]),
    $make([
        'sku' => '216', 'model' => '7 inch', 'brand' => 'Sunany', 'category' => 'customer-displays', 'price' => 2187000.0,
        'name' => [
            'uz' => 'Sunany xaridor displeyi, 7 dyuym',
            'ru' => 'Дисплей покупателя Sunany, 7 дюймов',
            'en' => 'Sunany customer display, 7 inch',
        ],
        'short' => [
            'uz' => '7 dyuymli xaridor displeyi: kassada summa va tovarlarni xaridorga koʻrsatadi. Poson kassa dasturiga ulaydi.',
            'ru' => '7-дюймовый дисплей покупателя: показывает покупателю товары и сумму на кассе. Poson подключит к кассовой программе.',
            'en' => '7-inch customer display that shows items and the total at the till. Poson connects it to your POS software.',
        ],
        'desc' => $desc(
            [
                'uz' => '<strong>Sunany 7 dyuymli xaridor displeyi</strong> kassada tovar nomi va umumiy summani xaridorga koʻrsatadi. Savdoda shaffoflik va ishonchni oshiradi.',
                'ru' => '<strong>Дисплей покупателя Sunany 7 дюймов</strong> показывает покупателю наименования товаров и итоговую сумму на кассе. Это добавляет прозрачности и доверия при расчёте.',
                'en' => 'The <strong>Sunany 7-inch customer display</strong> shows shoppers the items and the total at the till. It adds transparency and trust at checkout.',
            ],
            [
                'uz' => $li('<strong>Chakana savdo:</strong> xaridor kassadagi hisobni koʻradi.', '<strong>Dorixona:</strong> narx va summani aniq koʻrsatish.', '<strong>HoReCa:</strong> hisob-kitobni mehmonga koʻrsatish.', '7 dyuymli displey.'),
                'ru' => $li('<strong>Розница:</strong> покупатель видит расчёт на кассе.', '<strong>Аптека:</strong> наглядный показ цены и суммы.', '<strong>HoReCa:</strong> показ счёта гостю.', 'Дисплей 7 дюймов.'),
                'en' => $li('<strong>Retail:</strong> shoppers see the till calculation.', '<strong>Pharmacy:</strong> clear display of item price and total.', '<strong>HoReCa:</strong> showing the bill to the guest.', '7-inch display.'),
            ],
            [
                'uz' => 'Poson displeyni kassa dasturiga ulaydi, kerakli maʼlumotlar chiqishini sozlaydi va ishini tekshiradi. Kafolatli servis — oʻz servis markazimizda.',
                'ru' => 'Poson подключит дисплей к кассовой программе, настроит вывод нужных данных и проверит работу. Гарантийный сервис — в нашем сервисном центре.',
                'en' => 'Poson connects the display to your POS software, configures what it shows and tests it. Warranty service is handled in our own service center.',
            ],
        ),
        'mt' => [
            'uz' => 'Sunany 7 dyuymli xaridor displeyi — Poson',
            'ru' => 'Дисплей покупателя Sunany 7 дюймов — Poson',
            'en' => 'Sunany 7-inch customer display — Poson',
        ],
        'md' => [
            'uz' => 'Sunany 7 dyuymli xaridor displeyi kassa uchun: tovar va summani xaridorga koʻrsatadi. Kassa dasturiga ulash, sozlash, kafolat va servis.',
            'ru' => 'Дисплей покупателя Sunany 7" для кассы: показывает товары и сумму. Подключение к кассовой программе, настройка, гарантия и сервис.',
            'en' => 'Sunany 7-inch customer display for tills, showing items and the total. POS connection, setup, warranty and service from Poson.',
        ],
        'specs' => [],
        'missing' => true,
    ]),

    // ─── Scales ─────────────────────────────────────────────────────────────
    $make([
        'sku' => '29', 'model' => 'M 15-2,5', 'brand' => 'Shtrix-Print', 'category' => 'scales', 'price' => 6600000.0,
        'name' => [
            'uz' => 'Shtrix-Print M 15-2,5 yorliq bosadigan elektron tarozi',
            'ru' => 'Весы с печатью этикеток Штрих-Принт M 15-2,5',
            'en' => 'Shtrix-Print M 15-2.5 label-printing scale',
        ],
        'short' => [
            'uz' => '15 kg gacha tortadigan yorliq bosuvchi tarozi: tovar nomi, narxi va shtrix-kodli yorliqni darhol chop etadi.',
            'ru' => 'Весы с печатью этикеток до 15 кг: сразу печатают этикетку с названием, ценой и штрихкодом.',
            'en' => 'Label-printing scale up to 15 kg that prints a label with the product name, price and barcode on the spot.',
        ],
        'desc' => $desc(
            [
                'uz' => '<strong>Shtrix-Print M 15-2,5</strong> — savdo zali va fasovka uchun yorliq bosadigan tarozi, tortish chegarasi 15 kg. Ikki qatorli displey vazn, narx va summani koʻrsatadi, tez tanlash tugmalari tez-tez sotiladigan tovarlarni bir bosishda tanlashga imkon beradi.',
                'ru' => '<strong>Штрих-Принт M 15-2,5</strong> — весы с печатью этикеток для торгового зала и фасовки, наибольший предел взвешивания 15 кг. Двухстрочный дисплей показывает массу, цену и стоимость, клавиши быстрого доступа позволяют выбирать ходовые товары одним нажатием.',
                'en' => 'The <strong>Shtrix-Print M 15-2.5</strong> is a label-printing scale for the shop floor and pre-packing, with a 15 kg maximum capacity. A two-line display shows weight, price and total, and quick-access keys pick popular items in one press.',
            ],
            [
                'uz' => $li('<strong>Chakana savdo:</strong> sabzavot, meva, goʻsht va qandolat boʻlimlari.', '<strong>Fasovka:</strong> vaznli mahsulotni yorliq bilan qadoqlash.', '<strong>Supermarket:</strong> yorliqdagi shtrix-kod kassada skanerlanadi.', 'Tortish chegarasi 15 kg.'),
                'ru' => $li('<strong>Розница:</strong> отделы овощей, фруктов, мяса и кондитерки.', '<strong>Фасовка:</strong> упаковка весового товара с этикеткой.', '<strong>Супермаркет:</strong> штрихкод на этикетке считывается на кассе.', 'Наибольший предел взвешивания 15 кг.'),
                'en' => $li('<strong>Retail:</strong> produce, meat and confectionery counters.', '<strong>Pre-packing:</strong> weighed goods packed with a label.', '<strong>Supermarkets:</strong> the label barcode is scanned at the till.', 'Maximum capacity 15 kg.'),
            ],
            $svc['scale'],
        ),
        'mt' => [
            'uz' => 'Shtrix-Print M 15-2,5 yorliq bosuvchi tarozi — Poson',
            'ru' => 'Весы с печатью этикеток Штрих-Принт M 15-2,5 — Poson',
            'en' => 'Shtrix-Print M 15-2.5 label-printing scale — Poson',
        ],
        'md' => [
            'uz' => 'Shtrix-Print M 15-2,5: 15 kg gacha yorliq bosuvchi tarozi, shtrix-kodli yorliq. Mahsulot bazasini yuklash, kassa va 1C bilan ulash, kafolat va servis.',
            'ru' => 'Штрих-Принт M 15-2,5: весы с печатью этикеток до 15 кг, штрихкод на этикетке. Загрузка базы товаров, связь с кассой и 1С, гарантия, сервис.',
            'en' => 'Shtrix-Print M 15-2.5 label-printing scale up to 15 kg with barcode labels. Product database load, POS and 1C link, warranty and service.',
        ],
        'specs' => ['max_weight' => ['value' => 15, 'key' => true]],
        'missing' => false,
    ]),
    $make([
        'sku' => '136', 'model' => 'RLS1000C', 'brand' => 'Rongta', 'category' => 'scales', 'price' => 5500000.0,
        'name' => [
            'uz' => 'Rongta RLS1000C yorliq bosadigan elektron tarozi',
            'ru' => 'Весы с печатью этикеток Rongta RLS1000C',
            'en' => 'Rongta RLS1000C label-printing scale',
        ],
        'short' => [
            'uz' => 'Shtrix-kodli yorliq bosadigan elektron tarozi: vaznli mahsulot savdosi va fasovka uchun, Poson sozlab beradi.',
            'ru' => 'Электронные весы с печатью этикеток со штрихкодом для весовых товаров и фасовки, настройка от Poson.',
            'en' => 'Electronic scale that prints barcode labels for weighed goods and pre-packing, set up by Poson.',
        ],
        'desc' => $desc(
            [
                'uz' => '<strong>Rongta RLS1000C</strong> — vaznli tovarlar uchun yorliq bosadigan elektron tarozi. Tortilgan mahsulotga shtrix-kodli yorliq chop etadi, shu tufayli kassada hisob tez va xatosiz boʻladi.',
                'ru' => '<strong>Rongta RLS1000C</strong> — электронные весы с печатью этикеток для весовых товаров. Печатают на взвешенном товаре этикетку со штрихкодом, поэтому расчёт на кассе проходит быстро и без ошибок.',
                'en' => 'The <strong>Rongta RLS1000C</strong> is an electronic label-printing scale for weighed goods. It prints a barcode label for each weighed item, so checkout is quick and error-free.',
            ],
            [
                'uz' => $li('<strong>Chakana savdo:</strong> sabzavot, meva va gastronomiya boʻlimlari.', '<strong>Fasovka:</strong> vaznli mahsulotni yorliq bilan qadoqlash.', '<strong>Supermarket:</strong> yorliqdagi shtrix-kod kassada skanerlanadi.', 'Tortish chegarasi modifikatsiyaga qarab belgilanadi, buyurtmada aniqlaymiz.'),
                'ru' => $li('<strong>Розница:</strong> отделы овощей, фруктов и гастрономии.', '<strong>Фасовка:</strong> упаковка весового товара с этикеткой.', '<strong>Супермаркет:</strong> штрихкод на этикетке считывается на кассе.', 'Предел взвешивания зависит от модификации, уточним при заказе.'),
                'en' => $li('<strong>Retail:</strong> produce and deli counters.', '<strong>Pre-packing:</strong> weighed goods packed with a label.', '<strong>Supermarkets:</strong> the label barcode is scanned at the till.', 'The capacity depends on the version, which we confirm when you order.'),
            ],
            $svc['scale'],
        ),
        'mt' => [
            'uz' => 'Rongta RLS1000C yorliq bosuvchi tarozi — Poson',
            'ru' => 'Весы с печатью этикеток Rongta RLS1000C — Poson',
            'en' => 'Rongta RLS1000C label-printing scale — Poson',
        ],
        'md' => [
            'uz' => 'Rongta RLS1000C: shtrix-kodli yorliq bosuvchi elektron tarozi vaznli savdo va fasovka uchun. Mahsulot bazasini yuklash, kassa bilan ulash, kafolat, servis.',
            'ru' => 'Rongta RLS1000C: электронные весы с печатью этикеток со штрихкодом для весовой торговли и фасовки. Загрузка базы товаров, связь с кассой, гарантия, сервис.',
            'en' => 'Rongta RLS1000C electronic scale printing barcode labels for weighed goods and pre-packing. Database load, POS link, warranty and service.',
        ],
        'specs' => [],
        'missing' => true,
    ]),

    // ─── Price checkers ─────────────────────────────────────────────────────
    $make([
        'sku' => '217', 'model' => 'HESTIA 101W', 'brand' => 'Jassway', 'category' => 'price-checkers', 'price' => 7600000.0,
        'name' => [
            'uz' => 'Jassway HESTIA 101W narx tekshirgich (prays-cheker)',
            'ru' => 'Прайс-чекер Jassway HESTIA 101W',
            'en' => 'Jassway HESTIA 101W price checker',
        ],
        'short' => [
            'uz' => 'Savdo zali uchun prays-cheker: xaridor tovar kodini skanerlab, narx va maʼlumotni oʻzi koʻradi. Poson bazaga ulaydi.',
            'ru' => 'Прайс-чекер для торгового зала: покупатель сам сканирует товар и видит цену и информацию. Poson подключит к базе.',
            'en' => 'Price checker for the shop floor: shoppers scan an item to see its price and details. Poson links it to your product database.',
        ],
        'desc' => $desc(
            [
                'uz' => '<strong>Jassway HESTIA 101W</strong> — savdo zaliga moʻljallangan prays-cheker. Xaridor tovar shtrix-kodini skanerlaydi va ekranda narx hamda maʼlumotni koʻradi, kassa navbatiga borishi shart emas.',
                'ru' => '<strong>Jassway HESTIA 101W</strong> — прайс-чекер для торгового зала. Покупатель сканирует штрихкод товара и видит на экране цену и информацию, не обращаясь на кассу.',
                'en' => 'The <strong>Jassway HESTIA 101W</strong> is a price checker for the shop floor. Shoppers scan an item barcode and see its price and details on screen without going to the till.',
            ],
            [
                'uz' => $li('<strong>Supermarket va doʻkonlar:</strong> mustaqil narx tekshirish.', '<strong>Dorixona:</strong> dori narxi va maʼlumotini tezda koʻrsatish.', '<strong>Kassa navbati:</strong> savollar kamayadi, xodim boshqa ishga vaqt topadi.', 'Tovar bazasi bilan integratsiya.'),
                'ru' => $li('<strong>Супермаркеты и магазины:</strong> самостоятельная проверка цены.', '<strong>Аптека:</strong> быстрый показ цены и информации о препарате.', '<strong>Очередь на кассе:</strong> меньше вопросов, персонал занят другими задачами.', 'Интеграция с базой товаров.'),
                'en' => $li('<strong>Supermarkets and shops:</strong> self-service price lookup.', '<strong>Pharmacy:</strong> quick display of price and product information.', '<strong>Checkout queues:</strong> fewer questions, staff free for other tasks.', 'Integration with your product database.'),
            ],
            [
                'uz' => 'Poson qurilmani oʻrnatadi, tarmoqqa va 1C yoki kassa tovar bazasiga ulaydi, narxlar toʻgʻri chiqishini tekshiradi. Kafolat va servis — oʻz servis markazimizda.',
                'ru' => 'Poson установит устройство, подключит к сети и товарной базе 1С или кассовой программы, проверит корректный вывод цен. Гарантия и сервис — в нашем сервисном центре.',
                'en' => 'Poson installs the device, connects it to the network and to the 1C or POS product database, and checks that prices display correctly. Warranty and service are handled in our own service center.',
            ],
        ),
        'mt' => [
            'uz' => 'Jassway HESTIA 101W prays-cheker — Poson',
            'ru' => 'Прайс-чекер Jassway HESTIA 101W — Poson',
            'en' => 'Jassway HESTIA 101W price checker — Poson',
        ],
        'md' => [
            'uz' => 'Jassway HESTIA 101W prays-cheker: xaridor tovarni skanerlab narxni oʻzi koʻradi. Tovar bazasi (1C) bilan ulash, sozlash, kafolat va servis.',
            'ru' => 'Прайс-чекер Jassway HESTIA 101W: покупатель сам сканирует товар и видит цену. Подключение к товарной базе 1С, настройка, гарантия и сервис.',
            'en' => 'Jassway HESTIA 101W price checker: shoppers scan an item to see its price. Product database (1C) link, setup, warranty and service.',
        ],
        'specs' => [],
        'missing' => true,
    ]),
];
