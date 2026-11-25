<?php

use App\Rules\BrandSafeText;
use Illuminate\Support\Arr;

/**
 * Plan 20 §8: every Mini App string passes the brand rule (no discount or
 * low-price wording), never names Payme / Click, and the uz copy uses ʻ / ʼ.
 */
it('keeps every tma.php string brand safe in every locale', function (string $locale) {
    $strings = Arr::dot(require lang_path("{$locale}/tma.php"));

    expect($strings)->not->toBeEmpty();

    foreach ($strings as $key => $value) {
        expect(BrandSafeText::violates((string) $value))->toBeFalse("{$locale}.tma.{$key}")
            ->and(preg_match('/\b(payme|click)\b/iu', (string) $value))->toBe(0, "{$locale}.tma.{$key}");
    }
})->with(['uz', 'ru', 'en']);

it('writes the new order source and chat label in every locale', function (string $locale) {
    expect(__('enums.order_source.telegram', [], $locale))->toBe('Telegram Mini App')
        ->and(__('telegram.order.source', [], $locale))->not->toBe('telegram.order.source');
})->with(['uz', 'ru', 'en']);
