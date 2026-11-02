<?php

use App\Rules\UzPhone;
use App\Support\Phone;
use Illuminate\Support\Facades\Validator;

it('normalizes Uzbek phone numbers to E.164', function (string $input) {
    expect(Phone::normalize($input))->toBe('+998901234567');
})->with([
    '90 123 45 67',
    '901234567',
    '+998901234567',
    '998901234567',
    '(90) 123-45-67',
    '+998 (90) 123-45-67',
    '+998 90 123 45 67',
    '00998901234567',
    '  90.123.45.67  ',
]);

it('rejects invalid phone numbers', function (?string $input) {
    expect(Phone::normalize($input))->toBeNull();
})->with([
    'null' => null,
    'empty' => '',
    'too short' => '90 123 45',
    'too long' => '+998 90 123 45 678',
    'other country' => '+7 912 345 67 89',
    'letters' => '90 123 45 6a',
    'plus in the middle' => '90+1234567',
    'local with plus' => '+901234567',
    'zero operator code' => '000000000',
    'zero operator code with country code' => '+998 00 123 45 67',
]);

it('formats phone numbers for display', function () {
    expect(Phone::display('901234567'))->toBe('+998 (90) 123-45-67')
        ->and(Phone::display('+998712000000'))->toBe('+998 (71) 200-00-00')
        ->and(Phone::display('+998000000000'))->toBe('+998000000000')
        ->and(Phone::display('not a phone'))->toBe('not a phone');
});

it('validates phones with the UzPhone rule', function (mixed $value, bool $passes) {
    $validator = Validator::make(['phone' => $value], ['phone' => [new UzPhone]]);

    expect($validator->passes())->toBe($passes);
})->with([
    ['(90) 123-45-67', true],
    ['+998901234567', true],
    ['12345', false],
    [12345, false],
]);

it('has a translated UzPhone message in every locale', function (string $locale) {
    app()->setLocale($locale);

    $message = Validator::make(['phone' => '123'], ['phone' => [new UzPhone]])->errors()->first('phone');

    expect($message)->not->toBe('validation.uz_phone')->toContain('+998');
})->with(['uz', 'ru', 'en']);

it('gives normalizeAny the same result as normalize for every Uzbek form', function (string $input) {
    expect(Phone::normalizeAny($input))->toBe('+998901234567')->toBe(Phone::normalize($input));
})->with([
    '90 123 45 67', '901234567', '+998901234567', '998901234567', '(90) 123-45-67',
    '+998 (90) 123-45-67', '+998 90 123 45 67', '00998901234567', '  90.123.45.67  ',
]);

it('normalizes foreign numbers to +digits', function (string $input, string $expected) {
    expect(Phone::normalizeAny($input))->toBe($expected)
        ->and(Phone::isUzbek($input))->toBeFalse()
        ->and(Phone::isForeign($input))->toBeTrue();
})->with([
    ['+7 916 123-45-67', '+79161234567'],
    ['007 (916) 123 45 67', '+79161234567'],
    ['+44 20 7946 0958', '+442079460958'],
    ['+1 (202) 555-0143', '+12025550143'],
    ['+86 138 0013 8000', '+8613800138000'],
]);

it('refuses ambiguous or malformed numbers in normalizeAny', function (?string $input) {
    expect(Phone::normalizeAny($input))->toBeNull();
})->with([
    'null' => null,
    'empty' => '',
    'no plus or 00' => '8 916 1234567',
    'plain 11 digits' => '79161234567',
    'too short' => '+7 916 123',
    'too long' => '+1234567890123456',
    'letters' => '+7 916 abc 45 67',
    'extension' => '+7 916 123 45 67 ext 5',
    'plus in the middle' => '7+9161234567',
    'two pluses' => '++79161234567',
    'zero first digit' => '+0 916 123 45 67',
    'malformed uzbek' => '+998 90 123 45 678',
    'malformed uzbek short' => '+998 90 123',
    'malformed uzbek 00' => '00998 90 123',
    'uzbek with a stray plus' => '+90 123 45 67',
    'uzbek with a stray plus 97' => '+97 123 45 67',
    'uzbek with a stray 00' => '0090 123 45 67',
]);

it('shows foreign numbers as +digits and leaves Uzbek formatting alone', function () {
    expect(Phone::display('+7 916 123-45-67'))->toBe('+79161234567')
        ->and(Phone::display('+998901234567'))->toBe('+998 (90) 123-45-67')
        ->and(Phone::display('rubbish'))->toBe('rubbish');
});

it('still accepts foreign numbers whose national part looks like a UZ code but is longer or shorter', function () {
    expect(Phone::normalizeAny('+90 212 123 45 67'))->toBe('+902121234567')
        ->and(Phone::normalizeAny('+33 1 23 45 67 89'))->toBe('+33123456789')
        ->and(Phone::isForeign('+90 123 45 67'))->toBeFalse()
        ->and(Phone::isForeign('+998 90 123 45 67'))->toBeFalse()
        ->and(Phone::isForeign('90 123 45 67'))->toBeFalse();
});
