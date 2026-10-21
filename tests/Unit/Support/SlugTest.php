<?php

use App\Support\Slug;

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
