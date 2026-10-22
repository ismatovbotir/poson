<?php

/**
 * Attribute library for the CatalogSeeder (Plan 03, T9).
 *
 * Source: autoid-domain + catalog-module/references/spec-attributes.md.
 * `categories` maps a category slug from categories.php to pivot flags.
 * Children inherit a parent's assignment, so each attribute is assigned
 * at the highest sensible level and never twice on the same ancestor chain.
 * `is_filterable` is the library default for new category assignments.
 * Technical option values (USB, IP65, 203, Android 14, …) are identical in all locales.
 *
 * @return list<array{
 *     code: string,
 *     name: array{uz: string, ru: string, en: string},
 *     unit: string|null,
 *     type: 'text'|'number'|'boolean'|'select'|'multiselect',
 *     is_filterable: bool,
 *     sort: int,
 *     options: list<array{uz: string, ru: string, en: string}>,
 *     categories: array<string, array{is_required: bool, is_filterable: bool}>
 * }>
 */
$same = static fn (string $value): array => ['uz' => $value, 'ru' => $value, 'en' => $value];

$assign = static fn (bool $required, bool $filterable): array => [
    'is_required' => $required,
    'is_filterable' => $filterable,
];

return [

    // ─── Scanning ───────────────────────────────────────────────────────────
    [
        'code' => 'scan_engine',
        'name' => ['uz' => 'Skanerlash moduli', 'ru' => 'Сканирующий модуль', 'en' => 'Scan engine'],
        'unit' => null,
        'type' => 'select',
        'is_filterable' => true,
        'sort' => 10,
        'options' => [
            ['uz' => '1D lazer', 'ru' => '1D лазерный', 'en' => '1D laser'],
            $same('1D CCD'),
            ['uz' => '2D imidjer', 'ru' => '2D-имиджер', 'en' => '2D imager'],
        ],
        'categories' => [
            'scanners' => $assign(true, true),
            'mobile-computers' => $assign(false, true),
        ],
    ],
    [
        'code' => 'symbologies',
        'name' => ['uz' => 'Oʻqiladigan kodlar', 'ru' => 'Считываемые коды', 'en' => 'Symbologies'],
        'unit' => null,
        'type' => 'multiselect',
        'is_filterable' => true,
        'sort' => 20,
        'options' => [
            $same('1D (EAN, UPC, Code 128)'),
            $same('QR Code'),
            $same('DataMatrix'),
            $same('PDF417'),
            $same('GS1 DataBar'),
            $same('Aztec'),
        ],
        'categories' => [
            'scanners' => $assign(false, true),
            'mobile-computers' => $assign(false, false),
        ],
    ],
    [
        'code' => 'interface',
        'name' => ['uz' => 'Interfeyslar va aloqa', 'ru' => 'Интерфейсы и связь', 'en' => 'Interfaces and connectivity'],
        'unit' => null,
        'type' => 'multiselect',
        'is_filterable' => true,
        'sort' => 30,
        'options' => [
            $same('USB'),
            $same('RS232'),
            $same('Bluetooth'),
            $same('2.4 GHz'),
            $same('Ethernet'),
            $same('Wi-Fi'),
            $same('LTE'),
        ],
        'categories' => [
            'scanners' => $assign(true, true),
            'label-printers' => $assign(true, true),
            'mobile-computers' => $assign(false, true),
            'fixed-rfid-readers' => $assign(true, true),
            'handheld-rfid-readers' => $assign(false, true),
            'rfid-printers' => $assign(true, true),
            'pos-terminals' => $assign(false, false),
            'receipt-printers' => $assign(true, true),
            'customer-displays' => $assign(false, true),
            'scales' => $assign(false, false),
        ],
    ],
    [
        'code' => 'ip_rating',
        'name' => ['uz' => 'Himoya darajasi (IP)', 'ru' => 'Степень защиты (IP)', 'en' => 'IP rating'],
        'unit' => null,
        'type' => 'select',
        'is_filterable' => true,
        'sort' => 40,
        'options' => [
            $same('IP42'),
            $same('IP52'),
            $same('IP53'),
            $same('IP54'),
            $same('IP65'),
            $same('IP67'),
            $same('IP68'),
        ],
        'categories' => [
            'scanners' => $assign(false, true),
            'mobile-computers' => $assign(false, true),
            'mobile-label-printers' => $assign(false, true),
            'handheld-rfid-readers' => $assign(false, true),
            'fixed-rfid-readers' => $assign(false, false),
        ],
    ],
    [
        'code' => 'drop_spec',
        'name' => ['uz' => 'Tushishga chidamlilik', 'ru' => 'Устойчивость к падениям', 'en' => 'Drop spec'],
        'unit' => 'm',
        'type' => 'number',
        'is_filterable' => false,
        'sort' => 50,
        'options' => [],
        'categories' => [
            'scanners' => $assign(false, false),
            'mobile-computers' => $assign(false, false),
            'mobile-label-printers' => $assign(false, false),
            'handheld-rfid-readers' => $assign(false, false),
        ],
    ],

    // ─── Printing ───────────────────────────────────────────────────────────
    [
        'code' => 'print_method',
        'name' => ['uz' => 'Chop etish usuli', 'ru' => 'Способ печати', 'en' => 'Print method'],
        'unit' => null,
        'type' => 'multiselect',
        'is_filterable' => true,
        'sort' => 100,
        'options' => [
            ['uz' => 'Toʻgʻridan-toʻgʻri termo', 'ru' => 'Прямая термопечать', 'en' => 'Direct thermal'],
            ['uz' => 'Termotransfer', 'ru' => 'Термотрансфер', 'en' => 'Thermal transfer'],
        ],
        'categories' => [
            'label-printers' => $assign(true, true),
            'rfid-printers' => $assign(true, true),
        ],
    ],
    [
        'code' => 'resolution',
        'name' => ['uz' => 'Chop etish aniqligi', 'ru' => 'Разрешение печати', 'en' => 'Print resolution'],
        'unit' => 'dpi',
        'type' => 'select',
        'is_filterable' => true,
        'sort' => 110,
        'options' => [
            $same('203'),
            $same('300'),
            $same('600'),
        ],
        'categories' => [
            'label-printers' => $assign(true, true),
            'rfid-printers' => $assign(true, true),
        ],
    ],
    [
        'code' => 'print_width',
        'name' => ['uz' => 'Maks. chop etish kengligi', 'ru' => 'Макс. ширина печати', 'en' => 'Max print width'],
        'unit' => 'mm',
        'type' => 'number',
        'is_filterable' => true,
        'sort' => 120,
        'options' => [],
        'categories' => [
            'label-printers' => $assign(true, true),
            'rfid-printers' => $assign(true, false),
        ],
    ],
    [
        'code' => 'print_speed',
        'name' => ['uz' => 'Chop etish tezligi', 'ru' => 'Скорость печати', 'en' => 'Print speed'],
        'unit' => 'mm/s',
        'type' => 'number',
        'is_filterable' => false,
        'sort' => 130,
        'options' => [],
        'categories' => [
            'label-printers' => $assign(false, false),
            'rfid-printers' => $assign(false, false),
            'receipt-printers' => $assign(false, false),
        ],
    ],
    [
        'code' => 'max_roll_diameter',
        'name' => ['uz' => 'Rulonning maks. tashqi diametri', 'ru' => 'Макс. внешний диаметр рулона', 'en' => 'Max roll outer diameter'],
        'unit' => 'mm',
        'type' => 'number',
        'is_filterable' => false,
        'sort' => 140,
        'options' => [],
        'categories' => [
            'label-printers' => $assign(false, false),
            'rfid-printers' => $assign(false, false),
        ],
    ],
    [
        'code' => 'max_ribbon_length',
        'name' => ['uz' => 'Ribbonning maks. uzunligi', 'ru' => 'Макс. длина риббона', 'en' => 'Max ribbon length'],
        'unit' => 'm',
        'type' => 'number',
        'is_filterable' => false,
        'sort' => 150,
        'options' => [],
        'categories' => [
            'label-printers' => $assign(false, false),
            'rfid-printers' => $assign(false, false),
        ],
    ],
    [
        'code' => 'paper_width',
        'name' => ['uz' => 'Lenta kengligi', 'ru' => 'Ширина ленты', 'en' => 'Paper width'],
        'unit' => 'mm',
        'type' => 'select',
        'is_filterable' => true,
        'sort' => 160,
        'options' => [
            $same('58'),
            $same('80'),
        ],
        'categories' => [
            'receipt-printers' => $assign(true, true),
            'receipt-paper' => $assign(true, true),
        ],
    ],
    [
        'code' => 'auto_cutter',
        'name' => ['uz' => 'Avtokesgich', 'ru' => 'Автоотрезчик', 'en' => 'Auto-cutter'],
        'unit' => null,
        'type' => 'boolean',
        'is_filterable' => true,
        'sort' => 170,
        'options' => [],
        'categories' => [
            'receipt-printers' => $assign(false, true),
        ],
    ],

    // ─── Mobile computers and POS terminals ─────────────────────────────────
    [
        'code' => 'os',
        'name' => ['uz' => 'Operatsion tizim', 'ru' => 'Операционная система', 'en' => 'Operating system'],
        'unit' => null,
        'type' => 'select',
        'is_filterable' => true,
        'sort' => 200,
        'options' => [
            $same('Android 11'),
            $same('Android 12'),
            $same('Android 13'),
            $same('Android 14'),
            $same('Android 15'),
            $same('Windows'),
        ],
        'categories' => [
            'mobile-computers' => $assign(true, true),
            'pos-terminals' => $assign(true, true),
        ],
    ],
    [
        'code' => 'screen_size',
        'name' => ['uz' => 'Ekran diagonali', 'ru' => 'Диагональ экрана', 'en' => 'Screen size'],
        'unit' => 'inch',
        'type' => 'number',
        'is_filterable' => true,
        'sort' => 210,
        'options' => [],
        'categories' => [
            'mobile-computers' => $assign(false, true),
            'pos-terminals' => $assign(false, true),
        ],
    ],
    [
        'code' => 'ram',
        'name' => ['uz' => 'Operativ xotira', 'ru' => 'Оперативная память', 'en' => 'RAM'],
        'unit' => 'GB',
        'type' => 'number',
        'is_filterable' => false,
        'sort' => 220,
        'options' => [],
        'categories' => [
            'mobile-computers' => $assign(false, false),
            'pos-terminals' => $assign(false, false),
        ],
    ],
    [
        'code' => 'storage',
        'name' => ['uz' => 'Doimiy xotira', 'ru' => 'Встроенная память', 'en' => 'Storage'],
        'unit' => 'GB',
        'type' => 'number',
        'is_filterable' => false,
        'sort' => 230,
        'options' => [],
        'categories' => [
            'mobile-computers' => $assign(false, false),
            'pos-terminals' => $assign(false, false),
        ],
    ],
    [
        'code' => 'battery',
        'name' => ['uz' => 'Akkumulyator sigʻimi', 'ru' => 'Ёмкость аккумулятора', 'en' => 'Battery capacity'],
        'unit' => 'mAh',
        'type' => 'number',
        'is_filterable' => false,
        'sort' => 240,
        'options' => [],
        'categories' => [
            'mobile-computers' => $assign(false, false),
            'mobile-label-printers' => $assign(false, false),
            'handheld-rfid-readers' => $assign(false, false),
        ],
    ],

    // ─── RFID ───────────────────────────────────────────────────────────────
    [
        'code' => 'rfid_frequency',
        'name' => ['uz' => 'RFID chastotasi', 'ru' => 'Частота RFID', 'en' => 'RFID frequency'],
        'unit' => null,
        'type' => 'multiselect',
        'is_filterable' => true,
        'sort' => 300,
        'options' => [
            $same('UHF 860–960 MHz'),
            $same('HF 13.56 MHz'),
            $same('LF 125 kHz'),
            $same('NFC'),
        ],
        'categories' => [
            'rfid' => $assign(true, true),
            'rfid-mobile-computers' => $assign(true, true),
        ],
    ],
    [
        'code' => 'rfid_protocol',
        'name' => ['uz' => 'RFID protokoli', 'ru' => 'Протокол RFID', 'en' => 'RFID protocol'],
        'unit' => null,
        'type' => 'multiselect',
        'is_filterable' => false,
        'sort' => 310,
        'options' => [
            $same('EPC Gen2 (ISO 18000-63)'),
            $same('ISO 15693'),
            $same('ISO 14443'),
        ],
        'categories' => [
            'rfid' => $assign(false, false),
            'rfid-mobile-computers' => $assign(false, false),
        ],
    ],
    [
        'code' => 'read_range',
        'name' => ['uz' => 'Oʻqish masofasi', 'ru' => 'Дальность считывания', 'en' => 'Read range'],
        'unit' => 'm',
        'type' => 'number',
        'is_filterable' => false,
        'sort' => 320,
        'options' => [],
        'categories' => [
            'fixed-rfid-readers' => $assign(false, false),
            'handheld-rfid-readers' => $assign(false, false),
            'rfid-tags' => $assign(false, false),
            'rfid-mobile-computers' => $assign(false, false),
        ],
    ],
    [
        'code' => 'antenna_ports',
        'name' => ['uz' => 'Antenna portlari soni', 'ru' => 'Количество антенных портов', 'en' => 'Antenna ports'],
        'unit' => null,
        'type' => 'number',
        'is_filterable' => false,
        'sort' => 330,
        'options' => [],
        'categories' => [
            'fixed-rfid-readers' => $assign(false, false),
        ],
    ],
    [
        'code' => 'antenna_polarization',
        'name' => ['uz' => 'Qutblanish', 'ru' => 'Поляризация', 'en' => 'Polarisation'],
        'unit' => null,
        'type' => 'select',
        'is_filterable' => true,
        'sort' => 340,
        'options' => [
            ['uz' => 'Chiziqli', 'ru' => 'Линейная', 'en' => 'Linear'],
            ['uz' => 'Doiraviy', 'ru' => 'Круговая', 'en' => 'Circular'],
        ],
        'categories' => [
            'rfid-antennas' => $assign(false, true),
        ],
    ],

    // ─── Labels ─────────────────────────────────────────────────────────────
    [
        'code' => 'label_width',
        'name' => ['uz' => 'Yorliq kengligi', 'ru' => 'Ширина этикетки', 'en' => 'Label width'],
        'unit' => 'mm',
        'type' => 'number',
        'is_filterable' => true,
        'sort' => 400,
        'options' => [],
        'categories' => [
            'thermal-labels' => $assign(true, true),
            'thermal-transfer-labels' => $assign(true, true),
            'rfid-tags' => $assign(false, false),
        ],
    ],
    [
        'code' => 'label_height',
        'name' => ['uz' => 'Yorliq balandligi', 'ru' => 'Высота этикетки', 'en' => 'Label height'],
        'unit' => 'mm',
        'type' => 'number',
        'is_filterable' => true,
        'sort' => 410,
        'options' => [],
        'categories' => [
            'thermal-labels' => $assign(true, true),
            'thermal-transfer-labels' => $assign(true, true),
            'rfid-tags' => $assign(false, false),
        ],
    ],
    [
        'code' => 'core_diameter',
        'name' => ['uz' => 'Gilza diametri', 'ru' => 'Диаметр втулки', 'en' => 'Core diameter'],
        'unit' => 'mm',
        'type' => 'select',
        'is_filterable' => true,
        'sort' => 420,
        'options' => [
            $same('12.7'),
            $same('25'),
            $same('40'),
            $same('76'),
        ],
        'categories' => [
            'thermal-labels' => $assign(true, true),
            'thermal-transfer-labels' => $assign(true, true),
            'ribbons' => $assign(true, true),
        ],
    ],
    [
        'code' => 'labels_per_roll',
        'name' => ['uz' => 'Rulondagi yorliqlar soni', 'ru' => 'Этикеток в рулоне', 'en' => 'Labels per roll'],
        'unit' => 'pcs',
        'type' => 'number',
        'is_filterable' => false,
        'sort' => 430,
        'options' => [],
        'categories' => [
            'thermal-labels' => $assign(false, false),
            'thermal-transfer-labels' => $assign(false, false),
            'rfid-tags' => $assign(false, false),
        ],
    ],
    [
        'code' => 'label_material',
        'name' => ['uz' => 'Yorliq materiali', 'ru' => 'Материал этикетки', 'en' => 'Label material'],
        'unit' => null,
        'type' => 'select',
        'is_filterable' => true,
        'sort' => 440,
        'options' => [
            ['uz' => 'Qogʻoz', 'ru' => 'Бумага', 'en' => 'Paper'],
            ['uz' => 'Polipropilen (PP)', 'ru' => 'Полипропилен (PP)', 'en' => 'Polypropylene (PP)'],
            ['uz' => 'Polietilen (PE)', 'ru' => 'Полиэтилен (PE)', 'en' => 'Polyethylene (PE)'],
            ['uz' => 'Poliester (PET)', 'ru' => 'Полиэстер (PET)', 'en' => 'Polyester (PET)'],
        ],
        'categories' => [
            'thermal-labels' => $assign(false, true),
            'thermal-transfer-labels' => $assign(true, true),
            'rfid-tags' => $assign(false, true),
        ],
    ],
    [
        'code' => 'adhesive',
        'name' => ['uz' => 'Yelim turi', 'ru' => 'Тип клея', 'en' => 'Adhesive'],
        'unit' => null,
        'type' => 'select',
        'is_filterable' => true,
        'sort' => 450,
        'options' => [
            ['uz' => 'Doimiy', 'ru' => 'Постоянный', 'en' => 'Permanent'],
            ['uz' => 'Oson olinadigan', 'ru' => 'Съёмный', 'en' => 'Removable'],
            ['uz' => 'Muzlatkich uchun', 'ru' => 'Морозостойкий', 'en' => 'Freezer'],
        ],
        'categories' => [
            'thermal-labels' => $assign(false, true),
            'thermal-transfer-labels' => $assign(false, true),
            'rfid-tags' => $assign(false, false),
        ],
    ],
    [
        'code' => 'thermal_coating',
        'name' => ['uz' => 'Termoqatlam turi', 'ru' => 'Тип термослоя', 'en' => 'Thermal coating'],
        'unit' => null,
        'type' => 'select',
        'is_filterable' => true,
        'sort' => 460,
        'options' => [
            $same('Eco'),
            $same('Top'),
        ],
        'categories' => [
            'thermal-labels' => $assign(false, true),
        ],
    ],

    // ─── Ribbons ────────────────────────────────────────────────────────────
    [
        'code' => 'ribbon_type',
        'name' => ['uz' => 'Ribbon turi', 'ru' => 'Тип риббона', 'en' => 'Ribbon type'],
        'unit' => null,
        'type' => 'select',
        'is_filterable' => true,
        'sort' => 500,
        'options' => [
            ['uz' => 'Wax (mum)', 'ru' => 'Wax (восковый)', 'en' => 'Wax'],
            ['uz' => 'Wax-resin (mum-smola)', 'ru' => 'Wax-resin (воск-смола)', 'en' => 'Wax-resin'],
            ['uz' => 'Resin (smola)', 'ru' => 'Resin (смоляной)', 'en' => 'Resin'],
        ],
        'categories' => [
            'ribbons' => $assign(true, true),
        ],
    ],
    [
        'code' => 'ribbon_width',
        'name' => ['uz' => 'Ribbon kengligi', 'ru' => 'Ширина риббона', 'en' => 'Ribbon width'],
        'unit' => 'mm',
        'type' => 'number',
        'is_filterable' => true,
        'sort' => 510,
        'options' => [],
        'categories' => [
            'ribbons' => $assign(true, true),
        ],
    ],
    [
        'code' => 'ribbon_length',
        'name' => ['uz' => 'Ribbon uzunligi', 'ru' => 'Длина риббона', 'en' => 'Ribbon length'],
        'unit' => 'm',
        'type' => 'number',
        'is_filterable' => true,
        'sort' => 520,
        'options' => [],
        'categories' => [
            'ribbons' => $assign(true, true),
        ],
    ],
    [
        'code' => 'ink_side',
        'name' => ['uz' => 'Boʻyoq qatlami tomoni', 'ru' => 'Сторона красящего слоя', 'en' => 'Ink side'],
        'unit' => null,
        'type' => 'select',
        'is_filterable' => true,
        'sort' => 530,
        'options' => [
            $same('IN'),
            $same('OUT'),
        ],
        'categories' => [
            'ribbons' => $assign(true, true),
        ],
    ],

    // ─── POS ────────────────────────────────────────────────────────────────
    [
        'code' => 'max_weight',
        'name' => ['uz' => 'Maks. tortish chegarasi', 'ru' => 'Наибольший предел взвешивания', 'en' => 'Max capacity'],
        'unit' => 'kg',
        'type' => 'number',
        'is_filterable' => true,
        'sort' => 600,
        'options' => [],
        'categories' => [
            'scales' => $assign(true, true),
        ],
    ],

    // ─── Software ───────────────────────────────────────────────────────────
    // Software details are admin-managed specs (decision 2026-10-22, business-facts
    // row 24), all optional. licence_model supersedes the former license_type
    // (perpetual / subscription): it is finer and a vendor may bill monthly and
    // yearly, so it is a multiselect ("Subscription" = period not known).
    // integrations are the program's own, not Poson services. The vendor site is the brand website
    // (CatalogSeeder::BRAND_WEBSITES; the "Try" link). Values: software-facts.md.
    [
        'code' => 'platforms',
        'name' => ['uz' => 'Platformalar', 'ru' => 'Платформы', 'en' => 'Platforms'],
        'unit' => null,
        'type' => 'multiselect',
        'is_filterable' => true,
        'sort' => 690,
        'options' => [
            $same('Windows'),
            $same('Android'),
            $same('iOS'),
            ['uz' => 'Veb / bulut', 'ru' => 'Веб / облако', 'en' => 'Web / cloud'],
        ],
        'categories' => [
            'software' => $assign(false, true),
        ],
    ],
    [
        'code' => 'licence_model',
        'name' => ['uz' => 'Litsenziya modeli', 'ru' => 'Модель лицензии', 'en' => 'Licence model'],
        'unit' => null,
        'type' => 'multiselect',
        'is_filterable' => false,
        'sort' => 700,
        'options' => [
            ['uz' => 'Oylik obuna', 'ru' => 'Ежемесячная подписка', 'en' => 'Monthly subscription'],
            ['uz' => 'Yillik obuna', 'ru' => 'Годовая подписка', 'en' => 'Yearly subscription'],
            ['uz' => 'Obuna', 'ru' => 'Подписка', 'en' => 'Subscription'],
            ['uz' => 'Muddatsiz litsenziya', 'ru' => 'Бессрочная лицензия', 'en' => 'Perpetual licence'],
            ['uz' => 'Individual shartlar', 'ru' => 'Индивидуальные условия', 'en' => 'Individual terms'],
        ],
        'categories' => [
            'software' => $assign(false, false),
        ],
    ],
    [
        'code' => 'segment',
        'name' => ['uz' => 'Biznes turi', 'ru' => 'Сфера бизнеса', 'en' => 'Business type'],
        'unit' => null,
        'type' => 'multiselect',
        'is_filterable' => true,
        'sort' => 702,
        'options' => [
            ['uz' => 'Chakana savdo', 'ru' => 'Розничная торговля', 'en' => 'Retail'],
            $same('HoReCa'),
            ['uz' => 'Ombor va zaxiralar hisobi', 'ru' => 'Склад и учёт запасов', 'en' => 'Warehouse and inventory'],
            ['uz' => 'Ulgurji savdo', 'ru' => 'Оптовая торговля', 'en' => 'Wholesale'],
            ['uz' => 'Dorixonalar', 'ru' => 'Аптеки', 'en' => 'Pharmacies'],
        ],
        'categories' => [
            'software' => $assign(false, true),
        ],
    ],
    [
        'code' => 'modules',
        'name' => ['uz' => 'Modullar', 'ru' => 'Модули', 'en' => 'Modules'],
        'unit' => null,
        'type' => 'multiselect',
        'is_filterable' => true,
        'sort' => 704,
        'options' => [
            ['uz' => 'Kassa (POS)', 'ru' => 'Касса (POS)', 'en' => 'Cash desk (POS)'],
            ['uz' => 'Ofitsiant ilovasi', 'ru' => 'Приложение официанта', 'en' => 'Waiter app'],
            ['uz' => 'Oshxona ekrani va printeri', 'ru' => 'Кухонный экран и печать', 'en' => 'Kitchen display and printing'],
            ['uz' => 'Ombor hisobi', 'ru' => 'Складской учёт', 'en' => 'Inventory'],
            ['uz' => 'Yetkazib berish', 'ru' => 'Доставка', 'en' => 'Delivery'],
            ['uz' => 'CRM va sodiqlik dasturi', 'ru' => 'CRM и программа лояльности', 'en' => 'CRM and loyalty programme'],
            ['uz' => 'Tahlil va hisobotlar', 'ru' => 'Аналитика и отчёты', 'en' => 'Analytics and reports'],
            ['uz' => 'Onlayn doʻkon', 'ru' => 'Интернет-магазин', 'en' => 'Online store'],
        ],
        'categories' => [
            'software' => $assign(false, true),
        ],
    ],
    [
        'code' => 'integrations',
        'name' => ['uz' => 'Dastur integratsiyalari', 'ru' => 'Интеграции программы', 'en' => 'Program integrations'],
        'unit' => null,
        'type' => 'multiselect',
        'is_filterable' => false,
        'sort' => 706,
        'options' => [
            $same('1C'),
            $same('Didox'),
            $same('Uzum Market'),
            $same('Telegram'),
            ['uz' => 'QR orqali toʻlov', 'ru' => 'Оплата по QR', 'en' => 'QR payment'],
        ],
        'categories' => [
            'software' => $assign(false, false),
        ],
    ],
    [
        'code' => 'online_cash_register',
        'name' => ['uz' => 'Fiskal cheklar (onlayn kassa)', 'ru' => 'Фискальные чеки (онлайн-касса)', 'en' => 'Fiscal receipts (online cash register)'],
        'unit' => null,
        'type' => 'boolean',
        'is_filterable' => true,
        'sort' => 708,
        'options' => [],
        'categories' => [
            'software' => $assign(false, true),
        ],
    ],
    [
        'code' => 'interface_languages',
        'name' => ['uz' => 'Interfeys tillari', 'ru' => 'Языки интерфейса', 'en' => 'Interface languages'],
        'unit' => null,
        'type' => 'multiselect',
        'is_filterable' => false,
        'sort' => 709,
        'options' => [
            $same('Oʻzbekcha'),
            $same('Русский'),
            $same('English'),
        ],
        'categories' => [
            'software' => $assign(false, false),
        ],
    ],
    [
        'code' => 'license_term',
        'name' => ['uz' => 'Litsenziya muddati', 'ru' => 'Срок лицензии', 'en' => 'Licence term'],
        'unit' => 'months',
        'type' => 'number',
        'is_filterable' => false,
        'sort' => 710,
        'options' => [],
        'categories' => [
            'software' => $assign(false, false),
        ],
    ],
    [
        'code' => 'seats',
        'name' => ['uz' => 'Qurilmalar / ish joylari soni', 'ru' => 'Количество устройств / рабочих мест', 'en' => 'Devices / seats'],
        'unit' => null,
        'type' => 'number',
        'is_filterable' => false,
        'sort' => 720,
        'options' => [],
        'categories' => [
            'software' => $assign(false, false),
        ],
    ],

    // ─── Services ───────────────────────────────────────────────────────────
    [
        'code' => 'service_duration',
        'name' => ['uz' => 'Bajarish davomiyligi', 'ru' => 'Длительность работ', 'en' => 'Duration'],
        'unit' => 'hours',
        'type' => 'number',
        'is_filterable' => false,
        'sort' => 800,
        'options' => [],
        'categories' => [
            'services' => $assign(true, false),
        ],
    ],
    [
        'code' => 'sla_hours',
        'name' => ['uz' => 'Javob berish muddati (SLA)', 'ru' => 'Время отклика (SLA)', 'en' => 'Response time (SLA)'],
        'unit' => 'hours',
        'type' => 'number',
        'is_filterable' => false,
        'sort' => 810,
        'options' => [],
        'categories' => [
            'services' => $assign(false, false),
        ],
    ],

];
