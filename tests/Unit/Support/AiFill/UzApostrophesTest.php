<?php

use App\Support\Content\AiFill\UzApostrophes;

it('maps apostrophes between letters to the right Uzbek marks', function (string $input, string $expected) {
    expect(UzApostrophes::normalize($input))->toBe($expected);
})->with([
    'o plain' => ["o'zbek", "o\u{02BB}zbek"],
    'G typographic' => ['G’oya', "G\u{02BB}oya"],
    'tutuq a' => ["ma'lumot", "ma\u{02BC}lumot"],
    'tutuq grave' => ['sanʻat', "san\u{02BC}at"],
    'already right okina' => ["o\u{02BB}rnatish", "o\u{02BB}rnatish"],
    'already right tutuq' => ["ta\u{02BC}minot", "ta\u{02BC}minot"],
    'wrong mark after o' => ["o\u{02BC}rnatish", "o\u{02BB}rnatish"],
    'quotes outside words stay' => ["'salom' dedi", "'salom' dedi"],
    'digits stay' => ["5'6", "5'6"],
]);

it('leaves html tags and attributes untouched', function () {
    $html = "<p class='x'>o'zbek</p><a href=\"https://example.com/o'brien\">g'oya</a>";

    expect(UzApostrophes::normalize($html))->toBe("<p class='x'>o\u{02BB}zbek</p><a href=\"https://example.com/o'brien\">g\u{02BB}oya</a>");
});
