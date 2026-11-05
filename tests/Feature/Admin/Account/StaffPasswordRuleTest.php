<?php

use App\Models\User;
use App\Rules\StaffPassword;
use App\Support\Admin\CommonPasswords;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

function staffPasswordRuleFails(string $password, ?User $user = null): ?string
{
    $user ??= User::factory()->make([
        'name' => 'Aziz Karimov',
        'email' => 'dilshod.rahimov@poson.test',
        'password' => Hash::make('Old-Granite-Window-47'),
    ]);

    return Validator::make(['value' => $password], ['value' => [new StaffPassword($user)]])->errors()->first('value') ?: null;
}

it('accepts a long passphrase with no composition requirement', function (string $password) {
    expect(staffPasswordRuleFails($password))->toBeNull();
})->with([
    'lower-case words' => ['tall copper lantern'],
    'no digit or symbol' => ['tallcopperlantern'],
    'exactly 12 characters' => ['tallcopperla'],
    'exactly 72 bytes' => [str_repeat('tallcopper-', 6).'lanter'],
    'cyrillic' => ['высокий медный фонарь'],
]);

/**
 * $count different 4-byte characters: the fewest characters that reach a byte length.
 */
function staffPasswordRuleFourByteText(int $count): string
{
    return implode('', array_map(fn (int $offset): string => mb_chr(0x1F600 + $offset), range(0, $count - 1)));
}

it('measures the upper limit in bytes, not characters', function () {
    // UTF-8 tops out at 4 bytes a character, so over 72 bytes takes at least 19 characters.
    expect(staffPasswordRuleFails(staffPasswordRuleFourByteText(18)))->toBeNull()
        ->and(staffPasswordRuleFails(staffPasswordRuleFourByteText(19)))
        ->toBe(__('staff.password_rule.too_long', ['max' => StaffPassword::MAX_BYTES]));
});

it('refuses with the matching reason', function (string $password, string $reason) {
    expect(staffPasswordRuleFails($password))
        ->toBe(__('staff.password_rule.'.$reason, ['min' => StaffPassword::MIN_CHARACTERS, 'max' => StaffPassword::MAX_BYTES]));
})->with([
    'NUL byte' => ["tall-copper\0lantern", 'control_character'],
    'line break' => ["tall-copper\nlantern", 'control_character'],
    'tab' => ["tall-copper\tlantern", 'control_character'],
    'invalid text' => ["tall-copper\xFFlantern", 'control_character'],
    '11 characters' => ['tallcopperl', 'too_short'],
    '73 bytes' => [str_repeat('tallcopper-', 6).'lantern', 'too_long'],
    'multi-byte over 72 bytes' => [str_repeat('фонарь', 7), 'too_long'],
    'first name' => ['copper-aziz-lantern', 'personal'],
    'surname, other case' => ['copper-KARIMOV-lantern', 'personal'],
    'full name with separators' => ['a-z-i-z-k-a-r-i-m-o-v', 'personal'],
    'email local part' => ['copper-dilshod.rahimov-x', 'personal'],
    'email local part piece' => ['copper-rahimov-lantern', 'personal'],
    'poson' => ['copper-lantern-poson', 'personal'],
    'poson, other case' => ['PoSoN-copper-lantern', 'personal'],
    'poson with separators' => ['p.o.s.o.n.copper.lantern', 'personal'],
    'single character only' => ['bbbbbbbbbbbbbb', 'run'],
    'run inside' => ['copper-lanternzzzz', 'run'],
    'common' => ['administrator', 'common'],
    'common with digits' => ['password1234567', 'common'],
    'common with look-alikes' => ['P@ssw0rd2024!', 'common'],
    'keyboard' => ['qwertyuiop12', 'common'],
    'numbers' => ['123456789012', 'common'],
    'common repeated' => ['qwertyqwertyqwerty', 'common'],
    'short unit repeated' => ['abababababab', 'common'],
    'current password' => ['Old-Granite-Window-47', 'same_as_current'],
]);

it('refuses a non-string value', function () {
    $user = User::factory()->make();
    $validator = Validator::make(['value' => ['array']], ['value' => [new StaffPassword($user)]]);

    expect($validator->fails())->toBeTrue();
});

it('does not reject a passphrase just for containing a common word', function () {
    expect(CommonPasswords::contains('password lantern copper window'))->toBeFalse()
        ->and(CommonPasswords::contains('Tall-Copper-Lantern-82'))->toBeFalse();
});

it('makes no external call', function () {
    Http::preventStrayRequests();
    Http::fake();

    staffPasswordRuleFails('tall copper lantern');

    Http::assertNothingSent();
});
