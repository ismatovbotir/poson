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
