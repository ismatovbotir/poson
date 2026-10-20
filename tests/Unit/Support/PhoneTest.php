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
]);

it('formats phone numbers for display', function () {
    expect(Phone::display('901234567'))->toBe('+998 (90) 123-45-67')
        ->and(Phone::display('+998000000000'))->toBe('+998 (00) 000-00-00')
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
