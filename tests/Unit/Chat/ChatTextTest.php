<?php

use App\Support\Chat\ChatText;

/**
 * Plan 28 §2.2 / CB-1: one normalizer for keywords and input.
 */
it('normalizes alike across scripts and apostrophe variants', function (string $input, string $expected) {
    expect(ChatText::normalize($input))->toBe($expected);
})->with([
    'uz latin apostrophe' => ["o'rnatish", 'ornatish'],
    'uz latin modifier letter turned comma' => ['oʻrnatish', 'ornatish'],
    'uz latin modifier letter apostrophe' => ['oʼrnatish', 'ornatish'],
    'uz latin typographic left' => ['o‘rnatish', 'ornatish'],
    'uz latin typographic right' => ['o’rnatish', 'ornatish'],
    'uz latin backtick' => ['o`rnatish', 'ornatish'],
    'uz latin acute' => ['o´rnatish', 'ornatish'],
    'uz latin prime' => ['o′rnatish', 'ornatish'],
    'uz cyrillic' => ['ўрнатиш', 'ornatish'],
    'uz cyrillic upper' => ['ЎРНАТИШ', 'ornatish'],
    'g with apostrophe' => ["g'isht", 'gisht'],
    'g cyrillic' => ['ғишт', 'gisht'],
    'q cyrillic' => ['қидириш', 'qidirish'],
    'h cyrillic' => ['ҳужжат', 'hujjat'],
    'uz javob' => ['жавоб', 'javob'],
    'ru delivery' => ['Доставка', 'dostavka'],
    'ru yo folded' => ['Ёлка', 'elka'],
    'ru shch' => ['щётка', 'shetka'],
    'ru soft sign dropped' => ['объём', 'obem'],
    'ru phrase' => ['Статус заказа?', 'status zakaza'],
    'punctuation and spaces' => ["  Salom!!!   dunyo,,, \n\t  ", 'salom dunyo'],
    'hyphen' => ['tsc-te210', 'tsc te210'],
    'typographic dash and quotes' => ['«narx» — qancha', 'narx qancha'],
    'digits kept' => ['ZD421 300 dpi', 'zd421 300 dpi'],
    'emoji separates' => ['salom😀', 'salom'],
    'emoji between words' => ['dostavka🚚qancha', 'dostavka qancha'],
    'symbols' => ['narx ≈ 5$ ★', 'narx 5'],
    'empty' => ['', ''],
    'only punctuation' => ['?!...', ''],
]);

it('has the same normal form for a keyword and for the input in any script', function () {
    $forms = ["o'rnatish", 'oʻrnatish', 'o‘rnatish', 'ўрнатиш', 'Ўрнатиш!', 'O\'RNATISH'];

    expect(collect($forms)->map(fn (string $form) => ChatText::normalize($form))->unique()->all())->toBe(['ornatish']);
});

it('cuts to 200 characters and handles ten kilobytes of input', function () {
    $normalized = ChatText::normalize(str_repeat('salom dunyo ', 1000));

    expect(mb_strlen($normalized))->toBeLessThanOrEqual(200)
        ->and(ChatText::normalize(str_repeat('ы', 10000)))->not->toBe('');

    $started = microtime(true);
    ChatText::normalize(str_repeat("(a+)+\u{2014}", 3000));

    expect(microtime(true) - $started)->toBeLessThan(0.5);
});

it('cuts to 1000 bytes before normalizing', function () {
    $input = str_repeat('a', 1000).' tail';

    expect(ChatText::normalize($input, 5000))->toBe(str_repeat('a', 1000));
});

it('scrubs invalid UTF-8 instead of failing', function () {
    $normalized = ChatText::normalize("sal\xC3om \xFF\xFE dunyo");

    expect(mb_check_encoding($normalized, 'UTF-8'))->toBeTrue()
        ->and($normalized)->toContain('dunyo');
});

it('detects model-like text', function (string $text, bool $expected) {
    expect(ChatText::looksLikeModel(ChatText::normalize($text)))->toBe($expected);
})->with([
    ['ZD421', true],
    ['zebra zd421 narxi', true],
    ['TSC TE210', true],
    ['ds2208', true],
    ['salom', false],
    ['300', false],
    ['a1', false],
    ['доставка', false],
]);
