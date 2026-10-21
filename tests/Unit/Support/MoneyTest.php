<?php

use App\Support\Money;

it('formats tiyin as soʻm with non-breaking thousands separators', function (int $tiyin, string $expected) {
    expect(Money::format($tiyin))->toBe($expected);
})->with([
    'million' => [125_000_000, "1\u{00A0}250\u{00A0}000\u{00A0}soʻm"],
    'thousand' => [100_000, "1\u{00A0}000\u{00A0}soʻm"],
    'small' => [50_000, "500\u{00A0}soʻm"],
    'zero' => [0, "0\u{00A0}soʻm"],
    'with tiyin' => [12_345, "123,45\u{00A0}soʻm"],
    'single tiyin' => [100_005, "1\u{00A0}000,05\u{00A0}soʻm"],
    'negative' => [-125_000_000, "-1\u{00A0}250\u{00A0}000\u{00A0}soʻm"],
]);

it('returns null for a null amount', function () {
    expect(Money::formatOrNull(null))->toBeNull()
        ->and(Money::formatOrNull(100_000))->toBe(Money::format(100_000));
});

it('uses the site.currency translation when it exists', function () {
    app('translator')->addLines(['site.currency' => 'сум'], 'ru');
    app()->setLocale('ru');

    expect(Money::format(100_000))->toBe("1\u{00A0}000\u{00A0}сум");
});

it('converts whole soʻm typed in admin to tiyin', function (int|string|null $input, ?int $tiyin) {
    expect(Money::sumsToTiyin($input))->toBe($tiyin);
})->with([
    'spaces' => ['1 250 000', 125_000_000],
    'nbsp' => ["1\u{00A0}250\u{00A0}000", 125_000_000],
    'int' => [19_500, 1_950_000],
    'zero' => ['0', 0],
    'empty' => ['', null],
    'null' => [null, null],
    'decimal' => ['12.5', null],
    'negative' => ['-5', null],
    'letters' => ['12a', null],
    'overflow' => ['1234567890123456', null],
]);

it('shows tiyin as whole soʻm for admin inputs', function () {
    expect(Money::tiyinToSums(125_000_000))->toBe('1 250 000')
        ->and(Money::tiyinToSums(12_345))->toBe('123')
        ->and(Money::tiyinToSums(null))->toBe('');
});
