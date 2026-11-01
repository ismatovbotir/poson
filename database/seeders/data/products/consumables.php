<?php

/**
 * Real consumables (labels, ribbons, receipt paper, textile tapes) from the 1C price list.
 *
 * Source: .claude/docs/pm/import/products-base.json, rows with group = consumables (131 rows).
 * Row shape follows database/seeders/data/products.php plus three extras:
 * `pricing_unit` ('label' = `price` is per single label, 'unit' = per roll), `suspicious_price`
 * and `has_missing_specs`. Every row is built from the compact table at the bottom
 * through per-family text templates (uz Latin, ru, en); the output is a plain list.
 *
 * - `price` is the 1C retail price in soʻm as given (fractional per-label prices are kept,
 *   never rounded); null = price on request (sale_mode `quote`). Dealer/wholesale are null.
 * - Assumptions (confirm with Poson):
 *   1. Receipt paper "80*80": the second number is the roll LENGTH in metres (a 20 mm-core
 *      48 g/m2 roll of 80 m is about 77 mm in diameter, which matches the 80 mm class).
 *      The 56 mm width is the `paper_width` option "56" (owner confirmed 2026-11-01).
 *   2. Label `roll_types` are NOT in the 1C names (except sku 56: 5000 pcs). They are assumed
 *      by label height: <=25 mm 40/2000 + 40/1000; <=45 mm 40/1000 + 40/500; <=100 mm 40/500;
 *      taller 40/500 (core mm / labels per roll; default core is 40 mm, owner 2026-11-01). Skus 161 and 39 have none (unknown).
 *   3. Ribbons: `ink_side` = OUT and `core_diameter` = 25 (1 inch core) for every ribbon, confirmed
 *      by the owner 2026-11-01; they are complete (has_missing_specs = false).
 *   4. Textile tapes use the tape_* attributes (tape_width, tape_length, tape_material, tape_color).
 *
 * Uzbek is Latin script: oʻ, gʻ use ʻ (U+02BB); the tutuq belgisi uses ʼ (U+02BC).
 *
 * @return list<array<string, mixed>>
 */
$num = static function (float|int $n, string $locale): string {
    $s = rtrim(rtrim(number_format((float) $n, 1, '.', ''), '0'), '.');

    return $locale === 'en' ? $s : str_replace('.', ',', $s);
};

$cut = static function (string $s, int $max): string {
    if (mb_strlen($s) <= $max) {
        return $s;
    }
    $head = mb_substr($s, 0, $max);
    $pos = mb_strrpos($head, ' ');

    return rtrim($pos === false ? $head : mb_substr($head, 0, $pos), ' ,;:-');
};

$html = static fn (string $intro, array $uses, string $close): string => '<p>'.$intro.'</p><ul><li>'.implode('</li><li>', $uses).'</li></ul><p>'.$close.'</p>';

$coreless = ['uz' => ', gilzasiz', 'ru' => ', без втулки', 'en' => ', coreless'];
$corelessNote = [
    'uz' => ' Rulon gilzasiz: ishlatilgandan keyin karton gilza chiqindisi qolmaydi.',
    'ru' => ' Рулон без втулки: после использования не остаётся картонной гильзы.',
    'en' => ' The roll is coreless, so no cardboard core is left over after use.',
];

// ─── Shared bullet lists ────────────────────────────────────────────────────
$U = [
    'dt' => [
        'uz' => ['<strong>Chakana savdo:</strong> narx yorliqlari, tarozi va tortiladigan tovar yorliqlari.', '<strong>Ombor:</strong> qabul qilish, ichki harakat va vaqtinchalik belgilash.', '<strong>Dorixona:</strong> qisqa muddatli yordamchi yorliqlar.', 'Termoqogʻoz asosli yorliq, rulonda, P-100-YD seriyasi.'],
        'ru' => ['<strong>Розница:</strong> ценники, этикетки для весов и весового товара.', '<strong>Склад:</strong> приёмка, внутренние перемещения и временная маркировка.', '<strong>Аптека:</strong> вспомогательные этикетки на короткий срок.', 'Этикетка на термобумаге, в рулоне, серия P-100-YD.'],
        'en' => ['<strong>Retail:</strong> price tags and labels for scales and weighed goods.', '<strong>Warehouse:</strong> receiving, internal moves and temporary marking.', '<strong>Pharmacy:</strong> auxiliary labels for short-term use.', 'Thermal paper face, supplied on rolls, P-100-YD series.'],
    ],
    'syn' => [
        'uz' => ['<strong>Chakana savdo:</strong> namlik yoki ishqalanish boʻladigan joylardagi tovar yorliqlari.', '<strong>Ombor va logistika:</strong> quti va idishlarga yorliqlar.', '<strong>Dorixona:</strong> qadoq va yordamchi yorliqlar.', 'Sintetik polipropilen (PP) asos, rulonda, ribbonsiz chop etiladi.'],
        'ru' => ['<strong>Розница:</strong> товарные этикетки там, где есть влага или трение.', '<strong>Склад и логистика:</strong> этикетки на коробки и ёмкости.', '<strong>Аптека:</strong> упаковочные и вспомогательные этикетки.', 'Синтетическая основа из полипропилена (PP), в рулоне, печать без риббона.'],
        'en' => ['<strong>Retail:</strong> product labels where moisture or rubbing is expected.', '<strong>Warehouse and logistics:</strong> labels for cartons and containers.', '<strong>Pharmacy:</strong> pack and auxiliary labels.', 'Synthetic polypropylene (PP) face, supplied on rolls, ribbon-free printing.'],
    ],
    'card' => [
        'uz' => ['<strong>Chakana savdo:</strong> kiyim, aksessuar va sovgʻa mahsulotlariga mos narx va tovar yorliqlari.', '<strong>Ombor:</strong> namuna va partiya belgilari.', 'Karton asos, ribbonsiz termochop etish.'],
        'ru' => ['<strong>Розница:</strong> ценники и бирки для одежды, аксессуаров и подарочных товаров.', '<strong>Склад:</strong> бирки на образцы и партии.', 'Картонная основа, термопечать без риббона.'],
        'en' => ['<strong>Retail:</strong> price and product tags for garments, accessories and gift items.', '<strong>Warehouse:</strong> sample and batch tags.', 'Cardboard stock, ribbon-free thermal printing.'],
    ],
    'band' => [
        'uz' => ['<strong>Klinika va shifoxonalar:</strong> bemorni identifikatsiya qilish.', '<strong>Qabul boʻlimi:</strong> brasletni ism va shtrix-kod bilan joyida chop etish.', 'Sintetik termomaterial (valeron), oʻlchami 275×26 mm.'],
        'ru' => ['<strong>Клиники и больницы:</strong> идентификация пациента.', '<strong>Приёмное отделение:</strong> печать браслета с именем и штрих-кодом на месте.', 'Синтетический термоматериал (валерон), размер 275×26 мм.'],
        'en' => ['<strong>Clinics and hospitals:</strong> patient identification.', '<strong>Admissions:</strong> printing a wristband with name and barcode on the spot.', 'Synthetic thermal material (valeron), 275×26 mm.'],
    ],
    'gpp' => [
        'uz' => ['<strong>Ishlab chiqarish:</strong> mahsulot va qadoq yorliqlari, shu jumladan nam sharoitda.', '<strong>Kosmetika va uy kimyosi:</strong> yaltiroq plyonkali yorliqlar.', '<strong>Logistika va ombor:</strong> chidamli yorliqlar.', 'Yaltiroq polipropilen (PP) plyonka, termotransfer chop etish.'],
        'ru' => ['<strong>Производство:</strong> этикетки на продукцию и упаковку, в том числе во влажной среде.', '<strong>Косметика и бытовая химия:</strong> этикетки на глянцевой плёнке.', '<strong>Логистика и склад:</strong> стойкая маркировка.', 'Глянцевая полипропиленовая (PP) плёнка, термотрансферная печать.'],
        'en' => ['<strong>Manufacturing:</strong> product and pack labels, including damp environments.', '<strong>Cosmetics and household chemicals:</strong> labels on gloss film.', '<strong>Logistics and warehouse:</strong> durable marking.', 'Gloss polypropylene (PP) film, thermal transfer printing.'],
    ],
    'coat' => [
        'uz' => ['<strong>Chakana savdo:</strong> tovar yorliqlari va shtrix-kodli narx yorliqlari.', '<strong>Ombor:</strong> quti, javon va joylashuv yorliqlari.', '<strong>Dorixona:</strong> qadoq yorliqlari.', 'Qoplamali qogʻoz, termotransfer chop etish, P-100-YG seriyasi.'],
        'ru' => ['<strong>Розница:</strong> товарные этикетки и ценники со штрих-кодом.', '<strong>Склад:</strong> коробочные, стеллажные и адресные этикетки.', '<strong>Аптека:</strong> упаковочные этикетки.', 'Мелованная бумага, термотрансферная печать, серия P-100-YG.'],
        'en' => ['<strong>Retail:</strong> product labels and barcoded price tags.', '<strong>Warehouse:</strong> carton, rack and location labels.', '<strong>Pharmacy:</strong> pack labels.', 'Coated paper, thermal transfer printing, P-100-YG series.'],
    ],
    'seal' => [
        'uz' => ['<strong>Logistika:</strong> quti, konteyner va yuklarni muhrlash.', '<strong>Ombor va ishlab chiqarish:</strong> qadoq ochilganini nazorat qilish.', '<strong>Dorixona:</strong> qadoq butunligini tasdiqlash.'],
        'ru' => ['<strong>Логистика:</strong> опломбирование коробок, контейнеров и грузов.', '<strong>Склад и производство:</strong> контроль вскрытия упаковки.', '<strong>Аптека:</strong> подтверждение целостности упаковки.'],
        'en' => ['<strong>Logistics:</strong> sealing cartons, containers and shipments.', '<strong>Warehouse and manufacturing:</strong> detecting opened packaging.', '<strong>Pharmacy:</strong> confirming pack integrity.'],
    ],
    'paper' => [
        'uz' => ['<strong>Chakana savdo:</strong> kassa cheklari.', '<strong>HoReCa:</strong> oshxona va bar buyurtma chiptalari.', '<strong>Dorixona va xizmat nuqtalari:</strong> cheklar va navbat talonlari.', 'Termoqogʻoz 48 g/m², rulonda.'],
        'ru' => ['<strong>Розница:</strong> кассовые чеки.', '<strong>HoReCa:</strong> кухонные и барные бегунки заказов.', '<strong>Аптеки и точки обслуживания:</strong> чеки и талоны очереди.', 'Термобумага 48 г/м², в рулоне.'],
        'en' => ['<strong>Retail:</strong> till receipts.', '<strong>HoReCa:</strong> kitchen and bar order tickets.', '<strong>Pharmacies and service points:</strong> receipts and queue tickets.', 'Thermal paper 48 g/m², supplied on rolls.'],
    ],
    'wax' => [
        'uz' => ['<strong>Chakana savdo:</strong> tovar va narx yorliqlari.', '<strong>Ombor:</strong> quti va joylashuv yorliqlari.', '<strong>Dorixona:</strong> qadoq yorliqlari.', 'Wax (mum) ribbon, %1$s%3$s.'],
        'ru' => ['<strong>Розница:</strong> товарные этикетки и ценники.', '<strong>Склад:</strong> коробочные и адресные этикетки.', '<strong>Аптека:</strong> упаковочные этикетки.', 'Риббон wax (воск), %1$s%3$s.'],
        'en' => ['<strong>Retail:</strong> product and price labels.', '<strong>Warehouse:</strong> carton and location labels.', '<strong>Pharmacy:</strong> pack labels.', 'Wax ribbon, %1$s%3$s.'],
    ],
    'wr' => [
        'uz' => ['<strong>Chakana savdo va ishlab chiqarish:</strong> qoplamali qogʻoz va yarim sintetik yorliqlar.', '<strong>Ombor:</strong> ishqalanishga chidamli quti yorliqlari.', '<strong>Dorixona:</strong> qadoq yorliqlari.', 'Wax-resin (mum-smola) ribbon, %1$s%3$s.'],
        'ru' => ['<strong>Розница и производство:</strong> этикетки из мелованной бумаги и полусинтетики.', '<strong>Склад:</strong> коробочные этикетки, стойкие к истиранию.', '<strong>Аптека:</strong> упаковочные этикетки.', 'Риббон wax-resin (воск-смола), %1$s%3$s.'],
        'en' => ['<strong>Retail and manufacturing:</strong> coated paper and semi-synthetic labels.', '<strong>Warehouse:</strong> carton labels that must resist rubbing.', '<strong>Pharmacy:</strong> pack labels.', 'Wax-resin ribbon, %1$s%3$s.'],
    ],
    'res' => [
        'uz' => ['<strong>Ishlab chiqarish:</strong> polipropilen va poliester yorliqlar.', '<strong>Logistika:</strong> namlik va ishqalanishga chidamli yorliqlar.', '<strong>Ombor:</strong> uzoq muddat oʻqiladigan shtrix-kodlar.', 'Resin (smola) ribbon, %1$s%3$s.'],
        'ru' => ['<strong>Производство:</strong> этикетки из полипропилена и полиэстера.', '<strong>Логистика:</strong> этикетки, стойкие к влаге и истиранию.', '<strong>Склад:</strong> штрих-коды, читаемые долгое время.', 'Риббон resin (смола), %1$s%3$s.'],
        'en' => ['<strong>Manufacturing:</strong> polypropylene and polyester labels.', '<strong>Logistics:</strong> labels that resist moisture and abrasion.', '<strong>Warehouse:</strong> barcodes that stay readable for a long time.', 'Resin ribbon, %1$s%3$s.'],
    ],
    'wash' => [
        'uz' => ['<strong>Tekstil ishlab chiqarish:</strong> parvarish va tarkib yorliqlari.', '<strong>Kiyim brendlari:</strong> yuvishga moʻljallangan yorliqlar.', '<strong>Tikuv sexlari:</strong> neylon va satin lentalarga chop etish.', 'Wash Care resin ribbon, %1$s%3$s.'],
        'ru' => ['<strong>Текстильное производство:</strong> этикетки по уходу и составу.', '<strong>Брендовая одежда:</strong> этикетки, рассчитанные на стирку.', '<strong>Швейные цеха:</strong> печать на нейлоновых и сатиновых лентах.', 'Риббон Wash Care resin, %1$s%3$s.'],
        'en' => ['<strong>Textile manufacturing:</strong> care and composition labels.', '<strong>Apparel brands:</strong> labels designed to withstand washing.', '<strong>Sewing workshops:</strong> printing on nylon and satin tapes.', 'Wash Care resin ribbon, %1$s%3$s.'],
    ],
    'deco' => [
        'uz' => ['<strong>Chakana savdo:</strong> ajralib turadigan premium yorliqlar.', '<strong>Qadoqlash:</strong> brend va sovgʻa mahsulotlari yorliqlari.', '<strong>Ishlab chiqarish:</strong> rangli kodlash va belgilash.', '%4$s, %1$s%3$s.'],
        'ru' => ['<strong>Розница:</strong> выделяющиеся премиальные этикетки.', '<strong>Упаковка:</strong> этикетки для брендов и подарочных товаров.', '<strong>Производство:</strong> цветовая кодировка и маркировка.', '%4$s, %1$s%3$s.'],
        'en' => ['<strong>Retail:</strong> premium labels that stand out.', '<strong>Packaging:</strong> labels for brands and gift items.', '<strong>Manufacturing:</strong> colour coding and marking.', '%4$s, %1$s%3$s.'],
    ],
    'tape' => [
        'uz' => ['<strong>Tikuv ishlab chiqarish:</strong> parvarish, tarkib va oʻlcham yorliqlari.', '<strong>Kiyim brendlari:</strong> mahsulot va brend yorliqlari.', '%4$s, kengligi %5$s mm, uzunligi 200 m%3$s.'],
        'ru' => ['<strong>Швейное производство:</strong> этикетки по уходу, составу и размеру.', '<strong>Брендовая одежда:</strong> товарные и брендовые этикетки.', '%4$s, ширина %5$s мм, длина 200 м%3$s.'],
        'en' => ['<strong>Garment manufacturing:</strong> care, composition and size labels.', '<strong>Apparel brands:</strong> product and brand labels.', '%4$s, %5$s mm wide, 200 m long%3$s.'],
    ],
];

// ─── Shared closing paragraphs ──────────────────────────────────────────────
$C = [
    'dt' => [
        'uz' => 'Uzoq saqlash, yorugʻlik yoki sovuqqa chidamlilik kerak boʻlsa, termotransfer yorliqni tanlang. Poson rulon va gilzani printeringiz modeliga moslab beradi, sinov chop etishni oʻtkazadi va doimiy buyurtmalar uchun ishonchli yetkazib berishni yoʻlga qoʻyadi.',
        'ru' => 'Если этикетке нужно хранение дольше, стойкость к свету или холоду, выбирайте термотрансферный вариант. Poson подберёт рулон и втулку под модель принтера, проведёт тестовую печать и наладит надёжные регулярные поставки.',
        'en' => 'If the label must last longer or resist light and cold, choose a thermal transfer label instead. Poson matches the roll and core to your printer, runs a test print and sets up dependable repeat deliveries.',
    ],
    'tt' => [
        'uz' => 'Termotransfer yorliq ribbon bilan juft ishlaydi: Poson yorliq materialiga mos ribbon (wax, wax-resin yoki resin)ni tanlaydi, printeringizni sozlaydi va sinov chop etishni oʻtkazadi. Rulon oʻlchami va gilzani printer modeliga moslab, muntazam yetkazib berishni taʼminlaymiz.',
        'ru' => 'Термотрансферная этикетка работает в паре с риббоном: Poson подберёт риббон (wax, wax-resin или resin) под материал этикетки, настроит принтер и проведёт тестовую печать. Размер рулона и втулку согласуем с моделью принтера и обеспечим регулярные поставки.',
        'en' => 'A thermal transfer label works as a pair with a ribbon: Poson selects the right ribbon (wax, wax-resin or resin) for the label material, configures your printer and runs a test print. We match roll size and core to your printer model and keep deliveries regular.',
    ],
    'seal' => [
        'uz' => 'Material va yelim turini buyurtma paytida menejerlarimiz siz bilan aniqlashtiradi. Poson mos ribbonni tanlaydi, printerni sozlaydi va sinov chop etishni oʻtkazadi.',
        'ru' => 'Материал и тип клея наши менеджеры уточнят с вами при заказе. Poson подберёт подходящий риббон, настроит принтер и проведёт тестовую печать.',
        'en' => 'Our managers confirm the material and adhesive type with you when you order. Poson selects a suitable ribbon, configures the printer and runs a test print.',
    ],
    'band' => [
        'uz' => 'Poson brasletni chop etish uchun printer va rulon turini klinikangiz jarayoniga moslab tanlaydi, sozlaydi va sinovdan oʻtkazadi. Rulondagi brasletlar soni va gilza buyurtma paytida aniqlashtiriladi.',
        'ru' => 'Poson подберёт принтер и тип рулона для печати браслетов под процессы вашей клиники, настроит и протестирует их. Количество браслетов в рулоне и втулку уточним при заказе.',
        'en' => 'Poson selects the printer and roll type for printing wristbands to fit your clinic workflow, then configures and tests them. The number of bands per roll and the core are confirmed when you order.',
    ],
    'paper' => [
        'uz' => 'Poson printer modeliga mos rulon oʻlchamini tanlaydi, kenglik va diametrning mosligini oldindan tekshiradi hamda kassa lentasiz qolmasligi uchun muntazam yetkazib berishni tashkil qiladi. Printerni sozlash yoki tozalash kerak boʻlsa, oʻz servis markazimiz yordam beradi.',
        'ru' => 'Poson подберёт размер рулона под модель принтера, заранее проверит совместимость по ширине и диаметру и организует регулярные поставки, чтобы касса не осталась без ленты. Если принтеру нужна настройка или чистка, поможет наш сервисный центр.',
        'en' => 'Poson matches the roll size to your printer model, checks width and diameter fit in advance and arranges regular deliveries so your tills never run out. If a printer needs adjustment or cleaning, our service center can help.',
    ],
    'ribbon' => [
        'uz' => 'Ribbon yorliq materialiga mos boʻlishi kerak: Poson yorliq va printeringiz modeliga qarab juftlikni tanlaydi, ribbon oʻrami (gilza va boʻyoq qatlami tomoni)ni tekshiradi va sinov chop etishni oʻtkazadi. Toʻgʻri juftlik chop etish aniqligini va kallakning xizmat muddatini saqlaydi.',
        'ru' => 'Риббон должен соответствовать материалу этикетки: Poson подберёт пару под этикетку и модель принтера, проверит намотку (втулку и сторону красящего слоя) и проведёт тестовую печать. Правильная пара сохраняет чёткость печати и ресурс печатающей головки.',
        'en' => 'A ribbon has to match the label material: Poson pairs it with your label and printer model, checks the winding (core and ink side) and runs a test print. A correct pair keeps print sharp and protects the printhead.',
    ],
    'tape' => [
        'uz' => 'Eng yaxshi natija uchun Poson lentani Wash Care resin ribbon (masalan, TDR370) va mos termotransfer printer bilan juftlaydi, printerni sozlaydi va sinov chop etishni oʻtkazadi.',
        'ru' => 'Для лучшего результата Poson подберёт к ленте риббон Wash Care resin (например, TDR370) и подходящий термотрансферный принтер, настроит его и проведёт тестовую печать.',
        'en' => 'For the best result Poson pairs the tape with a Wash Care resin ribbon (for example TDR370) and a suitable thermal transfer printer, configures it and runs a test print.',
    ],
];

// ─── Family text templates ──────────────────────────────────────────────────
// sprintf args: 1 size, 2 " (grade)", 3 ", grade", 4 kind name, 5 width, 6 coreless note.
$cardT = static fn (array $c): array => [
    'name' => ['uz' => 'Termokarton yorliqlar, '.$c['uz'], 'ru' => 'Термокартонные бирки, '.$c['ru'], 'en' => 'Thermal cardboard tags, '.$c['en']],
    'short' => [
        'uz' => 'Karton asosli termo yorliqlar %1$s, '.$c['uz'].': ribbonsiz chop etiladi, narx va tovar yorliqlari uchun.',
        'ru' => 'Термокартонные бирки %1$s, '.$c['ru'].': печать без риббона, для ценников и товарных бирок.',
        'en' => 'Thermal cardboard tags %1$s, '.$c['en'].': ribbon-free printing for price and product tags.',
    ],
    'meta' => [
        'uz' => 'Termokarton yorliqlar %1$s, '.$c['uz'].': ribbonsiz chop etish. Poson printerga mos rulon tanlab beradi.',
        'ru' => 'Термокартонные бирки %1$s, '.$c['ru'].': печать без риббона. Poson подберёт рулон под ваш принтер.',
        'en' => 'Thermal cardboard tags %1$s, '.$c['en'].': no ribbon needed. Poson matches the roll to your printer.',
    ],
    'intro' => [
        'uz' => '<strong>%1$s termokarton yorliqlar</strong> ('.$c['uz'].') Poson ishlab chiqarishida tayyorlanadi. Karton asosga ribbonsiz, printer kallagining issiqligi bilan chop etiladi.',
        'ru' => '<strong>Термокартонные бирки %1$s</strong> ('.$c['ru'].') выпускаются в производстве Poson. Печать на картонной основе выполняется без риббона, нагревом печатающей головки.',
        'en' => '<strong>%1$s thermal cardboard tags</strong> ('.$c['en'].') are made by Poson. They print on cardboard stock without a ribbon, using the heat of the printhead.',
    ],
    'uses' => 'card', 'close' => 'dt', 'dim' => 'wh', 'label' => true, 'spec' => 'wh',
];

$G = [
    'dt' => [
        'name' => ['uz' => 'Termoyorliqlar', 'ru' => 'Термоэтикетки', 'en' => 'Direct thermal labels'],
        'short' => [
            'uz' => 'Poson ishlab chiqargan %1$s termoyorliqlar: ribbonsiz chop etiladi, tarozi, narx yorliqlari va ombor belgilash uchun.',
            'ru' => 'Термоэтикетки %1$s производства Poson: печать без риббона для весов, ценников и складской маркировки.',
            'en' => 'Poson-made %1$s direct thermal labels: ribbon-free printing for scales, price tags and warehouse marking.',
        ],
        'meta' => [
            'uz' => 'Termoyorliqlar %1$s, ribbonsiz chop etish: tarozi, narx yorliqlari, ombor. Poson printeringizga mos rulon tanlab beradi.',
            'ru' => 'Термоэтикетки %1$s без риббона: для весов, ценников и склада. Poson подберёт рулон под ваш принтер и обеспечит поставки.',
            'en' => 'Direct thermal labels %1$s, no ribbon needed: scales, price tags, warehouse. Poson matches the roll to your printer.',
        ],
        'intro' => [
            'uz' => '<strong>%1$s termoyorliqlar</strong> Poson ishlab chiqarishida tayyorlanadi va ribbonsiz, printer kallagining issiqligi bilan chop etiladi. Ribbon sarflanmaydi, printerga xizmat koʻrsatish soddalashadi.',
            'ru' => '<strong>Термоэтикетки %1$s</strong> выпускаются в производстве Poson и печатаются без риббона — за счёт нагрева печатающей головки. Риббон не расходуется, обслуживание принтера проще.',
            'en' => '<strong>%1$s direct thermal labels</strong> are made by Poson and print without a ribbon, using only the heat of the printhead. There is no ribbon to buy and printer upkeep stays simple.',
        ],
        'uses' => 'dt', 'close' => 'dt', 'dim' => 'wh', 'label' => true, 'spec' => 'paper',
    ],
    'dtlogo' => [
        'name' => ['uz' => 'Logotipli termoyorliqlar', 'ru' => 'Термоэтикетки с логотипом', 'en' => 'Direct thermal labels with logo'],
        'short' => [
            'uz' => 'Logotip bosilgan %1$s termoyorliqlar, Poson ishlab chiqarishi: brendli narx va tovar yorliqlari, ribbonsiz chop etish.',
            'ru' => 'Термоэтикетки %1$s с нанесённым логотипом, производство Poson: фирменные ценники и этикетки, печать без риббона.',
            'en' => 'Poson-made %1$s direct thermal labels with a pre-printed logo: branded price and product labels, no ribbon needed.',
        ],
        'meta' => [
            'uz' => 'Logotipli termoyorliqlar %1$s: brendli yorliqlar, ribbonsiz chop etish. Poson printerga mos rulon tanlab beradi.',
            'ru' => 'Термоэтикетки с логотипом %1$s: фирменные этикетки, печать без риббона. Poson подберёт рулон под принтер.',
            'en' => 'Direct thermal labels with logo %1$s: branded labels, no ribbon needed. Poson matches the roll to your printer.',
        ],
        'intro' => [
            'uz' => '<strong>%1$s logotipli termoyorliqlar</strong> — Poson ishlab chiqarishidagi termoyorliq, unga logotip oldindan bosilgan. Ribbonsiz chop etiladi va brendli narx hamda tovar yorliqlari uchun mos. Logotip varianti tafsilotlarini menejerimiz bilan aniqlashtiring.',
            'ru' => '<strong>Термоэтикетки %1$s с логотипом</strong> — этикетка производства Poson с заранее нанесённым логотипом. Печатается без риббона и подходит для фирменных ценников и товарных этикеток. Детали варианта с логотипом уточняйте у менеджера.',
            'en' => '<strong>%1$s direct thermal labels with logo</strong> are Poson-made labels with a pre-printed logo. They print without a ribbon and suit branded price and product labels. Ask your manager to confirm the details of the logo version.',
        ],
        'uses' => 'dt', 'close' => 'dt', 'dim' => 'wh', 'label' => true, 'spec' => 'paper',
    ],
    'syn' => [
        'name' => ['uz' => 'Sintetik termoyorliqlar (PP)', 'ru' => 'Синтетические термоэтикетки (PP)', 'en' => 'Synthetic PP direct thermal labels'],
        'short' => [
            'uz' => 'Sintetik PP termoyorliqlar %1$s: qogʻozdan koʻra namlik va ishqalanishga chidamliroq, ribbonsiz chop etiladi.',
            'ru' => 'Синтетические термоэтикетки PP %1$s: устойчивее к влаге и трению, чем бумажные, печать без риббона.',
            'en' => 'Synthetic polypropylene (PP) direct thermal labels %1$s: tougher than paper against moisture and abrasion, no ribbon.',
        ],
        'meta' => [
            'uz' => 'Sintetik termoyorliqlar PP %1$s: namlik va ishqalanishga chidamli, ribbonsiz chop etish. Poson printerga mos rulon tanlaydi.',
            'ru' => 'Синтетические термоэтикетки PP %1$s: стойкие к влаге и трению, печать без риббона. Poson подберёт рулон под принтер.',
            'en' => 'Synthetic PP direct thermal labels %1$s: resist moisture and abrasion, no ribbon needed. Poson matches the roll to your printer.',
        ],
        'intro' => [
            'uz' => '<strong>%1$s sintetik termoyorliqlar</strong> polipropilen (PP) asosida tayyorlangan va ribbonsiz chop etiladi. Sintetik asos qogʻozga qaraganda namlik va ishqalanishga chidamliroq, shuning uchun yorliq qiyin sharoitda ham oʻqiladigan boʻlib qoladi.',
            'ru' => '<strong>Синтетические термоэтикетки %1$s</strong> изготовлены на основе полипропилена (PP) и печатаются без риббона. Синтетическая основа лучше бумаги переносит влагу и трение, поэтому этикетка остаётся читаемой и в непростых условиях.',
            'en' => '<strong>%1$s synthetic direct thermal labels</strong> are made on a polypropylene (PP) base and print without a ribbon. A synthetic face copes with moisture and rubbing better than paper, so the label stays readable in tougher conditions.',
        ],
        'uses' => 'syn', 'close' => 'dt', 'dim' => 'wh', 'label' => true, 'spec' => 'pp',
    ],
    'synrem' => [
        'name' => ['uz' => 'Sintetik termoyorliqlar (PP), olinadigan yelim', 'ru' => 'Синтетические термоэтикетки (PP), съёмный клей', 'en' => 'Synthetic PP thermal labels, removable adhesive'],
        'short' => [
            'uz' => 'Sintetik PP termoyorliqlar %1$s, oson olinadigan yelim: vaqtinchalik belgilash uchun, ribbonsiz chop etiladi.',
            'ru' => 'Синтетические термоэтикетки PP %1$s со съёмным клеем: для временной маркировки, печать без риббона.',
            'en' => 'Synthetic PP direct thermal labels %1$s with removable adhesive: for temporary marking, no ribbon needed.',
        ],
        'meta' => [
            'uz' => 'Sintetik termoyorliqlar PP %1$s, olinadigan yelim: vaqtinchalik belgilash, ribbonsiz chop etish. Poson rulon tanlab beradi.',
            'ru' => 'Синтетические термоэтикетки PP %1$s со съёмным клеем: временная маркировка, без риббона. Poson подберёт рулон.',
            'en' => 'Synthetic PP thermal labels %1$s with removable adhesive: temporary marking, no ribbon. Poson matches the roll to your printer.',
        ],
        'intro' => [
            'uz' => '<strong>%1$s sintetik termoyorliqlar</strong> polipropilen (PP) asosida, oson olinadigan (removable) yelim bilan. Ribbonsiz chop etiladi; yorliqni keyinchalik olib tashlash kerak boʻlgan vaqtinchalik belgilash uchun mos.',
            'ru' => '<strong>Синтетические термоэтикетки %1$s</strong> на основе полипропилена (PP) со съёмным (removable) клеем. Печатаются без риббона; подходят для временной маркировки, когда этикетку потом нужно снять.',
            'en' => '<strong>%1$s synthetic direct thermal labels</strong> on a polypropylene (PP) base with removable adhesive. They print without a ribbon and suit temporary marking where the label is taken off later.',
        ],
        'uses' => 'syn', 'close' => 'dt', 'dim' => 'wh', 'label' => true, 'spec' => 'pp', 'removable' => true,
    ],
    'cardw' => $cardT(['uz' => 'oq', 'ru' => 'белые', 'en' => 'white']),
    'cardc' => $cardT(['uz' => 'rangli', 'ru' => 'цветные', 'en' => 'coloured']),
    'band' => [
        'name' => ['uz' => 'Tibbiy termobrasletlar (sintetik)', 'ru' => 'Медицинские термобраслеты (синтетика)', 'en' => 'Medical thermal wristbands (synthetic)'],
        'short' => [
            'uz' => 'Bemorni identifikatsiya qilish uchun sintetik termobrasletlar %1$s: klinika va shifoxonalarda ribbonsiz chop etiladi.',
            'ru' => 'Синтетические термобраслеты %1$s для идентификации пациентов в клиниках и больницах, печать без риббона.',
            'en' => 'Synthetic thermal wristbands %1$s for patient identification in clinics and hospitals, printed without a ribbon.',
        ],
        'meta' => [
            'uz' => 'Tibbiy termobrasletlar %1$s, sintetik: bemorni identifikatsiya qilish, ribbonsiz chop etish. Poson printer va rulonni tanlaydi.',
            'ru' => 'Медицинские термобраслеты %1$s, синтетика: идентификация пациентов, печать без риббона. Poson подберёт принтер и рулон.',
            'en' => 'Medical thermal wristbands %1$s, synthetic: patient identification, no ribbon needed. Poson selects the printer and roll.',
        ],
        'intro' => [
            'uz' => '<strong>%1$s tibbiy termobrasletlar</strong> sintetik termomaterialdan (valeron) tayyorlangan va Poson ishlab chiqarishida chiqariladi. Brasletga bemor maʼlumoti va shtrix-kod ribbonsiz chop etiladi.',
            'ru' => '<strong>Медицинские термобраслеты %1$s</strong> изготовлены из синтетического термоматериала (валерон) и выпускаются в производстве Poson. Данные пациента и штрих-код печатаются на браслете без риббона.',
            'en' => '<strong>%1$s medical thermal wristbands</strong> are made from synthetic thermal material (valeron) and produced by Poson. Patient data and a barcode print on the band without a ribbon.',
        ],
        'uses' => 'band', 'close' => 'band', 'dim' => 'wh', 'label' => true, 'spec' => 'wh', 'missing' => true,
    ],
    'gpp' => [
        'name' => ['uz' => 'Yaltiroq PP termotransfer yorliqlar', 'ru' => 'Этикетки из глянцевого PP', 'en' => 'Gloss PP thermal transfer labels'],
        'short' => [
            'uz' => 'Oq yaltiroq polipropilen (PP) yorliqlar %1$s: termotransfer chop etish, namlik va ishqalanishga chidamli.',
            'ru' => 'Белые этикетки из глянцевого полипропилена (PP) %1$s: термотрансферная печать, стойкость к влаге и истиранию.',
            'en' => 'White gloss polypropylene (PP) labels %1$s for thermal transfer printing, resistant to moisture and abrasion.',
        ],
        'meta' => [
            'uz' => 'Yaltiroq PP termotransfer yorliqlar %1$s, oq: namlik va ishqalanishga chidamli. Poson ribbon va printerga mos tanlov beradi.',
            'ru' => 'Этикетки из глянцевого PP %1$s, белые, термотрансфер: стойкие к влаге и трению. Poson подберёт риббон и рулон под принтер.',
            'en' => 'White gloss PP thermal transfer labels %1$s: moisture and abrasion resistant. Poson matches ribbon and roll to your printer.',
        ],
        'intro' => [
            'uz' => '<strong>%1$s yaltiroq PP yorliqlar</strong> oq polipropilen (PP) plyonkadan tayyorlangan va ribbon orqali termotransfer usulida chop etiladi. Plyonka namlik va ishqalanishga qogʻozdan yaxshiroq bardosh beradi, chop etilgan tasvir esa uzoq vaqt oʻqiladi.',
            'ru' => '<strong>Этикетки из глянцевого PP %1$s</strong> изготовлены из белой полипропиленовой (PP) плёнки и печатаются термотрансферным способом через риббон. Плёнка лучше бумаги переносит влагу и трение, а отпечаток долго остаётся читаемым.',
            'en' => '<strong>%1$s gloss PP labels</strong> are made from white polypropylene (PP) film and printed by thermal transfer through a ribbon. The film copes with moisture and rubbing better than paper, and the print stays readable for a long time.',
        ],
        'uses' => 'gpp', 'close' => 'tt', 'dim' => 'wh', 'label' => true, 'spec' => 'pp',
    ],
    'gppsilver' => [
        'name' => ['uz' => 'Kumush PP termotransfer yorliqlar', 'ru' => 'Этикетки из серебристого PP', 'en' => 'Silver PP thermal transfer labels'],
        'short' => [
            'uz' => 'Kumush rangli polipropilen (PP) yorliqlar %1$s: termotransfer chop etish, namlik va ishqalanishga chidamli.',
            'ru' => 'Этикетки из серебристого полипропилена (PP) %1$s: термотрансферная печать, стойкость к влаге и истиранию.',
            'en' => 'Silver polypropylene (PP) labels %1$s for thermal transfer printing, resistant to moisture and abrasion.',
        ],
        'meta' => [
            'uz' => 'Kumush PP termotransfer yorliqlar %1$s: namlik va ishqalanishga chidamli. Poson ribbon va printerga mos tanlov beradi.',
            'ru' => 'Этикетки из серебристого PP %1$s, термотрансфер: стойкие к влаге и трению. Poson подберёт риббон под принтер.',
            'en' => 'Silver PP thermal transfer labels %1$s: moisture and abrasion resistant. Poson matches ribbon and roll to your printer.',
        ],
        'intro' => [
            'uz' => '<strong>%1$s kumush PP yorliqlar</strong> kumush rangli polipropilen (PP) plyonkadan tayyorlangan va ribbon orqali termotransfer usulida chop etiladi. Metall koʻrinishli plyonka tovar va texnik yorliqlarni ajratib koʻrsatadi.',
            'ru' => '<strong>Этикетки из серебристого PP %1$s</strong> изготовлены из полипропиленовой (PP) плёнки серебристого цвета и печатаются термотрансферным способом через риббон. Плёнка с металлическим видом выделяет товарные и технические этикетки.',
            'en' => '<strong>%1$s silver PP labels</strong> are made from silver-coloured polypropylene (PP) film and printed by thermal transfer through a ribbon. The metallic-look film makes product and technical labels stand out.',
        ],
        'uses' => 'gpp', 'close' => 'tt', 'dim' => 'wh', 'label' => true, 'spec' => 'pp',
    ],
    'coat' => [
        'name' => ['uz' => 'Termotransfer yorliqlar, qoplamali qogʻoz', 'ru' => 'Этикетки из мелованной бумаги', 'en' => 'Coated paper thermal transfer labels'],
        'short' => [
            'uz' => 'Qoplamali qogʻoz yorliqlar %1$s: termotransfer chop etish, wax yoki wax-resin ribbon bilan, tovar va ombor yorliqlari uchun.',
            'ru' => 'Этикетки из мелованной бумаги %1$s: термотрансферная печать с риббоном wax или wax-resin, для товарной и складской маркировки.',
            'en' => 'Coated paper labels %1$s for thermal transfer printing with a wax or wax-resin ribbon, for product and warehouse marking.',
        ],
        'meta' => [
            'uz' => 'Termotransfer yorliqlar %1$s, qoplamali qogʻoz: tovar va ombor yorliqlari, wax yoki wax-resin ribbon bilan. Poson printerga mos tanlaydi.',
            'ru' => 'Этикетки из мелованной бумаги %1$s, термотрансфер: товарная и складская маркировка, риббон wax или wax-resin. Подбор под принтер.',
            'en' => 'Coated paper thermal transfer labels %1$s for product and warehouse marking, with wax or wax-resin ribbon. Matched to your printer.',
        ],
        'intro' => [
            'uz' => '<strong>%1$s qoplamali qogʻoz yorliqlar</strong> Poson ishlab chiqarishida tayyorlanadi va ribbon orqali termotransfer usulida chop etiladi. Tasvir vaqt oʻtishi bilan oʻchmaydi va issiqlik yoki yorugʻlikdan qorayib ketmaydi.',
            'ru' => '<strong>Этикетки из мелованной бумаги %1$s</strong> выпускаются в производстве Poson и печатаются термотрансферным способом через риббон. Изображение не выцветает со временем и не темнеет от тепла и света.',
            'en' => '<strong>%1$s coated paper labels</strong> are made by Poson and printed by thermal transfer through a ribbon. The image does not fade over time or darken with heat and light.',
        ],
        'uses' => 'coat', 'close' => 'tt', 'dim' => 'wh', 'label' => true, 'spec' => 'coatpaper',
    ],
    'coatlogo' => [
        'name' => ['uz' => 'Logotipli termotransfer yorliqlar, qoplamali qogʻoz', 'ru' => 'Этикетки из мелованной бумаги с логотипом', 'en' => 'Coated paper thermal transfer labels with logo'],
        'short' => [
            'uz' => 'Logotip bosilgan qoplamali qogʻoz yorliqlar %1$s: termotransfer chop etish, brendli tovar yorliqlari uchun.',
            'ru' => 'Этикетки из мелованной бумаги %1$s с нанесённым логотипом: термотрансферная печать для фирменной маркировки.',
            'en' => 'Coated paper labels %1$s with a pre-printed logo for thermal transfer printing, for branded product labels.',
        ],
        'meta' => [
            'uz' => 'Logotipli termotransfer yorliqlar %1$s, qoplamali qogʻoz: brendli tovar yorliqlari. Poson ribbon va printerga mos tanlaydi.',
            'ru' => 'Этикетки с логотипом %1$s из мелованной бумаги, термотрансфер: фирменная маркировка. Poson подберёт риббон и принтер.',
            'en' => 'Coated paper thermal transfer labels with logo %1$s for branded product labels. Poson matches ribbon and printer.',
        ],
        'intro' => [
            'uz' => '<strong>%1$s logotipli qoplamali qogʻoz yorliqlar</strong> — Poson ishlab chiqarishidagi termotransfer yorliq, unga logotip oldindan bosilgan. Brendli tovar yorliqlari uchun mos; logotip varianti tafsilotlarini menejerimiz bilan aniqlashtiring.',
            'ru' => '<strong>Этикетки из мелованной бумаги %1$s с логотипом</strong> — термотрансферная этикетка производства Poson с заранее нанесённым логотипом. Подходит для фирменной маркировки; детали варианта с логотипом уточняйте у менеджера.',
            'en' => '<strong>%1$s coated paper labels with logo</strong> are Poson-made thermal transfer labels with a pre-printed logo. They suit branded product labels; ask your manager to confirm the details of the logo version.',
        ],
        'uses' => 'coat', 'close' => 'tt', 'dim' => 'wh', 'label' => true, 'spec' => 'coatpaper',
    ],
    'seal' => [
        'name' => ['uz' => 'Plombirovkali (muhrlovchi) yorliqlar', 'ru' => 'Пломбировочные этикетки', 'en' => 'Tamper-evident seal labels'],
        'short' => [
            'uz' => 'Plombirovkali yorliqlar %1$s: quti va yuklarni muhrlash, ochilganini nazorat qilish. Material buyurtma paytida aniqlashtiriladi.',
            'ru' => 'Пломбировочные этикетки %1$s для опломбирования коробок и грузов и контроля вскрытия. Материал уточняется при заказе.',
            'en' => 'Tamper-evident seal labels %1$s for sealing cartons and shipments and detecting opening. Material confirmed when you order.',
        ],
        'meta' => [
            'uz' => 'Plombirovkali yorliqlar %1$s: quti va yuklarni muhrlash, ochilganini nazorat qilish. Poson mos material va ribbonni tanlab beradi.',
            'ru' => 'Пломбировочные этикетки %1$s: опломбирование коробок и грузов, контроль вскрытия. Poson подберёт материал и риббон.',
            'en' => 'Tamper-evident seal labels %1$s for sealing cartons and shipments. Poson helps select the right material and ribbon.',
        ],
        'intro' => [
            'uz' => '<strong>%1$s plombirovkali yorliqlar</strong> (Poson ishlab chiqarishi) quti, konteyner va yuklarning ochilganini nazorat qilish uchun moʻljallangan. Yorliq ustiga ribbon yordamida termotransfer usulida chop etiladi.',
            'ru' => '<strong>Пломбировочные этикетки %1$s</strong> (производство Poson) предназначены для контроля вскрытия коробок, контейнеров и грузов. Печать выполняется термотрансферным способом через риббон.',
            'en' => '<strong>%1$s tamper-evident seal labels</strong> (made by Poson) are designed to show whether cartons, containers or shipments have been opened. They are printed by thermal transfer through a ribbon.',
        ],
        'uses' => 'seal', 'close' => 'seal', 'dim' => 'wh', 'label' => true, 'spec' => 'wh', 'missing' => true,
    ],
    'paper' => [
        'name' => ['uz' => 'Termo chek lentasi', 'ru' => 'Термочековая лента', 'en' => 'Thermal receipt paper roll'],
        'short' => [
            'uz' => '%1$s termo chek lentasi (48 g/m²), Poson ishlab chiqarishi: kassa, chek printerlari va kioskalar uchun.',
            'ru' => 'Термочековая лента %1$s (48 г/м²) производства Poson: для касс, чековых принтеров и киосков.',
            'en' => 'Poson-made thermal receipt paper %1$s (48 g/m²) for tills, receipt printers and kiosks.',
        ],
        'meta' => [
            'uz' => 'Termo chek lentasi %1$s, 48 g/m²: kassa, chek printerlari va kioskalar uchun. Poson printerga mos oʻlcham va yetkazib berish.',
            'ru' => 'Термочековая лента %1$s, 48 г/м²: для касс, чековых принтеров и киосков. Poson подберёт размер и обеспечит поставки.',
            'en' => 'Thermal receipt paper %1$s, 48 g/m², for tills, receipt printers and kiosks. Poson matches the size and supplies regularly.',
        ],
        'intro' => [
            'uz' => '<strong>%1$s termo chek lentasi</strong> — 48 g/m² termoqogʻoz, Poson ishlab chiqarishi. Lenta ribbonsiz chop etadigan kassa va chek printerlari, kioskalar hamda terminallar uchun.%6$s',
            'ru' => '<strong>Термочековая лента %1$s</strong> — термобумага 48 г/м² производства Poson. Лента для кассовых и чековых принтеров, киосков и терминалов, печатающих без риббона.%6$s',
            'en' => '<strong>%1$s thermal receipt paper</strong> is 48 g/m² thermal paper made by Poson. The roll is for till and receipt printers, kiosks and terminals that print without a ribbon.%6$s',
        ],
        'uses' => 'paper', 'close' => 'paper', 'dim' => 'wl', 'label' => false, 'spec' => 'receipt',
    ],
];

// Ribbon families: [names, short, meta, intro, uses set, ribbon_type option, extra].
$ribbonTexts = [
    'wax' => [
        'short' => [
            'uz' => '%4$s %1$s: qogʻoz yorliqlar uchun termotransfer ribbon. Poson yorliq va printerga mos juftlikni tanlaydi.',
            'ru' => '%4$s %1$s для бумажных этикеток на термотрансферных принтерах. Poson подберёт пару под этикетку и принтер.',
            'en' => '%4$s %1$s for paper labels on thermal transfer printers. Poson pairs it with your label and printer.',
        ],
        'meta' => [
            'uz' => '%4$s %1$s: qogʻoz yorliqlar uchun termotransfer ribbon. Poson yorliq, printer va oʻramni tekshirib tanlaydi.',
            'ru' => '%4$s %1$s для бумажных этикеток. Poson проверит принтер, этикетку и намотку и подберёт подходящий риббон.',
            'en' => '%4$s %1$s for paper labels. Poson checks printer, label and winding and picks the right ribbon.',
        ],
        'intro' => [
            'uz' => '<strong>%4$s %1$s</strong>%2$s — qogʻoz yorliqlarga termotransfer chop etish uchun. Wax qatlam aniq shtrix-kod va matn beradi; bu eng keng qoʻllaniladigan ribbon turi.',
            'ru' => '<strong>%4$s %1$s</strong>%2$s — для термотрансферной печати на бумажных этикетках. Восковой слой даёт чёткие штрих-коды и текст; это самый распространённый тип риббона.',
            'en' => '<strong>%4$s %1$s</strong>%2$s is for thermal transfer printing on paper labels. The wax layer gives crisp barcodes and text and is the most widely used ribbon type.',
        ],
        'uses' => 'wax',
    ],
    'wr' => [
        'short' => [
            'uz' => '%4$s %1$s: qoplamali qogʻoz va yarim sintetik yorliqlar uchun, ishqalanishga chidamliroq. Poson juftlikni tanlaydi.',
            'ru' => '%4$s %1$s для этикеток из мелованной бумаги и полусинтетики, лучше держит истирание. Poson подберёт пару.',
            'en' => '%4$s %1$s for coated paper and semi-synthetic labels, more rub-resistant than wax. Poson picks the pair.',
        ],
        'meta' => [
            'uz' => '%4$s %1$s: qoplamali qogʻoz va yarim sintetik yorliqlar, ishqalanishga chidamliroq chop etish. Poson yorliq va printerga mos tanlaydi.',
            'ru' => '%4$s %1$s для мелованной бумаги и полусинтетики, печать устойчивее к истиранию. Poson подберёт под этикетку и принтер.',
            'en' => '%4$s %1$s for coated paper and semi-synthetic labels, with print more resistant to rubbing. Matched to label and printer.',
        ],
        'intro' => [
            'uz' => '<strong>%4$s %1$s</strong>%2$s — qoplamali qogʻoz va yarim sintetik yorliqlar uchun. Tarkibdagi resin tasvirni sof wax ribbonga qaraganda ishqalanishga chidamliroq qiladi.',
            'ru' => '<strong>%4$s %1$s</strong>%2$s — для этикеток из мелованной бумаги и полусинтетики. Смола в составе делает отпечаток устойчивее к истиранию, чем у чистого wax-риббона.',
            'en' => '<strong>%4$s %1$s</strong>%2$s is for coated paper and semi-synthetic labels. The resin content makes the print more resistant to rubbing than a pure wax ribbon.',
        ],
        'uses' => 'wr',
    ],
    'res' => [
        'short' => [
            'uz' => '%4$s %1$s: sintetik (PP, PET) yorliqlar uchun, ishqalanish va namlikka chidamli chop etish. Poson juftlikni tanlaydi.',
            'ru' => '%4$s %1$s для синтетических (PP, PET) этикеток: отпечаток стоек к истиранию и влаге. Poson подберёт пару.',
            'en' => '%4$s %1$s for synthetic (PP, PET) labels, giving print that resists abrasion and moisture. Poson picks the pair.',
        ],
        'meta' => [
            'uz' => '%4$s %1$s: sintetik PP va PET yorliqlar uchun, ishqalanish va namlikka chidamli. Poson yorliq va printerga mos tanlab beradi.',
            'ru' => '%4$s %1$s для синтетических этикеток PP и PET, стойкий к истиранию и влаге. Poson подберёт под этикетку и принтер.',
            'en' => '%4$s %1$s for synthetic PP and PET labels, resistant to abrasion and moisture. Poson matches it to label and printer.',
        ],
        'intro' => [
            'uz' => '<strong>%4$s %1$s</strong>%2$s — sintetik (PP, PET) va yuqori talabli yorliqlar uchun. Resin tasvir ishqalanish, namlik va kimyoviy taʼsirga eng chidamli ribbon turi hisoblanadi.',
            'ru' => '<strong>%4$s %1$s</strong>%2$s — для синтетических (PP, PET) этикеток и повышенных требований. Resin даёт самый стойкий к истиранию, влаге и химии отпечаток среди типов риббона.',
            'en' => '<strong>%4$s %1$s</strong>%2$s is for synthetic (PP, PET) labels and demanding conditions. Resin gives the print that best resists abrasion, moisture and chemicals among ribbon types.',
        ],
        'uses' => 'res',
    ],
    'wash' => [
        'short' => [
            'uz' => '%4$s %1$s: neylon va satin lentalarga tekstil parvarish yorliqlarini chop etish uchun. Poson lenta va printerni juftlaydi.',
            'ru' => '%4$s %1$s для печати этикеток по уходу на нейлоновых и сатиновых лентах. Poson подберёт ленту и принтер.',
            'en' => '%4$s %1$s for printing care labels on nylon and satin tapes. Poson pairs the tape and the printer.',
        ],
        'meta' => [
            'uz' => '%4$s %1$s: neylon va satin lentalarga parvarish yorliqlarini chop etish. Poson lenta, printer va ribbonni juftlab beradi.',
            'ru' => '%4$s %1$s для печати этикеток по уходу на нейлоновых и сатиновых лентах. Poson подберёт ленту, принтер и риббон.',
            'en' => '%4$s %1$s for printing care labels on nylon and satin tapes. Poson pairs tape, printer and ribbon.',
        ],
        'intro' => [
            'uz' => '<strong>%4$s %1$s</strong>%2$s — tekstil parvarish va tarkib yorliqlari uchun: neylon va satin lentalarga chop etilgan tasvir yuvishga chidamli boʻlishi uchun moʻljallangan.',
            'ru' => '<strong>%4$s %1$s</strong>%2$s — для текстильных этикеток по уходу и составу: отпечаток на нейлоновых и сатиновых лентах рассчитан на стойкость к стирке.',
            'en' => '<strong>%4$s %1$s</strong>%2$s is for textile care and composition labels: print on nylon and satin tapes is designed to withstand washing.',
        ],
        'uses' => 'wash',
    ],
    'deco' => [
        'short' => [
            'uz' => '%4$s %1$s: rangli yoki metall effektli yorliqlar uchun termotransfer ribbon. Poson yorliq va printerga mos tanlaydi.',
            'ru' => '%4$s %1$s для цветных и металлизированных этикеток на термотрансферных принтерах. Poson подберёт под этикетку и принтер.',
            'en' => '%4$s %1$s for coloured and metallic-effect labels on thermal transfer printers. Matched to label and printer by Poson.',
        ],
        'meta' => [
            'uz' => '%4$s %1$s: rangli va metall effektli yorliqlar uchun termotransfer ribbon. Poson yorliq, printer va oʻramni tekshirib tanlaydi.',
            'ru' => '%4$s %1$s для цветных и металлизированных этикеток. Poson проверит принтер, этикетку и намотку и подберёт риббон.',
            'en' => '%4$s %1$s for coloured and metallic-effect labels. Poson checks printer, label and winding before recommending it.',
        ],
        'intro' => [
            'uz' => '<strong>%4$s %1$s</strong>%2$s — rangli yoki metall effektli yorliqlar uchun: qadoq, brend va sovgʻa mahsulotlarida ajralib turadigan chop etish.',
            'ru' => '<strong>%4$s %1$s</strong>%2$s — для цветных и металлизированных этикеток: выделяющаяся печать на упаковке, брендовой и подарочной продукции.',
            'en' => '<strong>%4$s %1$s</strong>%2$s is for coloured or metallic-effect labels: print that stands out on packaging, branded and gift items.',
        ],
        'uses' => 'deco',
    ],
];

$ribbon = static fn (array $names, string $group, string $type) => $ribbonTexts[$group] + [
    'name' => $names, 'close' => 'ribbon', 'dim' => 'wl', 'label' => false, 'spec' => 'ribbon',
    'type' => $type, 'ng' => true,
];

$WAX = 'Wax (mum)';
$WR = 'Wax-resin (mum-smola)';
$RES = 'Resin (smola)';

$G += [
    'wax' => $ribbon(['uz' => 'Wax ribbon', 'ru' => 'Риббон wax', 'en' => 'Wax ribbon'], 'wax', $WAX),
    'waxc' => $ribbon(['uz' => 'Rangli wax ribbon (red/green)', 'ru' => 'Цветной риббон wax (red/green)', 'en' => 'Coloured wax ribbon (red/green)'], 'deco', $WAX),
    'wr' => $ribbon(['uz' => 'Wax-resin ribbon', 'ru' => 'Риббон wax-resin', 'en' => 'Wax-resin ribbon'], 'wr', $WR),
    'wrg' => $ribbon(['uz' => 'Yashil wax-resin ribbon', 'ru' => 'Зелёный риббон wax-resin', 'en' => 'Green wax-resin ribbon'], 'deco', $WR),
    'res' => $ribbon(['uz' => 'Resin ribbon', 'ru' => 'Риббон resin', 'en' => 'Resin ribbon'], 'res', $RES),
    'resp' => $ribbon(['uz' => 'Premium Special resin ribbon', 'ru' => 'Риббон Premium Special resin', 'en' => 'Premium Special resin ribbon'], 'res', $RES),
    'resg' => $ribbon(['uz' => 'Oltin rangli resin ribbon', 'ru' => 'Золотой риббон resin', 'en' => 'Gold resin ribbon'], 'deco', $RES),
    'resm' => $ribbon(['uz' => 'Metall Gold/Silver resin ribbon', 'ru' => 'Металлик-риббон Gold/Silver resin', 'en' => 'Metallic Gold/Silver resin ribbon'], 'deco', $RES),
    'resk' => $ribbon(['uz' => 'Premium Pink/White resin ribbon', 'ru' => 'Риббон Premium Pink/White resin', 'en' => 'Premium Pink/White resin ribbon'], 'deco', $RES),
    'resf' => $ribbon(['uz' => 'Rangli foil/resin ribbon', 'ru' => 'Цветной риббон foil/resin', 'en' => 'Coloured foil/resin ribbon'], 'deco', $RES),
    'wash' => $ribbon(['uz' => 'Wash Care resin ribbon', 'ru' => 'Риббон Wash Care resin', 'en' => 'Wash Care resin ribbon'], 'wash', $RES),
];

// Textile tapes.
$tapeTexts = [
    'nylon' => [
        'short' => [
            'uz' => '%4$s %1$s: kiyimga tikiladigan parvarish va tarkib yorliqlari uchun neylon lenta, termotransfer printerda chop etiladi.',
            'ru' => '%4$s %1$s для пришивных этикеток по уходу и составу, печать на термотрансферных принтерах.',
            'en' => '%4$s %1$s for sewn-in care and composition labels, printed on thermal transfer printers.',
        ],
        'meta' => [
            'uz' => '%4$s %1$s: kiyim parvarish va tarkib yorliqlari uchun neylon lenta. Poson lenta, ribbon va printerni juftlab beradi.',
            'ru' => '%4$s %1$s для этикеток по уходу и составу одежды. Poson подберёт ленту, риббон и принтер в одной связке.',
            'en' => '%4$s %1$s for garment care and composition labels. Poson pairs the tape with a ribbon and a printer.',
        ],
        'intro' => [
            'uz' => '<strong>%4$s %1$s</strong>%2$s — kiyimga tikiladigan parvarish va tarkib yorliqlari uchun neylon lenta. U termotransfer printerda Wash Care resin ribbon bilan chop etiladi.',
            'ru' => '<strong>%4$s %1$s</strong>%2$s — нейлоновая лента для пришивных этикеток по уходу и составу одежды. Печатается на термотрансферном принтере с риббоном Wash Care resin.',
            'en' => '<strong>%4$s %1$s</strong>%2$s is a nylon tape for sewn-in garment care and composition labels. It is printed on a thermal transfer printer with a Wash Care resin ribbon.',
        ],
    ],
    'satin' => [
        'short' => [
            'uz' => '%4$s %1$s: kiyimga tikiladigan parvarish, tarkib va brend yorliqlari uchun, termotransfer printerda chop etiladi.',
            'ru' => '%4$s %1$s для пришивных этикеток по уходу, составу и бренду, печать на термотрансферных принтерах.',
            'en' => '%4$s %1$s for sewn-in care, composition and brand labels, printed on thermal transfer printers.',
        ],
        'meta' => [
            'uz' => '%4$s %1$s: kiyim parvarish, tarkib va brend yorliqlari uchun satin lenta. Poson lenta, ribbon va printerni juftlab beradi.',
            'ru' => '%4$s %1$s для этикеток по уходу, составу и бренду одежды. Poson подберёт ленту, риббон и принтер в одной связке.',
            'en' => '%4$s %1$s for garment care, composition and brand labels. Poson pairs the tape with a ribbon and a printer.',
        ],
        'intro' => [
            'uz' => '<strong>%4$s %1$s</strong>%2$s — kiyimga tikiladigan parvarish, tarkib va brend yorliqlari uchun satin lenta. U termotransfer printerda Wash Care resin ribbon bilan chop etiladi.',
            'ru' => '<strong>%4$s %1$s</strong>%2$s — сатиновая лента для пришивных этикеток по уходу, составу и бренду одежды. Печатается на термотрансферном принтере с риббоном Wash Care resin.',
            'en' => '<strong>%4$s %1$s</strong>%2$s is a satin tape for sewn-in garment care, composition and brand labels. It is printed on a thermal transfer printer with a Wash Care resin ribbon.',
        ],
    ],
    'silicone' => [
        'short' => [
            'uz' => '%4$s %1$s: kiyim yorliqlari uchun silikon lenta. Shartlar va qoʻllanish tafsilotlari soʻrov boʻyicha aniqlashtiriladi.',
            'ru' => '%4$s %1$s для этикеток на одежде. Условия и детали применения уточняются по запросу.',
            'en' => '%4$s %1$s for garment labels. Terms and application details are confirmed on request.',
        ],
        'meta' => [
            'uz' => '%4$s %1$s: kiyim yorliqlari uchun. Poson lenta, ribbon va printerni juftlab beradi, tijorat taklifini soʻrang.',
            'ru' => '%4$s %1$s для этикеток на одежде. Poson подберёт ленту, риббон и принтер — запросите коммерческое предложение.',
            'en' => '%4$s %1$s for garment labels. Poson pairs tape, ribbon and printer; request a quote.',
        ],
        'intro' => [
            'uz' => '<strong>%4$s %1$s</strong>%2$s — kiyim yorliqlari uchun silikon lenta. Qoʻllanish sharoiti va mos ribbon turi buyurtma paytida menejerimiz bilan aniqlashtiriladi.',
            'ru' => '<strong>%4$s %1$s</strong>%2$s — силиконовая лента для этикеток на одежде. Условия применения и подходящий тип риббона уточняет менеджер при заказе.',
            'en' => '<strong>%4$s %1$s</strong>%2$s is a silicone tape for garment labels. Your manager confirms the application conditions and the suitable ribbon type when you order.',
        ],
    ],
];

$tape = static fn (array $names, string $group) => $tapeTexts[$group] + [
    'name' => $names, 'uses' => 'tape', 'close' => 'tape', 'dim' => 'wl', 'label' => false, 'spec' => 'tape', 'ng' => true,
];

$G += [
    'nylon' => $tape(['uz' => 'Neylon tekstil lenta', 'ru' => 'Нейлоновая текстильная лента', 'en' => 'Nylon textile tape'], 'nylon'),
    'satin' => $tape(['uz' => 'Satin tekstil lenta', 'ru' => 'Сатиновая текстильная лента', 'en' => 'Satin textile tape'], 'satin'),
    'satinb' => $tape(['uz' => 'Qora satin tekstil lenta', 'ru' => 'Чёрная сатиновая текстильная лента', 'en' => 'Black satin textile tape'], 'satin'),
    'satinw' => $tape(['uz' => 'Oq satin tekstil lenta', 'ru' => 'Белая сатиновая текстильная лента', 'en' => 'White satin textile tape'], 'satin'),
    'satinc' => $tape(['uz' => 'Rangli satin tekstil lenta', 'ru' => 'Цветная сатиновая текстильная лента', 'en' => 'Coloured satin textile tape'], 'satin'),
    'silicone' => $tape(['uz' => 'Silikon tekstil lenta', 'ru' => 'Силиконовая текстильная лента', 'en' => 'Silicone textile tape'], 'silicone'),
];

$rollTypes = static function (float|int $height, string $sku): array {
    if ($sku === '56') {
        return [['core' => '76', 'labels' => 5000]];
    }
    if ($height <= 25) {
        return [['core' => '40', 'labels' => 2000], ['core' => '40', 'labels' => 1000]];
    }
    if ($height <= 45) {
        return [['core' => '40', 'labels' => 1000], ['core' => '40', 'labels' => 500]];
    }
    if ($height <= 100) {
        return [['core' => '40', 'labels' => 500]];
    }

    return [['core' => '40', 'labels' => 500]];
};

$suspicious = ['65', '56', '161', '39', '152'];
$shortCode = ['dt' => 'DT', 'dtlogo' => 'DT-LOGO', 'syn' => 'DT-PP', 'synrem' => 'DT-PP-REM', 'cardw' => 'KARTON-OQ', 'cardc' => 'KARTON-RANGLI', 'band' => 'BAND', 'gpp' => 'TT-PP', 'gppsilver' => 'TT-PP-SILVER', 'coat' => 'TT-COAT', 'coatlogo' => 'TT-COAT-LOGO', 'seal' => 'SEAL', 'paper' => 'PAPER'];

$build = static function (array $r) use ($G, $U, $C, $num, $cut, $html, $coreless, $corelessNote, $rollTypes, $suspicious, $shortCode): array {
    [$sku, $fam, $w, $h, $price, $brand, $grade, $flag] = array_pad($r, 8, null);
    $g = $G[$fam];
    $paren = $grade !== null ? ' ('.$grade.')' : '';
    $comma = $grade !== null ? ', '.$grade : '';
    $name = $short = $metaTitle = $metaDescription = $description = [];

    foreach (['uz', 'ru', 'en'] as $l) {
        $size = $g['dim'] === 'wh'
            ? $num($w, $l).'×'.$num($h, $l).' mm'
            : $num($w, $l).($l === 'ru' ? ' мм × ' : ' mm × ').$num($h, $l).($l === 'ru' ? ' м' : ' m');
        if ($l === 'ru') {
            $size = str_replace(' mm', ' мм', $size);
        }
        $kind = $g['name'][$l];
        $args = [$size, $paren, $comma, $kind, $num($w, $l), $flag === 'cl' ? $corelessNote[$l] : ''];

        $name[$l] = $kind.' '.$size.(($g['ng'] ?? false) ? $paren : '').(($brand !== 'POSON' && $brand !== null) ? ' '.$brand : '').($flag === 'cl' ? $coreless[$l] : '');
        $short[$l] = $cut(vsprintf($g['short'][$l], $args), 160);
        $title = $name[$l].' — Poson';
        $metaTitle[$l] = mb_strlen($title) <= 60 ? $title : $cut($name[$l], 60);
        $metaDescription[$l] = $cut(vsprintf($g['meta'][$l], $args), 155);
        $description[$l] = $html(
            vsprintf($g['intro'][$l], $args),
            array_map(static fn (string $li): string => vsprintf($li, $args), $U[$g['uses']][$l]),
            vsprintf($C[$g['close']][$l], $args),
        );
    }

    $isLabel = $g['label'];
    $tapeMaterial = ['nylon' => 'Neylon', 'silicone' => 'Silikon'];
    $tapeColor = ['satinb' => 'Qora', 'satinw' => 'Oq', 'satinc' => 'Rangli'];
    $key = static fn (mixed $value): array => ['value' => $value, 'key' => true];
    $specs = match ($g['spec']) {
        'paper' => ['label_width' => $key($w), 'label_height' => $key($h), 'label_material' => 'Qogʻoz'],
        'coatpaper' => ['label_width' => $key($w), 'label_height' => $key($h), 'label_material' => $key('Qogʻoz')],
        'pp' => ['label_width' => $key($w), 'label_height' => $key($h), 'label_material' => $key('Polipropilen (PP)')]
            + (($g['removable'] ?? false) ? ['adhesive' => 'Oson olinadigan'] : []),
        'wh' => ['label_width' => $key($w), 'label_height' => $key($h)],
        'receipt' => in_array($w, [56, 58, 80], true) ? ['paper_width' => $key((string) $w)] : [],
        'ribbon' => ['ribbon_type' => $key($g['type']), 'ribbon_width' => $key($w), 'ribbon_length' => $key($h), 'ink_side' => 'OUT', 'core_diameter' => '25'],
        'tape' => ['tape_width' => $key($w), 'tape_length' => $key($h), 'tape_material' => $tapeMaterial[$fam] ?? 'Satin']
            + (isset($tapeColor[$fam]) ? ['tape_color' => $tapeColor[$fam]] : []),
    };

    $missing = ($g['missing'] ?? false) || ($g['spec'] === 'receipt' && ! in_array($w, [56, 58, 80], true));

    $prefix = $shortCode[$fam] ?? strtoupper($fam);
    $model = $prefix.' '.$num($w, 'en').'×'.$num($h, 'en').($grade !== null ? ' '.$grade : '');

    $row = [
        'sku' => (string) $sku,
        'model' => $model,
        'brand' => $brand,
        'category' => match (true) {
            in_array($fam, ['dt', 'dtlogo', 'syn', 'synrem', 'cardw', 'cardc', 'band'], true) => 'thermal-labels',
            in_array($fam, ['gpp', 'gppsilver', 'coat', 'coatlogo', 'seal'], true) => 'thermal-transfer-labels',
            $fam === 'paper' => 'receipt-paper',
            ($g['spec'] === 'tape') => 'textile-tapes',
            default => 'ribbons',
        },
        'type' => 'consumable',
        'unit' => 'roll',
        'min_qty' => 1,
        'sale_mode' => $price === null ? 'quote' : 'cart',
        'stock_status' => 'in_stock',
        'warranty_months' => null,
        'badge' => null,
        'is_featured' => false,
        'pricing_unit' => $isLabel ? 'label' : 'unit',
        'suspicious_price' => in_array((string) $sku, $suspicious, true),
        'has_missing_specs' => $missing,
    ];

    if ($isLabel) {
        $row['roll_types'] = in_array($sku, ['161', '39'], true) ? [] : $rollTypes($h, (string) $sku);
    }

    return $row + [
        'price' => $price,
        'price_dealer' => null,
        'price_wholesale' => null,
        'name' => $name,
        'short_description' => $short,
        'description' => $description,
        'meta_title' => $metaTitle,
        'meta_description' => $metaDescription,
        'specs' => $specs,
        'related' => [],
    ];
};

// ─── Compact source table: [sku, family, width, height|length, retail soʻm, brand, grade, flag] ───
$P = 'POSON';
$table = [
    // Direct thermal labels (P-100-YD)
    ['110', 'dt', 100, 100, 100, $P, 'P-100-YD'],
    ['111', 'dt', 100, 150, 140, $P, 'P-100-YD'],
    ['268', 'dt', 100, 250, 220, $P, 'P-100-YD'],
    ['259', 'dt', 100, 80, 80, $P, 'P-100-YD'],
    ['151', 'dt', 30, 20, 8, $P, 'P-100-YD'],
    ['104', 'dt', 30, 30, null, $P, 'P-100-YD'],
    ['191', 'dt', 40, 20, 8, $P, 'P-100-YD'],
    ['152', 'dt', 40, 30, null, $P, 'P-100-YD'],
    ['105', 'dt', 40, 40, null, $P, 'P-100-YD'],
    ['192', 'dt', 43, 25, 15, $P, 'P-100-YD'],
    ['106', 'dt', 50, 30, null, $P, 'P-100-YD'],
    ['129', 'dt', 58, 30, 18, $P, 'P-100-YD'],
    ['260', 'dtlogo', 58, 40, 30, $P, 'P-100-YD'],
    ['130', 'dt', 58, 40, 24, $P, 'P-100-YD'],
    ['163', 'dt', 58, 60, 35, $P, 'P-100-YD'],
    ['252', 'dt', 60, 90, 50, $P, 'P-100-YD'],
    ['107', 'dt', 70, 50, null, $P, 'P-100-YD'],
    ['158', 'dt', 70, 54, 39, $P, 'P-100-YD'],
    ['42', 'dt', 75, 90, 60, $P, 'P-100-YD'],
    ['108', 'dt', 80, 50, 39, $P, 'P-100-YD'],
    ['209', 'dt', 80, 60, 50, $P, 'P-100-YD'],
    ['109', 'dt', 90, 45, null, $P, 'P-100-YD'],
    // Synthetic PP direct thermal, cardboard tags, medical wristband
    ['244', 'syn', 100, 80, 110, $P],
    ['231', 'syn', 40, 30, 18, $P],
    ['267', 'syn', 58, 30, 26, $P],
    ['232', 'syn', 58, 40, 34, $P],
    ['208', 'syn', 80, 60, 70, $P],
    ['245', 'synrem', 80, 60, 73, $P],
    ['169', 'cardw', 60, 38, 40, $P],
    ['222', 'cardc', 60, 38, 50, $P],
    ['39', 'band', 275, 26, null, $P],
    // Thermal transfer: gloss PP
    ['135', 'gpp', 100, 100, 122, $P, 'TC-WS820-WG D'],
    ['38', 'gpp', 100, 80, 102, $P, 'TC-WS820-WG D'],
    ['189', 'gpp', 40, 20, 20, $P, 'WS820-WG'],
    ['147', 'gppsilver', 40, 25, 75, $P, 'WS820-WG'],
    ['65', 'gpp', 58, 30, null, $P, 'WS820-WG'],
    ['185', 'gpp', 58, 40, 40, $P, 'TC-WS820-WG D'],
    ['188', 'gpp', 58, 60, 45, $P, 'WS820-WG'],
    ['41', 'gpp', 66.5, 26, 50, $P],
    ['56', 'gpp', 74, 10, null, $P, 'TC-WS820-WG D'],
    ['43', 'gpp', 80, 30, 44, $P, 'WS820-WG'],
    ['40', 'gpp', 80, 50, 59, $P, 'TC-WS820-WG D'],
    ['236', 'gpp', 85, 15, 35, $P, 'TC-WS820-WG D'],
    // Thermal transfer: coated paper (P-100-YG D)
    ['195', 'coat', 100, 100, 80, $P, 'P-100-YG D'],
    ['193', 'coat', 100, 150, 125, $P, 'P-100-YG D'],
    ['173', 'coat', 100, 50, 45, $P, 'P-100-YG D'],
    ['202', 'coat', 100, 80, 80, $P, 'P-100-YG D'],
    ['139', 'coat', 15, 15, 6, $P, 'P-100-YG D'],
    ['172', 'coat', 30, 20, 10, $P, 'P-100-YG D'],
    ['266', 'coat', 30, 50, 17, $P, 'P-100-YG D'],
    ['194', 'coat', 40, 58, 25, $P, 'P-100-YG D'],
    ['190', 'coat', 45, 35, 18, $P, 'P-100-YG D'],
    ['265', 'coat', 50, 30, 17, $P, 'P-100-YG D'],
    ['269', 'coat', 50, 60, 30, $P, 'P-100-YG D'],
    ['227', 'coatlogo', 58, 60, 50, $P, 'P-100-YG D'],
    ['204', 'coat', 65, 45, 30, $P, 'P-100-YG D'],
    ['203', 'coat', 70, 50, 38, $P, 'P-100-YG D'],
    ['196', 'coat', 75, 45, 35, $P, 'P-100-YG D'],
    ['161', 'seal', 80, 30, null, $P],
    // Ribbons
    ['165', 'waxc', 110, 300, 175000, $P, 'TDW108'],
    ['128', 'wax', 110, 300, 45000, $P, 'TDW108'],
    ['125', 'wax', 30, 300, 12500, $P, 'TDW108'],
    ['126', 'wax', 35, 300, null, $P, 'TDW108'],
    ['127', 'wax', 60, 300, 23000, $P, 'TDW108'],
    ['143', 'wax', 110, 300, 42000, 'BDB'],
    ['142', 'wax', 60, 300, 25000, 'BDB'],
    ['124', 'wr', 110, 300, 110000, $P, 'TDM230'],
    ['123', 'wr', 110, 450, null, $P, 'TDM230'],
    ['121', 'wr', 25, 300, 25000, $P, 'TDM230'],
    ['120', 'wr', 30, 300, 29000, $P, 'TDM230'],
    ['119', 'wr', 40, 300, 42000, $P, 'TDM230'],
    ['118', 'wr', 50, 300, 38000, $P, 'TDM230'],
    ['117', 'wr', 60, 300, 55000, $P, 'TDM230'],
    ['122', 'wr', 90, 450, null, $P, 'TDM230'],
    ['145', 'wr', 80, 300, 80000, 'BDB'],
    ['213', 'wrg', 110, 300, 300000, null],
    ['33', 'res', 110, 300, 150000, $P, 'TDR325'],
    ['131', 'res', 50, 300, 93804.1, $P, 'TDR325'],
    ['144', 'res', 110, 300, 182068.92, 'BDB'],
    ['237', 'res', 60, 300, 120000, 'BDB'],
    ['210', 'resp', 60, 300, 115000, null],
    ['183', 'resp', 85, 300, 195000, null],
    ['166', 'resm', 100, 200, 395000, $P, 'CS5090/95'],
    ['168', 'resk', 100, 200, 270000, $P, 'CS6P/CS3380'],
    ['214', 'resg', 30, 200, 130000, $P],
    ['251', 'resf', 30, 200, 160000, $P],
    ['212', 'wash', 30, 300, 65000, null],
    ['116', 'wash', 25, 300, 55000, $P, 'TDR370'],
    ['115', 'wash', 30, 300, 65000, $P, 'TDR370'],
    ['114', 'wash', 35, 300, null, $P, 'TDR370'],
    ['113', 'wash', 40, 300, 85000, $P, 'TDR370'],
    ['112', 'wash', 50, 300, 110000, $P, 'TDR370'],
    ['37', 'wash', 60, 300, 129709.19, $P, 'TDR370'],
    // Receipt paper (48 g/m2; second number = length in metres, owner confirmed 2026-11-01)
    ['201', 'paper', 56, 12, 1200, $P, null, 'cl'],
    ['167', 'paper', 56, 12, 1655.73, $P],
    ['186', 'paper', 56, 15, 2025, $P],
    ['184', 'paper', 56, 30, 3500, $P],
    ['246', 'paper', 80, 30, 5000, $P],
    ['57', 'paper', 80, 50, 10384.62, $P],
    ['187', 'paper', 80, 60, 10000, $P],
    ['47', 'paper', 80, 80, 13898.69, $P],
    // Textile tapes: nylon NT30
    ['75', 'nylon', 15, 200, 21145.67, $P, 'NT30'],
    ['76', 'nylon', 20, 200, null, $P, 'NT30'],
    ['77', 'nylon', 25, 200, 35136.79, $P, 'NT30'],
    ['78', 'nylon', 30, 200, 42132.35, $P, 'NT30'],
    ['79', 'nylon', 35, 200, null, $P, 'NT30'],
    ['80', 'nylon', 40, 200, null, $P, 'NT30'],
    ['81', 'nylon', 45, 200, 56282.46, $P, 'NT30'],
    ['82', 'nylon', 50, 200, null, $P, 'NT30'],
    // Textile tapes: nylon NT7038
    ['83', 'nylon', 15, 200, null, $P, 'NT7038'],
    ['84', 'nylon', 20, 200, null, $P, 'NT7038'],
    ['85', 'nylon', 25, 200, 43000, $P, 'NT7038'],
    ['86', 'nylon', 30, 200, 55000, $P, 'NT7038'],
    ['87', 'nylon', 35, 200, null, $P, 'NT7038'],
    ['88', 'nylon', 40, 200, 70000, $P, 'NT7038'],
    ['89', 'nylon', 45, 200, null, $P, 'NT7038'],
    ['90', 'nylon', 50, 200, null, $P, 'NT7038'],
    // Textile tapes: satin PS7530
    ['91', 'satin', 15, 200, 26000, $P, 'PS7530'],
    ['92', 'satin', 20, 200, 40000, $P, 'PS7530'],
    ['93', 'satin', 25, 200, 50000, $P, 'PS7530'],
    ['94', 'satin', 30, 200, 60000, $P, 'PS7530'],
    ['95', 'satin', 35, 200, 61000, $P, 'PS7530'],
    ['96', 'satin', 40, 200, 68000, $P, 'PS7530'],
    ['97', 'satin', 50, 200, 85000, $P, 'PS7530'],
    // Textile tapes: black satin PS7500, other satin, silicone
    ['98', 'satinb', 25, 200, 95000, $P, 'PS7500'],
    ['99', 'satinb', 30, 200, 115000, $P, 'PS7500'],
    ['100', 'satinb', 40, 200, null, $P, 'PS7500'],
    ['249', 'satinc', 25, 200, 150000, $P],
    ['211', 'satinw', 30, 200, 61000, null],
    ['164', 'satin', 100, 200, 395000, $P, 'CS832'],
    ['270', 'silicone', 25, 200, null, $P],
];

return array_map($build, $table);
