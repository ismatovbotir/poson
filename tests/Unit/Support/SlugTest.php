<?php

use App\Support\Slug;
use Illuminate\Database\UniqueConstraintViolationException;

it('strips Uzbek apostrophes before slugging', function (string $input, string $slug) {
    expect(Slug::make($input))->toBe($slug);
})->with([
    'okina' => ['Oʻzbekcha gʻ', 'ozbekcha-g'],
    'tutuq belgisi' => ['Taʼminot', 'taminot'],
    'ascii and typographic' => ["O'z ‘bek’ `x`", 'oz-bek-x'],
    'cyrillic' => ['Сканеры штрих-кода', 'skanery-strix-koda'],
    'spaces and case' => ['  Label Printers  ', 'label-printers'],
    'empty' => ['', ''],
]);

it('appends a number until the slug is free', function () {
    $taken = ['scanners', 'scanners-2'];

    expect(Slug::unique('scanners', fn (string $slug): bool => in_array($slug, $taken, true)))->toBe('scanners-3')
        ->and(Slug::unique('printers', fn (): bool => false))->toBe('printers');
});

it('tells which column a unique violation is on from the driver message, not the SQL', function (string $message, bool $slug, bool $sku) {
    $pdo = new PDOException($message);
    $pdo->errorInfo = ['23000', 1062, $message];
    $exception = new UniqueConstraintViolationException('mysql', 'insert into `products` (`sku`, `slug`) values (?, ?)', [], $pdo);

    expect(Slug::violates($exception, 'slug'))->toBe($slug)
        ->and(Slug::violates($exception, 'sku'))->toBe($sku);
})->with([
    'mysql slug' => ["Duplicate entry 'zebra-sku' for key 'products.products_slug_unique'", true, false],
    'mysql sku' => ["Duplicate entry 'slug-1' for key 'products.products_sku_unique'", false, true],
    'sqlite slug' => ['UNIQUE constraint failed: products.slug', true, false],
    'sqlite sku' => ['UNIQUE constraint failed: products.sku', false, true],
]);
