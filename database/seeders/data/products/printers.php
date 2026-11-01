<?php

/**
 * Real product data, group "printers" (label printers, RFID printer, printheads).
 *
 * Source: docs/pm/import/products-base.json (rows with group = printers). Row shape follows
 * database/seeders/data/products.php plus two internal fields that are never displayed:
 * `has_missing_specs` (bool).
 *
 * - Prices are whole soʻm retail prices (the seeder converts them to tiyin); dealer and
 *   wholesale levels are null.
 * - Specs use attribute codes and exact uz option values from attributes.php. A spec is only
 *   set when a manufacturer page or the 1C name proves it. Unknown required specs are left out
 *   and the row gets has_missing_specs = true.
 * - USB Host is not an option of the `interface` attribute and is therefore left out of specs.
 * - Texts are generated from per-row lead sentences plus per-kind templates (use cases, service).
 *
 * Uzbek is Latin script: oʻ, gʻ use ʻ (U+02BB); the tutuq belgisi uses ʼ (U+02BC).
 *
 * @return list<array{
 *     sku: string,
 *     model: string|null,
 *     brand: string|null,
 *     category: string,
 *     type: 'hardware'|'consumable'|'software'|'service',
 *     unit: 'piece'|'roll'|'box'|'pack'|'license'|'hour',
 *     min_qty: int,
 *     sale_mode: 'cart'|'quote'|'both',
 *     stock_status: 'in_stock'|'on_order'|'out_of_stock',
 *     warranty_months: int|null,
 *     badge: 'hit'|'new'|null,
 *     is_featured: bool,
 *     price: int|null,
 *     price_dealer: int|null,
 *     price_wholesale: int|null,
 *     name: array{uz: string, ru: string, en: string},
 *     short_description: array{uz: string, ru: string, en: string},
 *     description: array{uz: string, ru: string, en: string},
 *     meta_title: array{uz: string, ru: string, en: string},
 *     meta_description: array{uz: string, ru: string, en: string},
 *     specs: array<string, mixed>,
 *     related: list<array{sku: string, type: 'accessory'|'consumable'|'alternative'|'software'}>,
 *     has_missing_specs: bool,
 * }>
 */
$dt = 'Toʻgʻridan-toʻgʻri termo';
$tt = 'Termotransfer';
$both = [$dt, $tt];

$tr = static fn (string $uz, string $ru, string $en): array => ['uz' => $uz, 'ru' => $ru, 'en' => $en];

$fmt = static fn (float|int $n, string $l): string => $l === 'en' ? (string) $n : str_replace('.', ',', (string) $n);

$kinds = [
    'desktop' => [
        'noun' => $tr('yorliq printeri', 'принтер этикеток', 'label printer'),
        'nounCap' => $tr('yorliq printeri', 'Принтер этикеток', 'label printer'),
        'metaNoun' => $tr('yorliq printeri', 'Принтер этикеток', 'label printer'),
        'use' => $tr('Doʻkon, dorixona va ombor uchun.', 'Для магазина, аптеки и склада.', 'For shops, pharmacies and warehouses.'),
        'bullets' => [
            'uz' => [['Chakana savdo:', 'javon yorliqlari, narx yorliqlari va tovar yorliqlari.'], ['Dorixona:', 'qadoq va retsept yorliqlari.'], ['Ombor va onlayn savdo:', 'mahsulot va joʻnatma yorliqlari.']],
            'ru' => [['Розница:', 'ценники и товарные этикетки.'], ['Аптека:', 'упаковочные и рецептурные этикетки.'], ['Склад и интернет-торговля:', 'этикетки на товар и отправления.']],
            'en' => [['Retail:', 'shelf and product labels.'], ['Pharmacy:', 'pack and prescription labels.'], ['Warehouse and e-commerce:', 'product and shipping labels.']],
        ],
        'svc' => $tr(
            'Poson printerni kompyuter va 1C ga ulaydi, kalibrlaydi, yorliq shablonlarini tayyorlaydi va xodimlaringizni oʻqitadi. Rasmiy kafolat davomida qurilmaga oʻz servis markazimizda xizmat koʻrsatamiz.',
            'Poson подключит принтер к компьютеру и 1С, выполнит калибровку, подготовит шаблоны этикеток и обучит сотрудников. В период официальной гарантии устройство обслуживает наш собственный сервисный центр.',
            'Poson connects the printer to your PC and 1C, calibrates it, prepares your label templates and trains your staff. During the official warranty, our own service center looks after the device.',
        ),
    ],
    'industrial' => [
        'noun' => $tr('sanoat yorliq printeri', 'промышленный принтер этикеток', 'industrial label printer'),
        'nounCap' => $tr('sanoat yorliq printeri', 'Промышленный принтер этикеток', 'industrial label printer'),
        'metaNoun' => $tr('sanoat printeri', 'Принтер этикеток', 'industrial printer'),
        'use' => $tr('Ombor, logistika va ishlab chiqarishda uzluksiz chop etish uchun.', 'Для непрерывной печати на складе, в логистике и на производстве.', 'For continuous printing in warehouses, logistics and manufacturing.'),
        'bullets' => [
            'uz' => [['Ombor va logistika:', 'palletlar, qutilar va joʻnatmalar uchun SSCC va transport yorliqlari.'], ['Ishlab chiqarish:', 'mahsulot markirovkasi va texnik yorliqlar.'], ['Chakana tarmoqlar:', 'taqsimlash markazida katta hajmdagi yorliqlarni chop etish.']],
            'ru' => [['Склад и логистика:', 'транспортные и SSCC-этикетки для паллет и коробов.'], ['Производство:', 'маркировка продукции и технические этикетки.'], ['Розничные сети:', 'печать больших объёмов этикеток в распределительном центре.']],
            'en' => [['Warehouse and logistics:', 'SSCC and shipping labels for pallets and cartons.'], ['Manufacturing:', 'product marking and technical labels.'], ['Retail chains:', 'high-volume label printing at a distribution center.']],
        ],
        'svc' => $tr(
            'Poson printerni ishlab chiqarish liniyasi yoki omborga oʻrnatadi, 1C va ombor tizimi bilan ulaydi, yorliq va ribbonni tanlashda maslahat beradi hamda profilaktik xizmatni tashkil qiladi. Kafolat davomida qurilmaga oʻz servis markazimiz xizmat koʻrsatadi.',
            'Poson установит принтер в цехе или на складе, подключит его к 1С и складской системе, поможет подобрать этикетки и риббон и организует профилактическое обслуживание. В гарантийный период устройство обслуживает наш сервисный центр.',
            'Poson installs the printer on your line or in your warehouse, connects it to 1C and your warehouse system, helps you choose labels and ribbons and arranges preventive maintenance. During warranty, our own service center looks after the device.',
        ),
    ],
    'mobile' => [
        'noun' => $tr('mobil yorliq printeri', 'мобильный принтер этикеток', 'mobile label printer'),
        'nounCap' => $tr('mobil yorliq printeri', 'Мобильный принтер этикеток', 'mobile label printer'),
        'metaNoun' => $tr('mobil printer', 'Мобильный принтер', 'mobile label printer'),
        'use' => $tr('Yorliqlarni joyida chop etish uchun.', 'Для печати этикеток прямо на месте.', 'For printing labels on the spot.'),
        'bullets' => [
            'uz' => [['Chakana savdo:', 'narxlar oʻzgarganda javon yorliqlarini savdo zalida chop etish.'], ['Ombor:', 'qabul qilish va inventarizatsiyada yorliqni joyida chop etish.'], ['Yetkazib berish va koʻchma xizmat:', 'yorliqlarni ish joyida, kompyutersiz chop etish.']],
            'ru' => [['Розница:', 'печать ценников прямо в зале при изменении цен.'], ['Склад:', 'этикетки на месте при приёмке и инвентаризации.'], ['Доставка и выездная работа:', 'печать этикеток на рабочем месте без стационарного компьютера.']],
            'en' => [['Retail:', 'print shelf labels on the shop floor when prices change.'], ['Warehouse:', 'labels printed on the spot during receiving and stocktaking.'], ['Delivery and field work:', 'label printing at the point of work without a desktop PC.']],
        ],
        'svc' => $tr(
            'Poson printerni ish qurilmalaringiz (TSD, telefon yoki kompyuter) bilan juftlaydi, shablonlarni sozlaydi va xodimlarni oʻqitadi. Kafolat davomida qurilmaga oʻz servis markazimiz xizmat koʻrsatadi.',
            'Poson подключит принтер к вашим рабочим устройствам (ТСД, телефону или компьютеру), настроит шаблоны и обучит сотрудников. В гарантийный период устройство обслуживает наш сервисный центр.',
            'Poson pairs the printer with your working devices (a mobile computer, phone or PC), sets up templates and trains your staff. During warranty, our own service center looks after the device.',
        ),
    ],
    'rfid' => [
        'noun' => $tr('RFID yorliq printeri', 'RFID-принтер этикеток', 'RFID label printer'),
        'nounCap' => $tr('RFID yorliq printeri', 'RFID-принтер этикеток', 'RFID label printer'),
        'metaNoun' => $tr('RFID printeri', 'RFID-принтер', 'RFID label printer'),
        'use' => $tr('RFID yorliqlarni kodlash va chop etish uchun.', 'Для кодирования и печати RFID-этикеток.', 'For encoding and printing RFID labels.'),
        'bullets' => [
            'uz' => [['Chakana savdo:', 'kiyim va aksessuarlar uchun RFID yorliqlari, tovarni tez inventarizatsiya qilish.'], ['Ombor:', 'qutilar va konteynerlar uchun RFID yorliqlarini kodlash.'], ['Ishlab chiqarish:', 'asbob va aktivlarni RFID orqali hisobga olish.']],
            'ru' => [['Розница:', 'RFID-этикетки для одежды и аксессуаров, быстрая инвентаризация товара.'], ['Склад:', 'кодирование RFID-этикеток для коробов и контейнеров.'], ['Производство:', 'учёт инструмента и активов с помощью RFID.']],
            'en' => [['Retail:', 'RFID labels for apparel and accessories and faster stock counts.'], ['Warehouse:', 'encoding RFID labels for cartons and containers.'], ['Manufacturing:', 'tracking tools and assets with RFID.']],
        ],
        'svc' => $tr(
            'Poson printerni oʻrnatadi, RFID yorliqlari bilan ishlashni sozlaydi, kodlash shablonlarini tayyorlaydi va 1C yoki ombor tizimi bilan ulaydi. Kafolat davomida qurilmaga oʻz servis markazimiz xizmat koʻrsatadi.',
            'Poson установит принтер, настроит работу с RFID-этикетками, подготовит шаблоны кодирования и подключит его к 1С или складской системе. В гарантийный период устройство обслуживает наш сервисный центр.',
            'Poson installs the printer, sets up work with your RFID labels, prepares encoding templates and connects it to 1C or your warehouse system. During warranty, our own service center looks after the device.',
        ),
    ],
    'part' => [
        'bullets' => [
            'uz' => [['Servis:', 'chiziqlar, oqargan joylar yoki chop etilmay qolgan nuqtalar paydo boʻlganda kallakni almashtirish.'], ['Moslik:', 'kallak printer modeliga va aniqlikka (dpi) mos tanlanadi.'], ['Ishonchlilik:', 'almashtirishdan keyin chop etish sifati tekshiriladi va sozlanadi.']],
            'ru' => [['Сервис:', 'замена головки при появлении полос, светлых участков или непропечатанных точек.'], ['Совместимость:', 'головка подбирается под модель принтера и разрешение (dpi).'], ['Надёжность:', 'после замены проверяется и настраивается качество печати.']],
            'en' => [['Service:', 'replace the printhead when streaks, faded areas or missing dots appear.'], ['Compatibility:', 'the printhead is matched to the printer model and resolution (dpi).'], ['Reliability:', 'print quality is checked and adjusted after the replacement.']],
        ],
        'svc' => $tr(
            'Poson muhandislari kallakning printer modeliga mosligini tekshiradi, almashtiradi va chop etishni sozlaydi. Buyurtma berishdan oldin mutaxassislarimiz bilan maslahatlashishingiz mumkin.',
            'Инженеры Poson проверят совместимость головки с вашей моделью принтера, заменят её и настроят печать. Перед заказом можно проконсультироваться с нашими специалистами.',
            'Poson engineers check that the printhead fits your printer model, replace it and tune the print. You can consult our specialists before ordering.',
        ),
    ],
];

$methodText = [
    'both' => $tr('termotransfer va toʻgʻridan-toʻgʻri termo chop etish', 'термотрансферная и прямая термопечать', 'thermal transfer and direct thermal printing'),
    'dt' => $tr('toʻgʻridan-toʻgʻri termo chop etish (ribbonsiz)', 'прямая термопечать (без риббона)', 'direct thermal printing (no ribbon)'),
    'tt' => $tr('termotransfer chop etish (ribbon bilan)', 'термотрансферная печать (с риббоном)', 'thermal transfer printing (with ribbon)'),
];

$specTpl = [
    'dpi' => $tr('%s dpi aniqlik', 'разрешение %s dpi', '%s dpi resolution'),
    'width' => $tr('chop etish kengligi %s mm gacha', 'ширина печати до %s мм', 'print width up to %s mm'),
    'speed' => $tr('tezlik %s mm/s gacha', 'скорость до %s мм/с', 'speed up to %s mm/s'),
    'ribbon' => $tr('ribbon uzunligi %s m gacha', 'риббон длиной до %s м', 'ribbon up to %s m'),
    'roll' => $tr('rulon tashqi diametri %s mm gacha', 'рулон диаметром до %s мм', 'rolls up to %s mm in diameter'),
    'if' => $tr('interfeyslar: %s', 'интерфейсы: %s', 'interfaces: %s'),
    'rfid' => $tr('UHF RFID (EPC Gen2)', 'UHF RFID (EPC Gen2)', 'UHF RFID (EPC Gen2)'),
];

$specPrefix = $tr('Asosiy parametrlar: ', 'Ключевые параметры: ', 'Key specs: ');

$specLine = static function (array $s, int $dpi, string $l) use ($dt, $tt, $methodText, $specTpl, $fmt): string {
    $parts = [];
    $m = $s['print_method'] ?? [];
    $hasDt = in_array($dt, $m, true);
    $hasTt = in_array($tt, $m, true);
    if ($hasDt || $hasTt) {
        $parts[] = $methodText[$hasDt && $hasTt ? 'both' : ($hasDt ? 'dt' : 'tt')][$l];
    }
    $parts[] = sprintf($specTpl['dpi'][$l], $dpi);
    if (isset($s['print_width'])) {
        $parts[] = sprintf($specTpl['width'][$l], $fmt($s['print_width'], $l));
    }
    if (isset($s['print_speed'])) {
        $parts[] = sprintf($specTpl['speed'][$l], $fmt($s['print_speed'], $l));
    }
    if (isset($s['max_ribbon_length'])) {
        $parts[] = sprintf($specTpl['ribbon'][$l], $fmt($s['max_ribbon_length'], $l));
    }
    if (isset($s['max_roll_diameter'])) {
        $parts[] = sprintf($specTpl['roll'][$l], $fmt($s['max_roll_diameter'], $l));
    }
    if (isset($s['rfid_frequency'])) {
        $parts[] = $specTpl['rfid'][$l];
    }
    if (isset($s['interface'])) {
        $parts[] = sprintf($specTpl['if'][$l], implode(', ', $s['interface']));
    }

    return implode(', ', $parts);
};

$keySpecs = ['print_method', 'resolution', 'print_width', 'interface'];

$build = static function (array $r) use ($kinds, $specLine, $specPrefix, $keySpecs, $fmt): array {
    $langs = ['uz', 'ru', 'en'];
    $kind = $kinds[$r['kind']];
    $isPart = $r['kind'] === 'part';
    $raw = $r['specs'];
    $dpi = $r['dpi'];
    $width = $raw['print_width'] ?? null;
    $variant = $r['variant'] ?? null;
    $brandModel = $r['brand'].' '.$r['model'];
    $suffix = $variant === null ? '' : ', '.$variant;

    $name = [];
    $short = [];
    $desc = [];
    $metaTitle = [];
    $metaDesc = [];

    foreach ($langs as $l) {
        if ($isPart) {
            $partName = $r['partName'][$l];
            $name[$l] = $partName;
            $short[$l] = match ($l) {
                'uz' => $partName.': almashtirish chop etish sifatini tiklaydi. Moslik va oʻrnatish — Poson muhandislari.',
                'ru' => $partName.': замена возвращает качество печати. Подбор и установка — инженеры Poson.',
                'en' => $partName.': replacement restores print quality. Fit check and installation by Poson engineers.',
            };
            $strong = $partName;
            $metaTitle[$l] = $partName.' — Poson';
            $metaDesc[$l] = match ($l) {
                'uz' => $partName.'. Moslik tekshiriladi, oʻrnatish va sozlash Poson servis markazida.',
                'ru' => $partName.'. Проверка совместимости, установка и настройка в сервисном центре Poson.',
                'en' => $partName.'. Compatibility check, installation and setup at the Poson service center.',
            };
            $specHtml = '';
        } else {
            $noun = $kind['noun'][$l];
            $nounCap = $kind['nounCap'][$l];
            $w = $width === null ? null : $fmt($width, $l);

            $name[$l] = match ($l) {
                'uz' => $brandModel.' '.$noun.', '.$dpi.' dpi'.$suffix,
                'ru' => $nounCap.' '.$brandModel.', '.$dpi.' dpi'.$suffix,
                'en' => $brandModel.' '.$noun.', '.$dpi.' dpi'.$suffix,
            };
            $short[$l] = match ($l) {
                'uz' => $brandModel.' — '.$dpi.' dpi '.$noun.($w ? ', '.$w.' mm gacha' : '').'. '.$kind['use'][$l],
                'ru' => $brandModel.' — '.$noun.', '.$dpi.' dpi'.($w ? ', до '.$w.' мм' : '').'. '.$kind['use'][$l],
                'en' => $brandModel.' — '.$dpi.' dpi '.$noun.($w ? ', up to '.$w.' mm wide' : '').'. '.$kind['use'][$l],
            };
            $strong = $brandModel;
            $metaTitle[$l] = match ($l) {
                'uz' => $brandModel.' '.$kind['metaNoun'][$l].' '.$dpi.' dpi'.$suffix.' — Poson',
                'ru' => $kind['metaNoun'][$l].' '.$brandModel.' '.$dpi.' dpi'.$suffix.' — Poson',
                'en' => $brandModel.' '.$kind['metaNoun'][$l].' '.$dpi.' dpi'.$suffix.' — Poson',
            };
            $metaDesc[$l] = match ($l) {
                'uz' => $brandModel.': '.$dpi.' dpi '.$noun.($w ? ', '.$w.' mm' : '').'. Oʻrnatish, sozlash, 1C bilan ulash va kafolat xizmati — Poson.',
                'ru' => $brandModel.': '.$noun.', '.$dpi.' dpi'.($w ? ', до '.$w.' мм' : '').'. Установка, настройка, интеграция с 1С и гарантийный сервис — Poson.',
                'en' => $brandModel.': '.$dpi.' dpi '.$noun.($w ? ', up to '.$w.' mm' : '').'. Installation, setup, 1C integration and warranty service from Poson.',
            };
            $specHtml = '<li>'.$specPrefix[$l].$specLine($raw, $dpi, $l).'.</li>';
        }

        $items = '';
        foreach ($kind['bullets'][$l] as [$label, $text]) {
            $items .= '<li><strong>'.$label.'</strong> '.$text.'</li>';
        }
        $desc[$l] = '<p><strong>'.$strong.'</strong> — '.$r['lead'][$l].'</p><ul>'.$items.$specHtml.'</ul><p>'.$kind['svc'][$l].'</p>';
    }

    $specs = [];
    foreach ($raw as $code => $value) {
        $specs[$code] = in_array($code, $keySpecs, true) ? ['value' => $value, 'key' => true] : $value;
    }

    $related = [];
    foreach ($r['rel'] ?? [] as [$sku, $type]) {
        $related[] = ['sku' => $sku, 'type' => $type];
    }

    return [
        'sku' => $r['sku'],
        'model' => $r['model'],
        'brand' => $r['brand'],
        'category' => $r['category'],
        'type' => 'hardware',
        'unit' => 'piece',
        'min_qty' => 1,
        'sale_mode' => $isPart ? ($r['price'] === null ? 'quote' : 'cart') : 'both',
        'stock_status' => 'on_order',
        'warranty_months' => $isPart ? null : 12,
        'badge' => null,
        'is_featured' => false,
        'price' => $r['price'],
        'price_dealer' => null,
        'price_wholesale' => null,
        'name' => $name,
        'short_description' => $short,
        'description' => $desc,
        'meta_title' => $metaTitle,
        'meta_description' => $metaDesc,
        'specs' => $specs,
        'related' => $related,
        'has_missing_specs' => $r['missing'],
    ];
};

$rows = [

    // ─── Desktop label printers ─────────────────────────────────────────────
    [
        'sku' => '3', 'model' => 'XP-365B', 'brand' => 'Xprinter', 'category' => 'desktop-label-printers', 'kind' => 'desktop', 'dpi' => 203, 'price' => 665500, 'variant' => null,
        'specs' => ['print_method' => [$dt], 'resolution' => '203', 'interface' => ['USB'], 'print_speed' => 127],
        'missing' => true,
        'lead' => $tr(
            'ixcham 203 dpi yorliq printeri. U toʻgʻridan-toʻgʻri termo usulida ishlaydi, shuning uchun ribbon kerak emas; 82 mm gacha kenglikdagi yorliqlar uchun mos va USB orqali ulanadi.',
            'компактный принтер этикеток 203 dpi с прямой термопечатью, поэтому риббон не нужен. Подходит для этикеток шириной до 82 мм и подключается по USB.',
            'a compact 203 dpi label printer that uses direct thermal printing, so no ribbon is needed. It handles labels up to 82 mm wide and connects over USB.',
        ),
    ],
    [
        'sku' => '70', 'model' => 'XP-365B', 'brand' => 'Xprinter', 'category' => 'desktop-label-printers', 'kind' => 'desktop', 'dpi' => 203, 'price' => 665500, 'variant' => null,
        'specs' => ['print_method' => [$dt], 'resolution' => '203', 'interface' => ['USB'], 'print_speed' => 127],
        'missing' => true,
        'lead' => $tr(
            'ixcham 203 dpi yorliq printeri. U toʻgʻridan-toʻgʻri termo usulida ishlaydi, shuning uchun ribbon kerak emas; 82 mm gacha kenglikdagi yorliqlar uchun mos va USB orqali ulanadi.',
            'компактный принтер этикеток 203 dpi с прямой термопечатью, поэтому риббон не нужен. Подходит для этикеток шириной до 82 мм и подключается по USB.',
            'a compact 203 dpi label printer that uses direct thermal printing, so no ribbon is needed. It handles labels up to 82 mm wide and connects over USB.',
        ),
        'rel' => [['233', 'alternative']],
    ],
    [
        'sku' => '233', 'model' => 'XP-365B', 'brand' => 'Xprinter', 'category' => 'desktop-label-printers', 'kind' => 'desktop', 'dpi' => 203, 'price' => 847000, 'variant' => 'Bluetooth',
        'specs' => ['print_method' => [$dt], 'resolution' => '203', 'interface' => ['USB', 'Bluetooth'], 'print_speed' => 127],
        'missing' => true,
        'lead' => $tr(
            'USB bilan birga Bluetooth aloqasi ham bor 203 dpi yorliq printeri. Toʻgʻridan-toʻgʻri termo usulida ishlaydi, ribbon kerak emas; 82 mm gacha kenglikdagi yorliqlar uchun mos.',
            'принтер этикеток 203 dpi с USB и дополнительным подключением по Bluetooth. Печатает методом прямой термопечати без риббона, подходит для этикеток шириной до 82 мм.',
            'a 203 dpi label printer with Bluetooth in addition to USB. It uses direct thermal printing with no ribbon and handles labels up to 82 mm wide.',
        ),
        'rel' => [['70', 'alternative']],
    ],
    [
        'sku' => '71', 'model' => 'XP-350B', 'brand' => 'Xprinter', 'category' => 'desktop-label-printers', 'kind' => 'desktop', 'dpi' => 203, 'price' => 847000, 'variant' => null,
        'specs' => ['print_method' => [$dt], 'resolution' => '203', 'interface' => ['USB', 'RS232']],
        'missing' => true,
        'lead' => $tr(
            '203 dpi yorliq printeri, USB va ketma-ket (RS232) port orqali ulanadi — eski kassa va ombor dasturlari bilan ishlash uchun qulay. Toʻgʻridan-toʻgʻri termo usulida ribbonsiz chop etadi.',
            'принтер этикеток 203 dpi с подключением по USB и последовательному порту (RS232) — удобен для работы со старыми кассовыми и складскими программами. Печатает методом прямой термопечати, без риббона.',
            'a 203 dpi label printer that connects over USB and a serial (RS232) port, which suits older POS and warehouse software. It prints by direct thermal, with no ribbon.',
        ),
    ],
    [
        'sku' => '72', 'model' => 'XP-421B', 'brand' => 'Xprinter', 'category' => 'desktop-label-printers', 'kind' => 'desktop', 'dpi' => 203, 'price' => 1089000, 'variant' => 'Ethernet',
        'specs' => ['resolution' => '203', 'interface' => ['USB', 'Ethernet']],
        'missing' => true,
        'lead' => $tr(
            '203 dpi yorliq printeri, USB va Ethernet interfeysiga ega — tarmoq orqali bir nechta ish joyiga umumiy printer sifatida ulash mumkin. Chop etish kengligi va usulini Poson mutaxassislari buyurtma oldidan tasdiqlaydi.',
            'принтер этикеток 203 dpi с интерфейсами USB и Ethernet — можно подключить по сети как общий принтер для нескольких рабочих мест. Ширину и способ печати наши специалисты подтвердят перед заказом.',
            'a 203 dpi label printer with USB and Ethernet, so it can be shared over the network between several workstations. Our specialists confirm print width and method before you order.',
        ),
        'rel' => [['230', 'alternative']],
    ],
    [
        'sku' => '230', 'model' => 'XP-421B', 'brand' => 'Xprinter', 'category' => 'desktop-label-printers', 'kind' => 'desktop', 'dpi' => 203, 'price' => 1149500, 'variant' => 'Wi-Fi',
        'specs' => ['resolution' => '203', 'interface' => ['USB', 'Wi-Fi']],
        'missing' => true,
        'lead' => $tr(
            '203 dpi yorliq printeri, USB va Wi-Fi orqali ulanadi — printerni kabelsiz tarmoqqa ulash mumkin. Chop etish kengligi va usulini Poson mutaxassislari buyurtma oldidan tasdiqlaydi.',
            'принтер этикеток 203 dpi с подключением по USB и Wi-Fi — принтер можно включить в беспроводную сеть. Ширину и способ печати наши специалисты подтвердят перед заказом.',
            'a 203 dpi label printer that connects over USB and Wi-Fi, so it can join a wireless network. Our specialists confirm print width and method before you order.',
        ),
        'rel' => [['72', 'alternative']],
    ],
    [
        'sku' => '197', 'model' => 'XP-T451E', 'brand' => 'Xprinter', 'category' => 'desktop-label-printers', 'kind' => 'desktop', 'dpi' => 300, 'price' => 2400000, 'variant' => null,
        'specs' => ['resolution' => '300', 'interface' => ['USB']],
        'missing' => true,
        'lead' => $tr(
            '300 dpi aniqlikdagi yorliq printeri (USB): mayda matn, kichik shtrix-kod va QR kodlarni aniqroq chop etadi. Chop etish kengligi va usulini Poson mutaxassislari buyurtma oldidan tasdiqlaydi.',
            'принтер этикеток с разрешением 300 dpi (USB): чётче печатает мелкий текст, небольшие штрих-коды и QR-коды. Ширину и способ печати наши специалисты подтвердят перед заказом.',
            'a 300 dpi label printer (USB) that renders fine text, small barcodes and QR codes more sharply. Our specialists confirm print width and method before you order.',
        ),
    ],
    [
        'sku' => '148', 'model' => 'GP-3120TUD', 'brand' => 'Gainscha', 'category' => 'desktop-label-printers', 'kind' => 'desktop', 'dpi' => 203, 'price' => 780000, 'variant' => null,
        'specs' => ['resolution' => '203', 'interface' => ['USB']],
        'missing' => true,
        'lead' => $tr(
            '203 dpi yorliq printeri, USB orqali ulanadi, 3 dyuymli sinfga mansub. Chop etish usuli va aniq kengligini Poson mutaxassislari buyurtma oldidan tasdiqlaydi.',
            'принтер этикеток 203 dpi с подключением по USB, относится к 3-дюймовому классу. Способ печати и точную ширину наши специалисты подтвердят перед заказом.',
            'a 203 dpi label printer with a USB connection, in the 3-inch class. Our specialists confirm the print method and exact width before you order.',
        ),
    ],
    [
        'sku' => '10', 'model' => 'GS-2408DC', 'brand' => 'Gainscha', 'category' => 'desktop-label-printers', 'kind' => 'desktop', 'dpi' => 203, 'price' => 1331000, 'variant' => null,
        'specs' => ['print_method' => [$dt], 'resolution' => '203', 'print_width' => 108, 'interface' => ['USB']],
        'missing' => false,
        'lead' => $tr(
            'toʻgʻridan-toʻgʻri termo usulidagi 203 dpi yorliq printeri: 108 mm gacha kenglikda chop etadi va USB orqali ulanadi. Ribbon kerak emas, sarf materiali faqat yorliq.',
            'принтер этикеток 203 dpi с прямой термопечатью: печатает шириной до 108 мм и подключается по USB. Риббон не нужен, из расходников — только этикетки.',
            'a 203 dpi direct thermal label printer that prints up to 108 mm wide and connects over USB. No ribbon is needed, so labels are the only consumable.',
        ),
    ],
    [
        'sku' => '11', 'model' => 'GS-3405T', 'brand' => 'Gainscha', 'category' => 'desktop-label-printers', 'kind' => 'desktop', 'dpi' => 300, 'price' => 2541000, 'variant' => null,
        'specs' => ['resolution' => '300', 'interface' => ['USB', 'Ethernet', 'RS232']],
        'missing' => true,
        'lead' => $tr(
            '300 dpi yorliq printeri: USB, USB Host, Ethernet va ketma-ket port bilan jihozlangan, shuning uchun kassa, ombor va tarmoq muhitiga oson ulanadi. Chop etish kengligi va usulini Poson mutaxassislari buyurtma oldidan tasdiqlaydi.',
            'принтер этикеток 300 dpi с интерфейсами USB, USB Host, Ethernet и последовательным портом — легко встраивается в кассовую, складскую и сетевую среду. Ширину и способ печати наши специалисты подтвердят перед заказом.',
            'a 300 dpi label printer with USB, USB Host, Ethernet and a serial port, so it fits easily into POS, warehouse and network setups. Our specialists confirm print width and method before you order.',
        ),
    ],
    [
        'sku' => '17', 'model' => 'LPQ58', 'brand' => 'HPRT', 'category' => 'desktop-label-printers', 'kind' => 'desktop', 'dpi' => 203, 'price' => 968000, 'variant' => null,
        'specs' => ['print_method' => [$dt], 'resolution' => '203', 'print_width' => 56, 'interface' => ['USB'], 'print_speed' => 76],
        'missing' => false,
        'lead' => $tr(
            'ixcham 2 dyuymli toʻgʻridan-toʻgʻri termo printer. Yorliq eni sozlanadi, chop etish kallagini tez almashtirish mumkin; kichik yorliqlar uchun ideal.',
            'компактный 2-дюймовый принтер с прямой термопечатью. Ширина этикетки регулируется, печатающую головку можно быстро заменить; удобен для небольших этикеток.',
            'a compact 2-inch direct thermal printer. The label width is adjustable and the printhead can be swapped quickly, which suits small labels well.',
        ),
    ],
    [
        'sku' => '20', 'model' => 'SL32', 'brand' => 'HPRT', 'category' => 'desktop-label-printers', 'kind' => 'desktop', 'dpi' => 203, 'price' => 968000, 'variant' => null,
        'specs' => ['print_method' => [$dt], 'resolution' => '203', 'print_width' => 72, 'interface' => ['USB'], 'print_speed' => 180, 'max_roll_diameter' => 100],
        'missing' => false,
        'lead' => $tr(
            '3 dyuymli toʻgʻridan-toʻgʻri termo yorliq printeri: tez ishlaydi, 25 dan 80 mm gacha kenglikdagi yorliqlar bilan ishlaydi va ribbon talab qilmaydi.',
            '3-дюймовый принтер этикеток с прямой термопечатью: работает быстро, поддерживает этикетки шириной от 25 до 80 мм и не требует риббона.',
            'a 3-inch direct thermal label printer: fast, works with labels from 25 to 80 mm wide and needs no ribbon.',
        ),
    ],
    [
        'sku' => '18', 'model' => 'PC300T', 'brand' => 'Honeywell', 'category' => 'desktop-label-printers', 'kind' => 'desktop', 'dpi' => 203, 'price' => 2117500, 'variant' => null,
        'specs' => ['print_method' => [$tt], 'resolution' => '203', 'print_width' => 104, 'interface' => ['USB'], 'print_speed' => 152],
        'missing' => false,
        'lead' => $tr(
            'Honeywell ning 4 dyuymli termotransfer stol usti printeri: ribbon bilan uzoq saqlanadigan yorliqlar chop etadi, USB orqali ulanadi, 203 dpi.',
            '4-дюймовый термотрансферный настольный принтер Honeywell: печатает через риббон стойкие этикетки, подключается по USB, 203 dpi.',
            'Honeywell 4-inch thermal transfer desktop printer that prints durable labels through a ribbon and connects over USB at 203 dpi.',
        ),
        'rel' => [['19', 'alternative']],
    ],
    [
        'sku' => '19', 'model' => 'PC300T', 'brand' => 'Honeywell', 'category' => 'desktop-label-printers', 'kind' => 'desktop', 'dpi' => 203, 'price' => 3025000, 'variant' => 'Ethernet',
        'specs' => ['print_method' => [$tt], 'resolution' => '203', 'print_width' => 104, 'interface' => ['USB', 'Ethernet'], 'print_speed' => 152],
        'missing' => false,
        'lead' => $tr(
            'USB bilan birga Ethernet portli Honeywell termotransfer printeri: 4 dyuymli, 203 dpi. Tarmoqqa ulanib, bir nechta ish joyiga xizmat qiladi.',
            'термотрансферный принтер Honeywell с портом Ethernet помимо USB: 4 дюйма, 203 dpi. Подключается к сети и обслуживает несколько рабочих мест.',
            'Honeywell thermal transfer printer with an Ethernet port in addition to USB: 4-inch class, 203 dpi. It joins the network and serves several workstations.',
        ),
        'rel' => [['18', 'alternative']],
    ],
    [
        'sku' => '21', 'model' => 'TE200', 'brand' => 'TSC', 'category' => 'desktop-label-printers', 'kind' => 'desktop', 'dpi' => 203, 'price' => 2783000, 'variant' => null,
        'specs' => ['print_method' => $both, 'resolution' => '203', 'print_width' => 108, 'interface' => ['USB'], 'print_speed' => 152, 'max_ribbon_length' => 300],
        'missing' => false,
        'lead' => $tr(
            'TSC TE200 seriyasining 4 dyuymli stol usti printeri: ribbon bilan ham, ribbonsiz ham ishlaydi, kundalik yorliq chop etish uchun ishonchli va sozlashga oson.',
            'настольный 4-дюймовый принтер серии TSC TE200: работает и с риббоном, и без него, надёжен в ежедневной печати этикеток и прост в настройке.',
            'a 4-inch desktop printer from the TSC TE200 series that works with or without a ribbon, reliable for daily label printing and easy to set up.',
        ),
    ],
    [
        'sku' => '261', 'model' => 'TE310', 'brand' => 'TSC', 'category' => 'desktop-label-printers', 'kind' => 'desktop', 'dpi' => 300, 'price' => 6500000, 'variant' => null,
        'specs' => ['print_method' => $both, 'resolution' => '300', 'print_width' => 105.6, 'interface' => ['USB', 'Ethernet', 'RS232'], 'print_speed' => 127, 'max_ribbon_length' => 300],
        'missing' => false,
        'lead' => $tr(
            '300 dpi aniqlikdagi 4 dyuymli stol usti printeri: USB, USB Host, Ethernet va RS232 interfeyslari bilan. Mayda matn va zich shtrix-kodlar uchun yuqori aniqlik.',
            'настольный 4-дюймовый принтер 300 dpi с интерфейсами USB, USB Host, Ethernet и RS232. Высокая чёткость для мелкого текста и плотных штрих-кодов.',
            'a 4-inch desktop printer at 300 dpi with USB, USB Host, Ethernet and RS232 interfaces. The high resolution suits fine text and dense barcodes.',
        ),
    ],
    [
        'sku' => '170', 'model' => 'TL241', 'brand' => 'TSC', 'category' => 'desktop-label-printers', 'kind' => 'desktop', 'dpi' => 203, 'price' => 2900000, 'variant' => null,
        'specs' => ['print_method' => $both, 'resolution' => '203', 'interface' => ['USB', 'Ethernet'], 'print_speed' => 152, 'max_ribbon_length' => 300],
        'missing' => true,
        'lead' => $tr(
            'TSC TL seriyasining 4 dyuymli stol usti printeri: termotransfer va toʻgʻridan-toʻgʻri termo chop etish, USB va Ethernet orqali ulanadi.',
            'настольный 4-дюймовый принтер серии TSC TL: термотрансферная и прямая термопечать, подключение по USB и Ethernet.',
            'a 4-inch desktop printer from the TSC TL series with thermal transfer and direct thermal printing, connecting over USB and Ethernet.',
        ),
    ],
    [
        'sku' => '226', 'model' => 'XD5-43t', 'brand' => 'Bixolon', 'category' => 'desktop-label-printers', 'kind' => 'desktop', 'dpi' => 300, 'price' => 5416033, 'variant' => null,
        'specs' => ['print_method' => $both, 'resolution' => '300', 'print_width' => 105.7, 'interface' => ['USB', 'Ethernet'], 'print_speed' => 102, 'max_ribbon_length' => 300],
        'missing' => false,
        'lead' => $tr(
            'Bixolon ning 300 dpi stol usti printeri: termotransfer va toʻgʻridan-toʻgʻri termo chop etadi, USB va Ethernet orqali ulanadi, ZPL va EPL buyruq tillari bilan ishlashga moslashgan.',
            'настольный принтер Bixolon 300 dpi: термотрансферная и прямая термопечать, подключение по USB и Ethernet, совместим с языками команд ZPL и EPL.',
            'Bixolon 300 dpi desktop printer with thermal transfer and direct thermal printing, USB and Ethernet connectivity and ZPL and EPL command language support.',
        ),
    ],
    [
        'sku' => '225', 'model' => 'ZD888T', 'brand' => 'Zebra', 'category' => 'desktop-label-printers', 'kind' => 'desktop', 'dpi' => 203, 'price' => 3645000, 'variant' => null,
        'specs' => ['print_method' => $both, 'resolution' => '203', 'print_width' => 104, 'interface' => ['USB', 'Ethernet'], 'print_speed' => 152, 'max_roll_diameter' => 127, 'max_ribbon_length' => 300],
        'missing' => false,
        'lead' => $tr(
            'Zebra ning 4 dyuymli stol usti printeri: ZPL buyruq tilini qoʻllaydi, ribbon bilan ham, ribbonsiz ham ishlaydi va USB hamda Ethernet orqali ulanadi.',
            '4-дюймовый настольный принтер Zebra: поддерживает язык команд ZPL, работает с риббоном и без него, подключается по USB и Ethernet.',
            'a 4-inch Zebra desktop printer that supports ZPL, works with or without a ribbon and connects over USB and Ethernet.',
        ),
    ],
    [
        'sku' => '4', 'model' => 'CR888T', 'brand' => 'Zebra', 'category' => 'desktop-label-printers', 'kind' => 'desktop', 'dpi' => 203, 'price' => 2662000, 'variant' => null,
        'specs' => ['resolution' => '203', 'interface' => ['USB']],
        'missing' => true,
        'lead' => $tr(
            'Zebra yorliq printeri, 203 dpi, USB orqali ulanadi. Modelning aniq texnik parametrlarini (chop etish usuli va kengligi) Poson mutaxassislari buyurtma oldidan tasdiqlaydi.',
            'принтер этикеток Zebra, 203 dpi, подключение по USB. Точные технические параметры модели (способ и ширину печати) наши специалисты подтвердят перед заказом.',
            'a Zebra label printer, 203 dpi, connecting over USB. Our specialists confirm the exact technical details (print method and width) before you order.',
        ),
    ],
    [
        'sku' => '5', 'model' => 'CR888TA', 'brand' => 'Zebra', 'category' => 'desktop-label-printers', 'kind' => 'desktop', 'dpi' => 300, 'price' => 3267000, 'variant' => null,
        'specs' => ['resolution' => '300', 'interface' => ['USB']],
        'missing' => true,
        'lead' => $tr(
            'Zebra yorliq printeri, 300 dpi, USB orqali ulanadi — mayda matn va zich shtrix-kodlar uchun yuqori aniqlik. Aniq texnik parametrlarni Poson mutaxassislari buyurtma oldidan tasdiqlaydi.',
            'принтер этикеток Zebra, 300 dpi, подключение по USB — высокая чёткость для мелкого текста и плотных штрих-кодов. Точные технические параметры наши специалисты подтвердят перед заказом.',
            'a Zebra label printer, 300 dpi, connecting over USB. The high resolution suits fine text and dense barcodes. Our specialists confirm the exact technical details before you order.',
        ),
    ],
    [
        'sku' => '9', 'model' => 'G500', 'brand' => 'Godex', 'category' => 'desktop-label-printers', 'kind' => 'desktop', 'dpi' => 203, 'price' => 2200000, 'variant' => null,
        'specs' => ['print_method' => $both, 'resolution' => '203', 'print_width' => 108, 'interface' => ['USB'], 'print_speed' => 127, 'max_ribbon_length' => 300],
        'missing' => false,
        'lead' => $tr(
            'universal 4 dyuymli stol usti yorliq printeri: ribbon bilan ham, ribbonsiz ham ishlaydi, 300 m gacha ribbon sigʻadi va USB orqali ulanadi.',
            'универсальный 4-дюймовый настольный принтер этикеток: работает с риббоном и без него, вмещает риббон до 300 м и подключается по USB.',
            'a versatile 4-inch desktop label printer that works with or without a ribbon, takes ribbons up to 300 m and connects over USB.',
        ),
        'rel' => [['199', 'accessory']],
    ],
    [
        'sku' => '55', 'model' => 'G530', 'brand' => 'Godex', 'category' => 'desktop-label-printers', 'kind' => 'desktop', 'dpi' => 300, 'price' => 3120000, 'variant' => null,
        'specs' => ['print_method' => $both, 'resolution' => '300', 'print_width' => 105.7, 'interface' => ['USB'], 'print_speed' => 102, 'max_ribbon_length' => 300],
        'missing' => false,
        'lead' => $tr(
            'G500 seriyasining 300 dpi varianti: mayda yozuv va kichik shtrix-kodlar uchun aniqroq chop etadi, ribbon bilan ham, ribbonsiz ham ishlaydi.',
            '300 dpi вариант серии G500: чётче печатает мелкий текст и небольшие штрих-коды, работает с риббоном и без него.',
            'the 300 dpi version of the G500 series: it renders fine text and small barcodes more sharply and works with or without a ribbon.',
        ),
        'rel' => [['200', 'accessory']],
    ],
    [
        'sku' => '154', 'model' => 'DA220', 'brand' => 'TSC', 'category' => 'desktop-label-printers', 'kind' => 'desktop', 'dpi' => 203, 'price' => 3025000, 'variant' => null,
        'specs' => ['print_method' => [$dt], 'resolution' => '203', 'print_width' => 108, 'interface' => ['USB', 'Ethernet'], 'print_speed' => 152],
        'missing' => false,
        'lead' => $tr(
            'TSC DA seriyasining toʻgʻridan-toʻgʻri termo printeri: ribbon kerak emas, 108 mm gacha kenglikda chop etadi va USB hamda Ethernet orqali ulanadi.',
            'принтер прямой термопечати серии TSC DA: риббон не нужен, печатает шириной до 108 мм, подключается по USB и Ethernet.',
            'a direct thermal printer from the TSC DA series: no ribbon is needed, it prints up to 108 mm wide and connects over USB and Ethernet.',
        ),
    ],

    // ─── Industrial label printers ──────────────────────────────────────────
    [
        'sku' => '51', 'model' => 'ZT411', 'brand' => 'Zebra', 'category' => 'industrial-label-printers', 'kind' => 'industrial', 'dpi' => 300, 'price' => 22500000, 'variant' => null,
        'specs' => ['print_method' => $both, 'resolution' => '300', 'print_width' => 104, 'interface' => ['USB', 'Ethernet', 'RS232'], 'print_speed' => 305, 'max_roll_diameter' => 203, 'max_ribbon_length' => 450],
        'missing' => false,
        'lead' => $tr(
            'Zebra ning 4 dyuymli sanoat printeri: metall korpusda, katta rulon va uzun ribbon bilan uzluksiz ishlash uchun moʻljallangan. 300 dpi aniqlik mayda yozuvni ham aniq chop etadi.',
            '4-дюймовый промышленный принтер Zebra в металлическом корпусе, рассчитан на непрерывную работу с большими рулонами и длинным риббоном. Разрешение 300 dpi чётко передаёт даже мелкий текст.',
            'a 4-inch industrial Zebra printer in a metal housing, built for continuous work with large rolls and long ribbons. The 300 dpi resolution keeps even fine text sharp.',
        ),
    ],
    [
        'sku' => '171', 'model' => 'MB241', 'brand' => 'TSC', 'category' => 'industrial-label-printers', 'kind' => 'industrial', 'dpi' => 203, 'price' => 9000000, 'variant' => null,
        'specs' => ['print_method' => $both, 'resolution' => '203', 'print_width' => 107, 'interface' => ['USB', 'Ethernet', 'RS232'], 'print_speed' => 305, 'max_roll_diameter' => 203, 'max_ribbon_length' => 450],
        'missing' => false,
        'lead' => $tr(
            'TSC MB240 seriyasining sanoat printeri: 203 dpi, yuqori tezlik, katta rulon va 450 m gacha ribbon. USB, USB Host, Ethernet va RS232 interfeyslari bilan.',
            'промышленный принтер серии TSC MB240: 203 dpi, высокая скорость, большой рулон и риббон до 450 м. Интерфейсы USB, USB Host, Ethernet и RS232.',
            'an industrial printer from the TSC MB240 series: 203 dpi, high speed, large rolls and ribbons up to 450 m. Interfaces include USB, USB Host, Ethernet and RS232.',
        ),
    ],
    [
        'sku' => '155', 'model' => 'MB341T', 'brand' => 'TSC', 'category' => 'industrial-label-printers', 'kind' => 'industrial', 'dpi' => 300, 'price' => 13310000, 'variant' => null,
        'specs' => ['print_method' => $both, 'resolution' => '300', 'print_width' => 105.7, 'interface' => ['USB', 'Ethernet', 'RS232'], 'max_roll_diameter' => 203, 'max_ribbon_length' => 450],
        'missing' => false,
        'lead' => $tr(
            'sensorli LCD ekranli 300 dpi sanoat printeri: sozlamalarni ekrandan oʻzgartirish mumkin, rulon diametri 203 mm gacha, ribbon 450 m gacha. USB, USB Host, Ethernet va RS232.',
            'промышленный принтер 300 dpi с сенсорным LCD-экраном: настройки меняются прямо на экране, рулон до 203 мм, риббон до 450 м. Интерфейсы USB, USB Host, Ethernet и RS232.',
            'a 300 dpi industrial printer with a touch LCD, so settings are changed on screen. It takes rolls up to 203 mm and ribbons up to 450 m, with USB, USB Host, Ethernet and RS232.',
        ),
    ],
    [
        'sku' => '178', 'model' => 'MH341T', 'brand' => 'TSC', 'category' => 'industrial-label-printers', 'kind' => 'industrial', 'dpi' => 300, 'price' => 18500000, 'variant' => null,
        'specs' => ['print_method' => $both, 'resolution' => '300', 'print_width' => 104, 'interface' => ['USB', 'Ethernet', 'RS232'], 'print_speed' => 305, 'max_roll_diameter' => 203, 'max_ribbon_length' => 600],
        'missing' => false,
        'lead' => $tr(
            'yuqori unumdorlikdagi 300 dpi sanoat printeri: 600 m gacha ribbon sigʻadi, Wi-Fi modulini ulashga tayyor. USB, USB Host, Ethernet va RS232 interfeyslari mavjud.',
            'высокопроизводительный промышленный принтер 300 dpi: вмещает риббон до 600 м, готов к установке модуля Wi-Fi. Интерфейсы USB, USB Host, Ethernet и RS232.',
            'a high-throughput 300 dpi industrial printer that takes ribbons up to 600 m and is ready for a Wi-Fi module. Interfaces include USB, USB Host, Ethernet and RS232.',
        ),
    ],
    [
        'sku' => '156', 'model' => 'MX341P', 'brand' => 'TSC', 'category' => 'industrial-label-printers', 'kind' => 'industrial', 'dpi' => 300, 'price' => 30250000, 'variant' => null,
        'specs' => ['print_method' => $both, 'resolution' => '300', 'print_width' => 104, 'interface' => ['USB', 'Ethernet', 'RS232'], 'print_speed' => 356, 'max_roll_diameter' => 203, 'max_ribbon_length' => 600],
        'missing' => false,
        'lead' => $tr(
            'TSC ning eng unumdor sanoat seriyasidagi 300 dpi printer: 356 mm/s gacha tezlik va 600 m gacha ribbon. Katta hajmli yorliq chop etuvchi omborlar va zavodlar uchun.',
            'принтер 300 dpi из самой производительной промышленной серии TSC: скорость до 356 мм/с и риббон до 600 м. Для складов и заводов с большими объёмами печати.',
            'a 300 dpi printer from the top TSC industrial series with speeds up to 356 mm/s and ribbons up to 600 m, made for warehouses and plants with high print volumes.',
        ),
    ],
    [
        'sku' => '157', 'model' => 'T6304e ODV-2D', 'brand' => 'Printronix', 'category' => 'industrial-label-printers', 'kind' => 'industrial', 'dpi' => 300, 'price' => 18150000, 'variant' => null,
        'specs' => ['print_method' => $both, 'resolution' => '300', 'print_width' => 104, 'interface' => ['USB', 'Ethernet', 'RS232']],
        'missing' => false,
        'lead' => $tr(
            '4 dyuymli sanoat printeri, ichiga ODV-2D shtrix-kod verifikatori oʻrnatilgan: chop etilgan 1D va 2D kodlarning sifati darhol tekshiriladi. USB, USB Host, Ethernet va RS232.',
            '4-дюймовый промышленный принтер со встроенным верификатором штрих-кодов ODV-2D: качество напечатанных 1D- и 2D-кодов проверяется сразу. Интерфейсы USB, USB Host, Ethernet и RS232.',
            'a 4-inch industrial printer with a built-in ODV-2D barcode verifier that checks the quality of printed 1D and 2D codes right away. Interfaces include USB, USB Host, Ethernet and RS232.',
        ),
    ],

    // ─── Mobile label printers ──────────────────────────────────────────────
    [
        'sku' => '262', 'model' => 'B300', 'brand' => 'Gainscha', 'category' => 'mobile-label-printers', 'kind' => 'mobile', 'dpi' => 203, 'price' => 1150000, 'variant' => null,
        'specs' => ['print_method' => [$dt], 'resolution' => '203', 'interface' => ['Bluetooth', 'USB']],
        'missing' => true,
        'lead' => $tr(
            '203 dpi mobil yorliq printeri, Bluetooth va USB orqali ulanadi. Aniq texnik parametrlarni (kenglik, batareya, himoya) Poson mutaxassislari buyurtma oldidan tasdiqlaydi.',
            'мобильный принтер этикеток 203 dpi, подключение по Bluetooth и USB. Точные технические параметры (ширину, батарею, защиту) наши специалисты подтвердят перед заказом.',
            'a 203 dpi mobile label printer that connects over Bluetooth and USB. Our specialists confirm the exact details (width, battery, protection) before you order.',
        ),
    ],
    [
        'sku' => '30', 'model' => 'GP-M322', 'brand' => 'Gainscha', 'category' => 'mobile-label-printers', 'kind' => 'mobile', 'dpi' => 203, 'price' => 1160000, 'variant' => null,
        'specs' => ['print_method' => [$dt], 'resolution' => '203', 'interface' => ['Bluetooth', 'USB']],
        'missing' => true,
        'lead' => $tr(
            '203 dpi mobil yorliq printeri, Bluetooth va USB orqali ulanadi. Aniq texnik parametrlarni (kenglik, batareya, himoya) Poson mutaxassislari buyurtma oldidan tasdiqlaydi.',
            'мобильный принтер этикеток 203 dpi, подключение по Bluetooth и USB. Точные технические параметры (ширину, батарею, защиту) наши специалисты подтвердят перед заказом.',
            'a 203 dpi mobile label printer that connects over Bluetooth and USB. Our specialists confirm the exact details (width, battery, protection) before you order.',
        ),
    ],

    // ─── RFID printers ──────────────────────────────────────────────────────
    [
        'sku' => '242', 'model' => 'ZD621R', 'brand' => 'Zebra', 'category' => 'rfid-printers', 'kind' => 'rfid', 'dpi' => 300, 'price' => 24200000, 'variant' => null,
        'specs' => ['print_method' => $both, 'resolution' => '300', 'print_width' => 104, 'interface' => ['USB', 'Ethernet', 'RS232'], 'rfid_frequency' => ['UHF 860–960 MHz'], 'rfid_protocol' => ['EPC Gen2 (ISO 18000-63)']],
        'missing' => false,
        'lead' => $tr(
            'UHF RFID yorliqlarni bir vaqtning oʻzida kodlaydigan va chop etadigan stol usti printer. 300 dpi aniqlik, USB, USB Host, Ethernet va ketma-ket port.',
            'настольный принтер, который кодирует и печатает UHF RFID-этикетки за один проход. Разрешение 300 dpi, интерфейсы USB, USB Host, Ethernet и последовательный порт.',
            'a desktop printer that encodes and prints UHF RFID labels in a single pass. It offers 300 dpi, USB, USB Host, Ethernet and a serial port.',
        ),
    ],

    // ─── Spare parts: printheads ────────────────────────────────────────────
    [
        'sku' => '199', 'model' => 'G500', 'brand' => 'Godex', 'category' => 'spare-parts', 'kind' => 'part', 'dpi' => 203, 'price' => 1200000, 'variant' => null,
        'partName' => $tr('Godex G500 uchun termik kallak, 203 dpi', 'Термоголовка Godex G500, 203 dpi', 'Godex G500 thermal printhead, 203 dpi'),
        'specs' => [],
        'missing' => false,
        'lead' => $tr(
            'Godex G500 yorliq printeri uchun almashtiriladigan 203 dpi termik kallak. Eskirgan kallak chiziqlar va oqargan chop etishga sabab boʻladi; almashtirish sifatni tiklaydi.',
            'сменная термоголовка 203 dpi для принтера этикеток Godex G500. Изношенная головка даёт полосы и бледную печать; замена возвращает качество.',
            'a replacement 203 dpi thermal printhead for the Godex G500 label printer. A worn head causes streaks and faded prints; replacing it restores quality.',
        ),
        'rel' => [['9', 'accessory']],
    ],
    [
        'sku' => '200', 'model' => 'G530', 'brand' => 'Godex', 'category' => 'spare-parts', 'kind' => 'part', 'dpi' => 300, 'price' => 1800000, 'variant' => null,
        'partName' => $tr('Godex G530 uchun termik kallak, 300 dpi', 'Термоголовка Godex G530, 300 dpi', 'Godex G530 thermal printhead, 300 dpi'),
        'specs' => [],
        'missing' => false,
        'lead' => $tr(
            'Godex G530 yorliq printeri uchun almashtiriladigan 300 dpi termik kallak. Eskirgan kallak chiziqlar va oqargan chop etishga sabab boʻladi; almashtirish sifatni tiklaydi.',
            'сменная термоголовка 300 dpi для принтера этикеток Godex G530. Изношенная головка даёт полосы и бледную печать; замена возвращает качество.',
            'a replacement 300 dpi thermal printhead for the Godex G530 label printer. A worn head causes streaks and faded prints; replacing it restores quality.',
        ),
        'rel' => [['55', 'accessory']],
    ],
    [
        'sku' => '45', 'model' => 'iX4-350 Pro', 'brand' => 'Argox', 'category' => 'spare-parts', 'kind' => 'part', 'dpi' => 300, 'price' => 3800000, 'variant' => null,
        'partName' => $tr('Argox iX4-350 Pro uchun termik kallak, 300 dpi', 'Термоголовка Argox iX4-350 Pro, 300 dpi', 'Argox iX4-350 Pro thermal printhead, 300 dpi'),
        'specs' => [],
        'missing' => false,
        'lead' => $tr(
            'Argox iX4-350 Pro sanoat printeri uchun almashtiriladigan 300 dpi termik kallak. Eskirgan kallak chiziqlar va oqargan chop etishga sabab boʻladi; almashtirish sifatni tiklaydi.',
            'сменная термоголовка 300 dpi для промышленного принтера Argox iX4-350 Pro. Изношенная головка даёт полосы и бледную печать; замена возвращает качество.',
            'a replacement 300 dpi thermal printhead for the Argox iX4-350 Pro industrial printer. A worn head causes streaks and faded prints; replacing it restores quality.',
        ),
    ],
];

return array_map($build, $rows);
