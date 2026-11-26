<?php

use App\Actions\Office\SetOfficeOwners;
use App\Enums\Role;
use App\Livewire\Admin\Office\Settings;
use App\Models\BusinessConnection;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\TelegramUser;
use App\Support\Office\OfficeSettings;
use App\Support\Office\OwnerCandidates;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/../../Helpers/Office.php';

/*
| Plan 44 §7 OG-3 (abuse case 5): the owner picker and SetOfficeOwners.
| Synthetic ids only (99900xxxxx).
*/

function ownersAbuseNothingStored(): void
{
    OfficeSettings::forget();

    expect(Setting::query()->where('key', OfficeSettings::OWNERS)->exists())->toBeFalse()
        ->and(SettingsAudit::query()->where('key', OfficeSettings::OWNERS)->exists())->toBeFalse();
}

it('refuses SetOfficeOwners itself while OFFICE_OWNER_TELEGRAM_IDS is set, even for an Admin with a password', function () {
    config(['office.owner_telegram_ids' => (string) OFFICE_OWNER_ID]);
    confirmAdminPassword();

    expect(fn () => app(SetOfficeOwners::class)->handle(staffUser(), SetOfficeOwners::ADD, 9990000002))->toThrow(ValidationException::class, __('office.errors.owners_locked'))
        ->and(OfficeSettings::ownerIds())->toBe([OFFICE_OWNER_ID]);

    ownersAbuseNothingStored();
});

it('never puts the phone, customer link, registration data or another row’s owner profile in the picker HTML', function () {
    $customer = Customer::factory()->create(['name' => 'Secret Customer LLC']);
    $user = TelegramUser::factory()->registered()->create([
        'telegram_id' => 9990000002, 'first_name' => 'Pickme', 'username' => 'pickme_user',
        'phone' => '+998901112233', 'name' => 'Registered Fullname', 'declared_company' => 'Declared Company', 'declared_inn' => '301234567',
    ]);
    $user->forceFill(['customer_id' => $customer->id])->save();
    BusinessConnection::factory()->create(['owner_telegram_id' => OFFICE_OWNER_ID, 'owner_first_name' => 'Pickme owner', 'owner_username' => 'pickme_owner', 'is_allowed' => false]);
    BusinessConnection::factory()->create(['owner_telegram_id' => 9990000088, 'owner_first_name' => 'Other owner', 'owner_username' => 'hidden_other', 'is_allowed' => false]);

    $html = Livewire::actingAs(staffUser())->test(Settings::class)
        ->set('ownerSearch', 'pickme')
        ->assertSeeHtml('data-owner-candidate="9990000002"')
        ->assertSeeHtml('data-owner-candidate="'.OFFICE_OWNER_ID.'"')
        ->assertDontSeeHtml('data-owner-candidate="9990000088"')
        ->html();

    $picker = str($html)->after('data-owner-picker')->before('data-connections')->toString();

    foreach (['+998901112233', '901112233', 'Registered Fullname', 'Declared Company', '301234567', 'Secret Customer LLC', 'Other owner', 'hidden_other'] as $secret) {
        expect($picker)->not->toContain($secret);
    }

    expect($html)->not->toContain('+998901112233')
        ->and($html)->not->toContain('Secret Customer LLC')
        ->and($html)->not->toContain('Registered Fullname');
});

it('escapes LIKE wildcards and needs 2 characters', function (string $term) {
    BusinessConnection::factory()->create(['owner_telegram_id' => OFFICE_OWNER_ID, 'owner_first_name' => 'Anvar', 'owner_username' => 'anvar_x']);
    TelegramUser::factory()->create(['telegram_id' => 9990000002, 'first_name' => 'Bobur', 'username' => 'bobur']);

    expect(OwnerCandidates::search($term))->toBe([]);
})->with(['percent' => ['%%'], 'underscores' => ['__'], 'percent and text' => ['a%r'], 'escape char' => ['!%'], 'one char' => ['a'], 'one char after @' => ['@a'], 'blank' => ['   ']]);

it('matches a literal underscore only where there is one', function () {
    BusinessConnection::factory()->create(['owner_telegram_id' => OFFICE_OWNER_ID, 'owner_first_name' => 'Anvar', 'owner_username' => 'anvar_x']);
    TelegramUser::factory()->create(['telegram_id' => 9990000002, 'first_name' => 'Anvarx', 'username' => 'anvarxx']);

    expect(array_column(OwnerCandidates::search('r_x'), 'telegram_id'))->toBe([OFFICE_OWNER_ID]);
});

it('refuses an 11th owner, and dedupes before the limit', function () {
    officeSettings(['owner_telegram_ids' => range(9990000001, 9990000010)]);
    confirmAdminPassword();

    expect(fn () => app(SetOfficeOwners::class)->handle(staffUser(), SetOfficeOwners::ADD, 9990000011))->toThrow(ValidationException::class)
        // A present id at the limit is a no-op, not an error.
        ->and(app(SetOfficeOwners::class)->handle(staffUser(), SetOfficeOwners::ADD, 9990000005))->toBeFalse();

    OfficeSettings::forget();

    expect(OfficeSettings::ownerIds())->toBe(range(9990000001, 9990000010))
        ->and(SettingsAudit::query()->where('key', OfficeSettings::OWNERS)->exists())->toBeFalse();
});

it('refuses a non-Admin, even with office.settings given directly', function (Role $role) {
    confirmAdminPassword();
    $user = staffUser($role);
    $user->givePermissionTo('office.settings');

    expect(fn () => app(SetOfficeOwners::class)->handle($user, SetOfficeOwners::ADD, OFFICE_OWNER_ID))->toThrow(AuthorizationException::class);

    ownersAbuseNothingStored();
})->with([
    'sales manager' => [Role::SalesManager],
    'service engineer' => [Role::ServiceEngineer],
    'content manager' => [Role::ContentManager],
]);

it('refuses an Admin without a recent password, or with an expired or future one', function (?int $secondsAgo) {
    if ($secondsAgo !== null) {
        confirmAdminPassword($secondsAgo);
    }

    expect(fn () => app(SetOfficeOwners::class)->handle(staffUser(), SetOfficeOwners::ADD, OFFICE_OWNER_ID))->toThrow(ValidationException::class, __('office.errors.password_required'));

    ownersAbuseNothingStored();
})->with(['none' => [null], 'expired' => [3600], 'future' => [-600]]);

it('refuses tampered ids and operations', function (mixed $id, string $operation) {
    confirmAdminPassword();

    expect(fn () => app(SetOfficeOwners::class)->handle(staffUser(), $operation, $id))->toThrow(ValidationException::class);

    ownersAbuseNothingStored();
})->with([
    'list' => [[9990000001], 'add'],
    'null' => [null, 'add'],
    'zero' => [0, 'add'],
    'negative' => [-9990000001, 'add'],
    'float' => [9990000001.5, 'add'],
    'numeric float string' => ['9990000001.0', 'add'],
    'boolean' => [true, 'add'],
    'text' => ['abc', 'add'],
    'sql' => ['1 OR 1=1', 'add'],
    'exponent' => ['1e9', 'add'],
    'leading plus' => ['+9990000001', 'add'],
    'too long' => ['12345678901234567', 'add'],
    'unknown operation' => [9990000001, 'replace'],
    'remove with text' => ['abc', 'remove'],
]);

it('refuses tampered Livewire calls without storing anything', function () {
    confirmAdminPassword();

    Livewire::actingAs(staffUser())->test(Settings::class)
        ->call('addOwner', ['nested'])->assertHasErrors('owner_ids')
        ->call('addOwner', 'x; drop')->assertHasErrors('owner_ids')
        ->call('addOwner', -5)->assertHasErrors('owner_ids')
        ->call('removeOwner', ['x'])
        ->set('manualOwnerId', '0')->call('addManualOwner')->assertHasErrors('manualOwnerId');

    ownersAbuseNothingStored();

    Livewire::actingAs(staffUser())->test(Settings::class)->call('allowConnection', 999999)->assertNotFound();
    Livewire::actingAs(staffUser())->test(Settings::class)->call('allowConnection', ['x'])->assertNotFound();

    ownersAbuseNothingStored();
});

it('takes the Allow owner id from the stored row, never from the browser', function () {
    $connection = BusinessConnection::factory()->create(['owner_telegram_id' => 9990000099, 'is_allowed' => false]);
    confirmAdminPassword();

    Livewire::actingAs(staffUser())->test(Settings::class)->call('allowConnection', (string) $connection->id);

    expect(OfficeSettings::ownerIds())->toBe([9990000099]);
});

it('refuses the Settings component to a non-Admin', function () {
    Livewire::actingAs(staffUser(Role::SalesManager))->test(Settings::class)->assertForbidden();
});
