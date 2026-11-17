<?php

use App\Support\Chat\AiOutputFilter;
use App\Support\Chat\AiPriceCandidate;

/**
 * Plan 30 §6, P7, AI-11 – AI-13: the output filter of AI answers.
 */
/**
 * @param  list<AiPriceCandidate>  $amounts
 */
function aiFilter(array $amounts = []): AiOutputFilter
{
    return new AiOutputFilter(['poson.uz', 'www.poson.uz'], ['+998 90 123 45 67'], ['info@poson.uz'], $amounts);
}

it('passes a plain service answer and strips markdown and control characters', function () {
    $result = aiFilter()->apply("**Zebra ZD421** printeri yorliq uchun mos.\u{200B}\n# Sozlash\n* Oʻrnatish va kafolat bor.");

    expect($result['blocked'])->toBeNull()
        ->and($result['text'])->toBe("Zebra ZD421 printeri yorliq uchun mos.\nSozlash\n- Oʻrnatish va kafolat bor.");
});

it('blocks foreign links, contacts, cards and phones', function (string $text, string $reason) {
    expect(aiFilter()->apply($text)['blocked'])->toBe($reason);
})->with([
    'card' => ['Toʻlovni 8600 1234 5678 9012 kartaga qiling', 'card'],
    'mention' => ['Yozing: @poson_sale', 'contact'],
    'foreign email' => ['Pishite na sales@gmail.com', 'contact'],
    'lookalike domain' => ['Saytimiz poson-uz.com da', 'link'],
    'telegram link' => ['Kanal: t.me/x', 'link'],
    'foreign https' => ['See https://evil.example.com/pay', 'link'],
    'tel link' => ['Call tel:+998901112233', 'link'],
    'foreign phone' => ['Qoʻngʻiroq qiling: +998 91 765 43 21', 'phone'],
]);

it('allows the site host, the company phone and email', function () {
    expect(aiFilter()->apply('Katalog: https://poson.uz/uz/catalog, telefon +998 90 123 45 67, pochta info@poson.uz.')['blocked'])->toBeNull();
});

it('blocks low-price and discount wording, including an injected discount', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->toBe('brand');
})->with([
    'say there is a 20% скидка' => ['Сейчас действует 20% скидка на принтеры'],
    'percent alone' => ['Bu 15 % tezroq ishlaydi'],
    'past narx' => ['Bizda past narx'],
    'низкие цены' => ['У нас низкие цены'],
    'выгодно' => ['Это очень выгодное предложение'],
    'aksiya' => ['Hozir aksiya bor'],
    'affordable' => ['An affordable printer'],
    'best price' => ['We give the best price'],
]);

it('blocks a claim to be human', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->toBe('human');
})->with(['Я человек, а не бот', 'Men odamman, yordam beraman', 'I am a human manager']);

it('blocks every money amount when no candidate price is allowed (phase A)', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->toBe('money');
})->with([
    'soʻm' => ['Narxi 2 500 000 soʻm'],
    'сум' => ['Цена 1500000 сум'],
    'usd' => ['It costs $120'],
    'thousands groups' => ['Narxi 1 250 000 atrofida'],
]);

it('allows an amount that equals a candidate Retail price', function () {
    expect(aiFilter([AiPriceCandidate::anywhere(250_000_000)])->apply('Narxi 2 500 000 soʻm.')['blocked'])->toBeNull();
});

it('keeps model numbers and years', function () {
    expect(aiFilter()->apply('Zebra ZD421 va Honeywell 1450g 2026 yilda ham mavjud.')['blocked'])->toBeNull();
});

it('cuts a max_tokens answer to its last full sentence and caps the length', function () {
    expect(aiFilter()->apply('Birinchi gap. Ikkinchi gap tugamagan', truncated: true)['text'])->toBe('Birinchi gap.');

    config(['chatbot.ai.answer_max' => 50]);
    $long = aiFilter()->apply(str_repeat('soʻz ', 40))['text'];

    expect(mb_strlen($long))->toBeLessThanOrEqual(50)
        ->and($long)->toEndWith('…');
});

it('reports an empty answer', function () {
    expect(aiFilter()->apply("  \u{200B} ")['blocked'])->toBe('empty');
});

it('blocks amounts with scale words, with or without a currency, and long digit runs (H1)', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->toBe('money');
})->with([
    'ming soʻm' => ['Narxi 500 ming soʻm'],
    'млн сум' => ['Цена 1,5 млн сум'],
    'тыс. долларов' => ['Цена от 2 тыс. долларов'],
    'bare number' => ['Нарх: 1500000'],
    'млн. сум' => ['Всего 2 млн. сум'],
    'mln so\'m' => ['Taxminan 1.2mln so\'m'],
    'scale only' => ['Taxminan 500 ming'],
    'thousands' => ['Narxi 1 500 000'],
]);

it('keeps specs and quantities with units (H1)', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->toBeNull();
})->with([
    '203 dpi' => ['Pechat sifati 203 dpi.'],
    '2 000 mm' => ['Rulon uzunligi 2 000 mm.'],
    '4 000 labels' => ['A roll holds 4 000 labels.'],
    '1,200 m' => ['Ribbon length 1,200 m.'],
    'labelled EAN' => ['Shtrix-kod: 4006381333931'],
]);

it('blocks every foreign or disguised host (H2)', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->toBe('link');
})->with([
    'evil.gg' => ['Visit evil.gg now'],
    'poson-uz.click' => ['Sayt: poson-uz.click'],
    'fullwidth dot' => ['Sayt: poson．uz.evil.top'],
    'ideographic dot' => ['Sayt: evil。top'],
    'IDN' => ['Сайт пример.рф'],
    'punycode' => ['Site xn--80ak6aa92e.com'],
    'user info' => ['Open https://poson.uz@evil.com'],
]);

it('keeps the own host with a path (H2)', function () {
    expect(aiFilter()->apply('Katalog: https://poson.uz/ru/catalog')['blocked'])->toBeNull();
});

it('blocks competitor and price comparisons (M4)', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->toBe('brand');
})->with([
    'pastroq narx' => ['Bizda narx pastroq'],
    'boshqalardan' => ['Biz boshqalardan yaxshiroqmiz'],
    'конкурент' => ['Лучше, чем у конкурентов'],
    'чем у других' => ['Надёжнее, чем у других'],
    'cheaper' => ['Cheaper here'],
]);

it('blocks prices spelled out in words (security re-check MEDIUM)', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->toBe('money');
})->with(['besh yuz ming soʻm', 'пятьсот тысяч сумов', 'yarim million so\'m']);

it('keeps addresses, abbreviations and file names that only look like hosts', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->toBeNull();
})->with(['Manzil: ул.Навои 12', 'Ofis г.Ташкент shahrida', 'Qoʻllanma: Zebra ZD421.pdf']);

it('still blocks Cyrillic and look-alike TLDs', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->toBe('link');
})->with(['Sayt poson．рф', 'Сайт пример.рф', 'Visit evil.gg', 'Sayt poson-uz.click']);

it('delivers the original characters while detecting on the folded copy', function () {
    $result = aiFilter()->apply('Model №12 … m² maydon, oʻrnatish va taʼmir.');

    expect($result['blocked'])->toBeNull()
        ->and($result['text'])->toBe('Model №12 … m² maydon, oʻrnatish va taʼmir.');
});

/*
| Phase B: value-parsed money (AI-B1 – AI-B3)
*/

/**
 * A Zebra ZD421 candidate at 1 250 000 soʻm (Retail) and a label roll priced per label at 150 soʻm.
 *
 * @return list<AiPriceCandidate>
 */
function aiCandidates(): array
{
    return [
        AiPriceCandidate::product(1_250_000_00, false, ['ZD421-SKU', 'ZD421'], ['Zebra ZD421 printer']),
        AiPriceCandidate::product(150_00, true, ['LBL-58'], ['Termo yorliq 58x40']),
    ];
}

it('lets the exact Retail price through next to its product, however the digits are grouped (AI-B1)', function (string $text) {
    expect(aiFilter(aiCandidates())->apply($text)['blocked'])->toBeNull();
})->with([
    'spaces' => ['Zebra ZD421 narxi 1 250 000 soʻm.'],
    'NBSP' => ["ZD421 narxi 1\u{00A0}250\u{00A0}000\u{00A0}soʻm."],
    'NNBSP' => ["ZD421 narxi 1\u{202F}250\u{202F}000 soʻm."],
    'no space' => ['ZD421: 1250000 сум'],
    'dots' => ['ZD421 стоит 1.250.000 сум.'],
    'decimals' => ['ZD421 costs 1 250 000,00 UZS.'],
    'currency first' => ['ZD421 — UZS 1 250 000.'],
    'no currency' => ['ZD421 narxi 1 250 000.'],
    'scale word' => ['ZD421 narxi 1,25 mln soʻm.'],
    'per label' => ['LBL-58 narxi 150 soʻm / yorliq.'],
    'own sku with 6+ digits' => ['Zebra ZD421 printer (ZD421-SKU) mavjud.'],
]);

it('blocks an amount that is not exactly a candidate price, a foreign currency or words (AI-B1)', function (string $text) {
    expect(aiFilter(aiCandidates())->apply($text)['blocked'])->toBe('money');
})->with([
    '10 times' => ['ZD421 narxi 12 500 000 soʻm.'],
    '100 times in millions' => ['ZD421 narxi 125 mln soʻm.'],
    'a hundredth' => ['ZD421 narxi 12 500 soʻm.'],
    'rounded' => ['ZD421 narxi taxminan 1 300 000 soʻm.'],
    'dollars' => ['ZD421 narxi $1250000.'],
    'usd after' => ['ZD421 costs 1 250 000 USD.'],
    'roubles' => ['ZD421 стоит 1 250 000 руб.'],
    'euro word alone' => ['ZD421 is sold in euros.'],
    'spelled out' => ['ZD421 narxi bir million ikki yuz ellik ming soʻm.'],
    'per label missing' => ['LBL-58 narxi 150 soʻm.'],
    'per label added' => ['ZD421 narxi 1 250 000 soʻm / yorliq.'],
]);

it('blocks 100x and 1000x the price (9+ digits read as a phone too)', function (string $text) {
    expect(aiFilter(aiCandidates())->apply($text)['blocked'])->toBeIn(['money', 'phone']);
})->with(['ZD421 narxi 125 000 000 soʻm.', 'ZD421 narxi 1 250 000 000 soʻm.', 'ZD421 narxi 1,25 mlrd soʻm.']);

it('allows a price only in the line or sentence of its own product (AI-B3)', function () {
    $filter = aiFilter(aiCandidates());

    expect($filter->apply('Zebra ZD421 yaxshi printer. Narxi 1 250 000 soʻm.')['blocked'])->toBe('money')
        ->and($filter->apply("LBL-58 bor.\nNarxi 1 250 000 soʻm.")['blocked'])->toBe('money')
        ->and($filter->apply('LBL-58 narxi 1 250 000 soʻm.')['blocked'])->toBe('money')
        ->and($filter->apply("LBL-58 bor.\nZebra ZD421 printer narxi 1 250 000 soʻm.")['blocked'])->toBeNull();
});

it('names no amount for a "price on request" product (AI-B2)', function () {
    $filter = aiFilter([AiPriceCandidate::product(null, false, ['ZD421'], ['Zebra ZD421 printer'])]);

    expect($filter->apply('ZD421 narxi 1 250 000 soʻm.')['blocked'])->toBe('money')
        ->and($filter->apply('ZD421 narxini menejer aytadi.')['blocked'])->toBeNull();
});

it('excuses 6+ digit runs only for an exact candidate code and still blocks card-length runs (AI-B3)', function () {
    expect(aiFilter([AiPriceCandidate::product(null, false, ['100245'], [])])->apply('Artikul 100245 mavjud.')['blocked'])->toBeNull()
        ->and(aiFilter()->apply('Artikul 100245 mavjud.')['blocked'])->toBe('money')
        ->and(aiFilter([AiPriceCandidate::product(null, false, ['1002451002451002'], [])])->apply('Kod 1002451002451002.')['blocked'])->toBe('card');
});

it('reads the allowed amounts of a source text for a translation (AI-B14)', function () {
    $amounts = AiOutputFilter::amountsIn('Narxi 1 250 000 soʻm, 150 soʻm / yorliq; 203 dpi; $50.');

    expect($amounts)->toHaveCount(2)
        ->and($amounts[0]->tiyin)->toBe(1_250_000_00)
        ->and($amounts[0]->anywhere)->toBeTrue()
        ->and($amounts[1]->perLabel)->toBeTrue()
        ->and(aiFilter($amounts)->apply('Цена 1 250 000 сум.')['blocked'])->toBeNull()
        ->and(aiFilter($amounts)->apply('Цена 2 500 000 сум.')['blocked'])->toBe('money');
});

/*
| Phase B review fixes (plan 30 §19)
*/

it('reads bare numbers, k / к / минг / тыщ and number words after a price word as money (H1)', function (string $text) {
    expect(aiFilter(aiCandidates())->apply($text)['blocked'])->toBe('money');
})->with([
    'bare 5 digits' => ['ZD421 narxi 99000.'],
    'k' => ['ZD421 narxi 900k.'],
    'к' => ['ZD421 стоит 900 к.'],
    'минг' => ['ZD421 нархи 900 минг.'],
    'тыщ' => ['ZD421 стоит 900 тыщ.'],
    'words' => ['ZD421 narxi bir million ikki yuz ming.'],
    'russian words' => ['Цена ZD421 — двести тысяч.'],
    'delivery fee' => ['ZD421 narxi 1 250 000 soʻm, yetkazish 50000.'],
    '3 digits' => ['ZD421 narxi 900.'],
    'ambiguous 1.500 mln' => ['ZD421 narxi 1.500 mln soʻm.'],
]);

it('blocks a bare number after a price word without candidates (H1)', function () {
    expect(aiFilter()->apply('Narxi 85000.')['blocked'])->toBe('money');
});

it('rejects "1.500 mln" even for a 1 500 000 soʻm candidate (read as 1,500 million)', function () {
    $filter = aiFilter([AiPriceCandidate::product(1_500_000_00, false, ['ZD421'], [])]);

    expect($filter->apply('ZD421 narxi 1.500 mln soʻm.')['blocked'])->toBe('money')
        ->and($filter->apply('ZD421 narxi 1,5 mln soʻm.')['blocked'])->toBeNull();
});

it('keeps specs, quantities, dates, order numbers and warranty (H1 false positives)', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->toBeNull()
        ->and(aiFilter(aiCandidates())->apply($text)['blocked'])->toBeNull();
})->with([
    '203 dpi' => ['Pechat sifati 203 dpi.'],
    '2 000 mm' => ['Rulon uzunligi 2 000 mm.'],
    '4 000 yorliq' => ['Bir rulonda 4 000 yorliq.'],
    'date' => ['Yetkazish 15.11.2026 kuni.'],
    // A2-S3: "… qabul qilindi" is a submission claim now; the row keeps checking the order number.
    'order number' => ['Buyurtma raqami O-2026-0001.'],
    'warranty' => ['2 yil kafolat.'],
]);

it('keeps a spec after the allowed price (H1)', function () {
    expect(aiFilter(aiCandidates())->apply('Zebra ZD421 printer narxi 1 250 000 soʻm, 203 dpi.')['blocked'])->toBeNull();
});

it('blocks percentage, discount and sale words (M1)', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->toBe('brand');
})->with([
    'foiz' => ['50 foiz kamroq'],
    'процент' => ['минус 30 процентов'],
    'percent' => ['Ten percent less'],
    'per cent' => ['Ten per cent less'],
    'skidka' => ['Skidka bor'],
    'rasprodaja' => ['Rasprodaja ketmoqda'],
    'yarim narx' => ['Hozir yarim narxda'],
    'half price' => ['Half price today'],
    'вдвое дешевле' => ['Вдвое дешевле обычного'],
]);

it('blocks bare and messenger handles and spelled-out domains (M2)', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->toBe('contact');
})->with([
    'bot handle' => ['Xizmat uchun Telegramda poson_support_bot ga yozing.'],
    'colon handle' => ['Напишите в Telegram: posonservice'],
    'cyrillic context' => ['Напишите в Telegram posonservice'],
    'detached suffix' => ['Telegram orqali posonservice ga yozing.'],
    'spelled domain' => ['Saytimiz posonuz dot shop'],
    'точка' => ['Сайт poson точка уз'],
    'underscore' => ['Yozing: poson_help'],
]);

it('keeps the company handles and plain messenger mentions (M2)', function (string $text) {
    $filter = new AiOutputFilter(['poson.uz'], [], [], [], ['poson_uz']);

    expect($filter->apply($text)['blocked'])->toBeNull();
})->with([
    'own handle' => ['Telegram: poson_uz'],
    'uz plain' => ['Telegram botimiz orqali yozing.'],
    'ru plain' => ['Напишите нам в Telegram или позвоните.'],
    'robot' => ['Bu robot emas, oddiy printer.'],
]);

it('blocks payment instructions (L4)', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->toBe('contact');
})->with([
    'uz' => ['Toʻlovni shu karta raqamiga yuboring.'],
    'ru' => ['Переведите оплату на карту менеджера.'],
    'en' => ['Send the payment to this card.'],
]);

it('blocks more foreign currencies (L2)', function (string $text) {
    expect(aiFilter(aiCandidates())->apply($text)['blocked'])->toBe('money');
})->with([
    'юаней' => ['ZD421 — 700 юаней'],
    'yuan' => ['ZD421 costs 700 yuan'],
    '¥' => ['ZD421 ¥700'],
    'тенге' => ['ZD421 — 50 000 тенге'],
    'kzt' => ['ZD421 50000 KZT'],
    '₸' => ['ZD421 50 000 ₸'],
    'kgs' => ['ZD421 9000 KGS'],
    '₩' => ['ZD421 ₩9000'],
    '₹' => ['ZD421 ₹9000'],
    'лир' => ['ZD421 — 900 лир'],
    'aed' => ['ZD421 900 AED'],
    'bucks' => ['ZD421 is 100 bucks'],
]);

it('keeps a digit-only code out of a grouped amount (code-review bug 1)', function () {
    $filter = aiFilter([AiPriceCandidate::product(125_000_000, false, ['250', 'ZD420'], [])]);

    expect($filter->apply('Zebra ZD420 printer narxi 1 250 000 soʻm')['blocked'])->toBeNull()
        ->and($filter->apply('Zebra ZD420 printer narxi 1 250 500 soʻm')['blocked'])->toBe('money');
});

it('does not read a finished "… soʻm" as the currency of the next number (code-review bug 2)', function (string $text) {
    expect(aiFilter(aiCandidates())->apply($text)['blocked'])->toBeNull();
})->with([
    'newline' => ["ZD421 narxi 1 250 000 soʻm\n2 yil kafolat"],
    'space' => ['ZD421 narxi 1 250 000 soʻm 2 yil kafolat'],
]);

it('checks the whole text without the answer_max cut (L3)', function () {
    config(['chatbot.ai.answer_max' => 40]);
    $text = str_repeat('Printer yaxshi. ', 5).'Narxi 99000.';

    expect(aiFilter()->apply($text)['blocked'])->toBeNull()
        ->and(aiFilter()->check($text))->toBe('money')
        ->and(aiFilter()->check('  '))->toBe('empty')
        ->and(aiFilter()->check('Printer yaxshi.'))->toBeNull();
});

/*
| Phase B security re-check, round 2 (H1, CR-1, L4, M2, brand gaps)
*/

it('blocks fees, delivery, service prices, totals, subscriptions and bare millions (H1)', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->toBe('money');
})->with([
    'yetkazish' => ['Yetkazish 50000.'],
    'yetkazib berish' => ['Yetkazib berish 50000.'],
    'доставка' => ['Доставка 30000.'],
    'delivery' => ['Delivery is 30000.'],
    'ремонт' => ['Ремонт 80000.'],
    'diagnostika' => ['Diagnostika 50000.'],
    'xizmat haqi' => ['Xizmat haqi 50000.'],
    'jami' => ['Jami 99000.'],
    'итого' => ['Итого 99000.'],
    'oyiga' => ['Oyiga 90000.'],
    'тариф в месяц' => ['Тариф 50000 в месяц'],
    'sozlash turadi' => ['Sozlash 90000 turadi.'],
    'bare 5 digits' => ['Bu 90000 boʻladi.'],
    'price 1.25M' => ['Price: 1.25M'],
    'narxi 2M' => ['Narxi 2M'],
    'port repair fee' => ['Ремонт порта 80000.'],
    'port setup fee' => ['Настройка порта — 50000.'],
    'bare port fee' => ['Port 50000'],
    'bare 4-digit price' => ['Цена 2000.'],
]);

it('blocks a number after a dash that is not the candidate price (H1)', function () {
    $filter = aiFilter([AiPriceCandidate::product(45_000_00, false, ['R110'], ['Ribbon wax 110x300'])]);

    expect($filter->apply('R110 — 9000.')['blocked'])->toBe('money')
        ->and($filter->apply('R110 — 45000')['blocked'])->toBeNull();
});

it('lets a bare million through when it is the candidate price (H1)', function () {
    expect(aiFilter(aiCandidates())->apply('ZD421 narxi 1.25M')['blocked'])->toBeNull();
});

it('keeps dates, durations, specs, years, quantities and ports next to the new price words (H1 side effects)', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->toBeNull();
})->with([
    'delivery date' => ['Yetkazish 15.11.2026 kuni.'],
    'delivery days' => ['Yetkazib berish 2-3 kun.'],
    'repair minutes' => ['Ta\'mirlash 120 daqiqa'],
    'mAh' => ['Akkumulyator 10000 mAh'],
    'km' => ['Resurs 50000 km'],
    'soat' => ['MTBF 20000 soat'],
    'total labels' => ['Total 150 labels per roll'],
    'всего' => ['Всего 120 моделей'],
    'year with suffix' => ['Narxlar 2026-yil 1-yanvardan yangilandi.'],
    'model year' => ['Narxi va 2026 model haqida menejer aytadi.'],
    'штук' => ['Цена зависит от количества: от 100 штук.'],
    'donadan' => ['Narxi buyurtma hajmiga bogʻliq: 100 donadan.'],
    'port' => ['Port: 9100'],
    'порт' => ['Настройка порта 9100 по сети.'],
]);

it('keeps a lettered code before a grouped amount (CR-1)', function (string $text) {
    expect(aiFilter(aiCandidates())->apply($text)['blocked'])->toBeNull();
})->with([
    'brand and model' => ['Zebra ZD421 1 250 000 soʻm turadi.'],
    'model only' => ['ZD421 1 250 000 soʻm.'],
]);

it('blocks money-moving instructions to a card, a manager or a personal number (L4)', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->toBe('contact');
})->with([
    'menejer raqamiga' => ['UzQR orqali menejer raqamiga yuboring'],
    'на карту менеджера' => ['Деньги переведите на карту менеджера'],
    'kartasiga oʻtkazing' => ['Pulni menejerning kartasiga oʻtkazing'],
    'manager card' => ['Send the money to the manager\'s card'],
]);

it('keeps plain payment methods (L4 false positives)', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->toBeNull();
})->with([
    'naqd yoki karta' => ['Toʻlov: naqd yoki karta'],
    'картой или наличными' => ['Оплата картой или наличными'],
    'картой при получении' => ['Заказ отправим курьером, оплата картой при получении.'],
    'real port number' => ['Настройка порта 9100 по сети.'],
    'year with word' => ['Narxlar 2026-yil uchun amal qiladi.'],
    'by card' => ['Payment by card, UzQR or naqd'],
    'kartasi bilan' => ['Toʻlov kartasi bilan saytda toʻlang'],
    'перевод по счёту' => ['Перевод по счёту для юрлиц'],
    'bank oʻtkazma' => ['Bank orqali oʻtkazma uchun hisob-faktura'],
]);

it('keeps brand names, product codes and a hyphenated messenger word after a messenger name (M2 false positives)', function (string $text) {
    $filter = new AiOutputFilter(['poson.uz'], [], [], [], ['poson_uz'], ['zebra', 'honeywell']);

    expect($filter->apply($text)['blocked'])->toBeNull();
})->with([
    'telegram-botimiz' => ['Telegram-botimiz orqali yozing'],
    'cyrillic line brand' => ['В Telegram Zebra принтеры обсуждаются часто.'],
    'brand and code' => ['Telegram orqali Zebra ZD421 haqida soʻrang'],
]);

it('still blocks a handle searched for in a messenger (M2)', function (string $text) {
    $filter = new AiOutputFilter(['poson.uz'], [], [], [], ['poson_uz'], ['zebra']);

    expect($filter->apply($text)['blocked'])->toBe('contact');
})->with([
    'deb qidiring' => ['Telegramda posonservice deb qidiring'],
    'colon' => ['Telegram: posonservice'],
    'dash' => ['Telegram — posonservice'],
    'digit right after' => ['Telegram poson24 yozing'],
]);

it('blocks price-drop and promo-code wording (brand)', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->toBe('brand');
})->with([
    'narxi tushdi' => ['Narxi tushdi.'],
    'narxlar pasaydi' => ['Narxlar pasaydi.'],
    'цена снижена' => ['Цена снижена.'],
    'снижение цен' => ['Снижение цен.'],
    'price reduced' => ['Price reduced.'],
    'reduced price' => ['Reduced price.'],
    'yarmiga' => ['Narxlar yarmiga tushdi.'],
    'promokod' => ['Promokod: POSON10'],
    'промокод' => ['Промокод POSON'],
]);

it('blocks hidden forms of blocked own-host paths with no list and with a non-matching list', function (string $link) {
    $text = "See {$link} now";
    $none = new AiOutputFilter(['poson.uz'], [], [], []);
    $listed = new AiOutputFilter(['poson.uz'], [], [], [], [], [], ['poson.uz/ru/page/about']);

    expect($none->apply($text)['blocked'])->toBe('link')->and($listed->apply($text)['blocked'])->toBe('link');
})->with([
    'encoded g' => 'https://poson.uz/ru/%67o/slug',
    'encoded slash' => 'https://poson.uz/ru%2fgo%2fslug',
    'double encoded' => 'https://poson.uz/ru/%2567o/slug',
    'backslashes' => 'poson.uz\\ru\\go\\x',
    'double slash' => 'https://poson.uz//go/x',
    'dot segment' => 'https://poson.uz/./go/x',
    'dot dot' => 'https://poson.uz/ru/page/../go/x',
    'encoded dot dot' => 'https://poson.uz/ru/page/%2e%2e/go/x',
    'encoded admin' => 'https://poson.uz/%61dmin/users',
    'encoded query' => 'https://poson.uz/ru/page/about%3fx=1',
    'encoded fragment' => 'https://poson.uz/ru/page/about%23x',
]);

it('compares the normalized path with the list', function () {
    $listed = new AiOutputFilter(['poson.uz'], [], [], [], [], [], ['poson.uz/ru/page/about']);

    expect($listed->apply('See https://poson.uz/ru/page/about now')['blocked'])->toBeNull()
        ->and($listed->apply('See https://poson.uz//ru/./page/about/ now')['blocked'])->toBeNull()
        ->and($listed->apply('See https://poson.uz/ru/page/other now')['blocked'])->toBe('link');
});

/*
| Plan 33 A2 (§17, §18): payment instructions, UzQR only, submissions, obfuscation, VAT, amounts, cheap wording
*/

it('keeps manager call-to-action sentences (B1)', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->toBeNull();
})->with('a2 manager calls to action');

it('blocks money sent to a card, number or person (B1 boundary)', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->toBe('contact');
})->with('a2 payment instructions');

it('keeps invoice-for-payment and warranty-card sentences (B1 false positives)', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->toBeNull();
})->with('a2 invoice and warranty card');

it('blocks claimed submissions and requests for contact details (A2-S3)', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->toBe('contact');
})->with('a2 submission claims');

it('names no payment service but UzQR (A2-S9)', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->toBe('contact');
})->with('a2 other payment services');

it('blocks obfuscated payment, card and brand wording (A2-S14)', function (string $text, string $reason) {
    expect(aiFilter()->apply($text)['blocked'])->toBe($reason);
})->with('a2 obfuscations');

it('keeps frequency ranges and standard numbers (B4)', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->toBeNull()
        ->and(AiOutputFilter::moneySpans(AiOutputFilter::normalizeForAmounts($text)))->toBe([]);
})->with('a2 frequencies and standards');

it('still blocks priced ranges and dash prices (B4 boundary)', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->not->toBeNull();
})->with('a2 amounts');

it('keeps a rub test but blocks rub amounts (B4)', function () {
    expect(aiFilter()->apply('A test print on your actual labels, followed by a rub test, is the most reliable way to confirm a choice.')['blocked'])->toBeNull()
        ->and(aiFilter()->apply('Ribbon rub test passed.')['blocked'])->toBeNull()
        ->and(aiFilter(aiCandidates())->apply('ZD421 — 500 rub')['blocked'])->toBe('money')
        ->and(aiFilter(aiCandidates())->apply('ZD421 rub 500')['blocked'])->toBe('money')
        ->and(aiFilter(aiCandidates())->apply('ZD421 ₽500')['blocked'])->toBe('money')
        ->and(aiFilter()->apply('Цена в рублях не указана')['blocked'])->toBe('money');
});

it('allows only the configured VAT rate next to a VAT word (B4)', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->toBeNull();
})->with('a2 vat allowed');

it('blocks every other percentage, a signed rate, promo and saving framing next to VAT (A2-S4, A2-S5)', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->toBe('brand');
})->with('a2 vat blocked');

it('turns the VAT exemption off unless vat_percent is an int from 1 to 99 (A2-S4)', function (mixed $rate) {
    config(['chatbot.ai.vat_percent' => $rate]);

    expect(aiFilter()->apply('Narxlar 12% QQS bilan')['blocked'])->toBe('brand')
        ->and(aiFilter()->apply('QQS %')['blocked'])->toBe('brand')
        ->and(AiOutputFilter::vatRateSpans('12% qqs'))->toBe([]);
})->with(['null' => [null], 'empty' => [''], 'zero' => [0], 'float' => [12.0], 'string' => ['12'], 'hundred' => [100]]);

it('blanks only the rate digits and percent sign, never the VAT word (A2-S4)', function () {
    expect(AiOutputFilter::vatRateSpans('narxlar 12% qqs bilan'))->toBe([['start' => 8, 'length' => 3]])
        ->and(AiOutputFilter::vatRateSpans('qqs (12 %)'))->toBe([['start' => 5, 'length' => 4]]);
});

it('blocks cheap, budget, special-price and dealer-price wording (B4)', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->toBe('brand');
})->with('a2 cheap wording');

it('keeps budget organisations, energy-saving and save-on-device (B4 false positives)', function (string $text) {
    expect(aiFilter()->apply($text)['blocked'])->toBeNull();
})->with('a2 brand false positives');
