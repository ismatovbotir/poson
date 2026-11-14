<?php

use App\Support\Chat\AiOutputFilter;

/**
 * Plan 30 §6, P7, AI-11 – AI-13: the output filter of AI answers.
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
    expect(aiFilter(['2 500 000 soʻm'])->apply('Narxi 2 500 000 soʻm.')['blocked'])->toBeNull();
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
