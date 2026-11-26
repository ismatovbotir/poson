<?php

use App\Actions\Office\SetOfficeOwners;
use App\Actions\Office\UpsertBusinessConnection;
use App\Actions\Telegram\HandleTelegramUpdate;
use App\Enums\Role;
use App\Livewire\Admin\Office\Settings;
use App\Models\BusinessConnection;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\TelegramUser;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Office\OfficeSettings;
use App\Support\Office\OwnerCandidates;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/../../Helpers/Office.php';

/*
| Plan 44 §1 (T1): the owner picker on Office → Settings, SetOfficeOwners,
| Allow / Remove on the Connection rows and the .env lock (OG-3).
*/

it('stores the owner first name and @username of a connection, cleaned and cut to 64 characters', function () {
    app(HandleTelegramUpdate::class)->handle(officeFixture('connection_enabled', 1));

    $connection = BusinessConnection::query()->where('connection_id', OFFICE_CONNECTION_ID)->sole();

    expect($connection->owner_first_name)->toBe('Owner')
        ->and($connection->owner_username)->toBe('fake_owner');

    app(UpsertBusinessConnection::class)->handle([
        'connectionId' => OFFICE_CONNECTION_ID, 'ownerId' => OFFICE_OWNER_ID, 'userChatId' => OFFICE_OWNER_ID, 'isEnabled' => true,
        'rights' => [], 'connectionDate' => null,
        'ownerFirstName' => "Ali\u{202E}\u{200B}\nVali ".str_repeat('x', 100), 'ownerUsername' => '   ',
    ]);

    $connection->refresh();

    expect($connection->owner_first_name)->toStartWith('Ali Vali x')
        ->and(mb_strlen($connection->owner_first_name))->toBe(64)
        ->and($connection->owner_first_name)->not->toContain("\u{202E}")
        ->and($connection->owner_username)->toBeNull();

    // An older caller without the profile keys never erases the stored name.
    app(UpsertBusinessConnection::class)->handle([
        'connectionId' => OFFICE_CONNECTION_ID, 'ownerId' => OFFICE_OWNER_ID, 'userChatId' => OFFICE_OWNER_ID, 'isEnabled' => true, 'rights' => [], 'connectionDate' => null,
    ]);

    expect($connection->fresh()->owner_first_name)->toStartWith('Ali Vali');
});

it('sets the owners after a password, audits old and new ids and recomputes connections', function () {
    $admin = staffUser();
    $connection = BusinessConnection::factory()->create(['owner_telegram_id' => OFFICE_OWNER_ID, 'is_allowed' => false]);
    confirmAdminPassword();

    expect(app(SetOfficeOwners::class)->handle($admin, SetOfficeOwners::ADD, OFFICE_OWNER_ID, '127.0.0.1'))->toBeTrue()
        ->and(app(SetOfficeOwners::class)->handle($admin, SetOfficeOwners::ADD, '9990000002'))->toBeTrue();

    $audit = SettingsAudit::query()->where('key', OfficeSettings::OWNERS)->latest('id')->first();

    expect(OfficeSettings::ownerIds())->toBe([OFFICE_OWNER_ID, 9990000002])
        ->and(Setting::query()->where('key', OfficeSettings::OWNERS)->count())->toBe(1)
        ->and($audit->old_text)->toBe((string) OFFICE_OWNER_ID)
        ->and($audit->new_text)->toBe(OFFICE_OWNER_ID.', 9990000002')
        ->and($audit->user_id)->toBe($admin->id)
        ->and($connection->fresh()->is_allowed)->toBeTrue();

    // Adding a present id or removing an absent one: no change, no audit.
    expect(app(SetOfficeOwners::class)->handle($admin, SetOfficeOwners::ADD, OFFICE_OWNER_ID))->toBeFalse()
        ->and(app(SetOfficeOwners::class)->handle($admin, SetOfficeOwners::REMOVE, 9990000055))->toBeFalse()
        ->and(SettingsAudit::query()->where('key', OfficeSettings::OWNERS)->count())->toBe(2);

    app(SetOfficeOwners::class)->handle($admin, SetOfficeOwners::REMOVE, OFFICE_OWNER_ID);

    expect(OfficeSettings::ownerIds())->toBe([9990000002])
        ->and(SettingsAudit::query()->where('key', OfficeSettings::OWNERS)->latest('id')->first()->old_text)->toBe(OFFICE_OWNER_ID.', 9990000002')
        ->and($connection->fresh()->is_allowed)->toBeFalse();
});

it('merges an add with the stored list, never a stale copy (no lost update)', function () {
    confirmAdminPassword();

    // This request has read the list (empty) ...
    expect(OfficeSettings::ownerIds())->toBe([]);

    // ... while another Admin stores an owner without this request noticing.
    Setting::query()->create(['key' => OfficeSettings::OWNERS, 'group' => 'office', 'value' => [9990000002]]);

    app(SetOfficeOwners::class)->handle(staffUser(), SetOfficeOwners::ADD, OFFICE_OWNER_ID);

    expect(OfficeSettings::ownerIds())->toBe([9990000002, OFFICE_OWNER_ID]);
});

it('lists candidates from connections and bot users with their source, never banned users', function () {
    BusinessConnection::factory()->create(['owner_telegram_id' => OFFICE_OWNER_ID, 'owner_first_name' => 'Botir', 'owner_username' => 'botir_owner', 'is_allowed' => false]);
    TelegramUser::factory()->create(['telegram_id' => 9990000002, 'first_name' => 'Botirjon', 'username' => 'botirjon', 'last_inbound_at' => now()]);
    TelegramUser::factory()->banned()->create(['telegram_id' => 9990000003, 'first_name' => 'Botir banned', 'username' => 'banned_one']);

    $results = collect(OwnerCandidates::search('bot'));

    expect($results->pluck('source', 'telegram_id')->all())->toBe([OFFICE_OWNER_ID => 'connection', 9990000002 => 'bot_user'])
        ->and(OwnerCandidates::search('b'))->toBe([])
        ->and(OwnerCandidates::search('@botir_owner')[0]['telegram_id'])->toBe(OFFICE_OWNER_ID)
        ->and(OwnerCandidates::search((string) 9990000002)[0]['telegram_id'])->toBe(9990000002)
        ->and(OwnerCandidates::search('bot', [OFFICE_OWNER_ID]))->toHaveCount(1);

    Livewire::actingAs(staffUser())->test(Settings::class)
        ->set('ownerSearch', 'bo')
        ->assertSeeHtml('data-owner-candidate="'.OFFICE_OWNER_ID.'"')
        ->assertSeeHtml('data-owner-candidate="9990000002"')
        ->assertDontSeeHtml('data-owner-candidate="9990000003"')
        ->assertSee('@botir_owner')
        ->assertSee(__('office.settings.owner_source_connection'))
        ->assertSee(__('office.settings.owner_source_bot_user'))
        ->assertSee(trans_choice('office.settings.owner_results', 2, ['count' => 2]))
        ->assertDontSeeHtml('role="listbox"');
});

it('caps the candidates at 20', function () {
    TelegramUser::factory()->count(25)->sequence(fn ($sequence) => ['first_name' => 'Same '.$sequence->index])->create();

    expect(OwnerCandidates::search('same'))->toHaveCount(20);
});

it('adds a candidate from the picker and shows it as a chip; an unknown id is “Unknown account #id”', function () {
    BusinessConnection::factory()->create(['owner_telegram_id' => OFFICE_OWNER_ID, 'owner_first_name' => 'Botir', 'is_allowed' => false]);
    officeSettings(['owner_telegram_ids' => [9990000077]]);
    confirmAdminPassword();

    Livewire::actingAs(staffUser())->test(Settings::class)
        ->assertSee(__('office.settings.owner_unknown', ['id' => 9990000077]))
        ->set('ownerSearch', 'Botir')
        ->call('addOwner', OFFICE_OWNER_ID)
        ->assertHasNoErrors()
        ->assertSet('ownerSearch', '')
        ->assertSeeHtml('data-owner-chip="'.OFFICE_OWNER_ID.'"')
        ->assertSee('Botir');

    expect(OfficeSettings::ownerIds())->toBe([9990000077, OFFICE_OWNER_ID]);
});

it('adds an owner by id and removes a chip', function () {
    confirmAdminPassword();

    $component = Livewire::actingAs(staffUser())->test(Settings::class)
        ->assertSeeHtml('data-owner-manual')
        ->set('manualOwnerId', 'abc')
        ->call('addManualOwner')
        ->assertHasErrors('manualOwnerId')
        ->set('manualOwnerId', (string) OFFICE_OWNER_ID)
        ->call('addManualOwner')
        ->assertHasNoErrors()
        ->assertSet('manualOwnerId', '');

    expect(OfficeSettings::ownerIds())->toBe([OFFICE_OWNER_ID]);

    $component->call('removeOwner', OFFICE_OWNER_ID);

    expect(OfficeSettings::ownerIds())->toBe([]);
});

it('sends to the password page before changing the owners without a recent confirmation', function () {
    Livewire::actingAs(staffUser())->test(Settings::class)
        ->call('addOwner', OFFICE_OWNER_ID)
        ->assertRedirect(PasswordConfirmation::url(route('admin.office.settings')));

    expect(OfficeSettings::ownerIds())->toBe([]);
});

it('allows and removes the owner of a Connection row with the same action', function () {
    $stranger = BusinessConnection::factory()->create(['owner_telegram_id' => 9990000099, 'owner_first_name' => 'Stranger', 'is_allowed' => false]);
    confirmAdminPassword();

    $component = Livewire::actingAs(staffUser())->test(Settings::class)
        ->assertSeeHtml('data-connection-allow="'.$stranger->id.'"')
        ->assertDontSeeHtml('data-connection-remove="'.$stranger->id.'"')
        ->assertSee(__('office.settings.owners_lost_hint'))
        ->call('allowConnection', $stranger->id)
        ->assertHasNoErrors()
        ->assertSeeHtml('data-connection-remove="'.$stranger->id.'"');

    expect(OfficeSettings::ownerIds())->toBe([9990000099])
        ->and($stranger->fresh()->is_allowed)->toBeTrue()
        ->and(SettingsAudit::query()->where('key', OfficeSettings::OWNERS)->count())->toBe(1);

    $component->call('removeConnection', $stranger->id)
        ->assertSeeHtml('data-connection-allow="'.$stranger->id.'"');

    expect(OfficeSettings::ownerIds())->toBe([])
        ->and($stranger->fresh()->is_allowed)->toBeFalse();
});

it('hides search, Add by ID and the add buttons once 10 owners are set', function () {
    officeSettings(['owner_telegram_ids' => range(9990000001, 9990000010)]);
    $connection = BusinessConnection::factory()->create(['owner_telegram_id' => 9990000050, 'is_allowed' => false]);

    Livewire::actingAs(staffUser())->test(Settings::class)
        ->assertDontSeeHtml('data-owner-search')
        ->assertDontSeeHtml('data-owner-manual')
        ->assertDontSeeHtml('data-connection-allow="'.$connection->id.'"')
        ->assertSee(__('office.settings.owners_max', ['max' => 10]));
});

it('makes the picker read-only while OFFICE_OWNER_TELEGRAM_IDS is set: resolved chips, no Allow, a hint', function () {
    config(['office.owner_telegram_ids' => (string) OFFICE_OWNER_ID]);
    BusinessConnection::factory()->create(['owner_telegram_id' => OFFICE_OWNER_ID, 'owner_first_name' => 'Locked owner', 'owner_username' => 'locked_owner']);
    $stranger = BusinessConnection::factory()->create(['owner_telegram_id' => 9990000099, 'is_allowed' => false]);
    confirmAdminPassword();

    Livewire::actingAs(staffUser())->test(Settings::class)
        ->assertSeeHtml('data-owners-locked')
        ->assertSee(__('office.settings.owners_locked_hint'))
        ->assertSeeHtml('data-owner-chip="'.OFFICE_OWNER_ID.'"')
        ->assertSee('Locked owner')
        ->assertSee('@locked_owner')
        ->assertDontSeeHtml('data-owner-remove=')
        ->assertDontSeeHtml('data-owner-search')
        ->assertDontSeeHtml('data-owner-manual')
        ->assertDontSeeHtml('data-connection-allow=')
        ->assertDontSeeHtml('data-connection-remove=')
        ->call('allowConnection', $stranger->id)
        ->assertHasErrors('owner_ids');

    expect(OfficeSettings::ownerIds())->toBe([OFFICE_OWNER_ID])
        ->and(Setting::query()->where('key', OfficeSettings::OWNERS)->exists())->toBeFalse();
});

it('refuses the Settings screen and the owner action to every role but the Admin', function (Role $role) {
    confirmAdminPassword();
    $user = staffUser($role);

    $this->actingAs($user)->get(route('admin.office.settings'))->assertForbidden();

    expect(fn () => app(SetOfficeOwners::class)->handle($user, SetOfficeOwners::ADD, OFFICE_OWNER_ID))->toThrow(AuthorizationException::class)
        ->and(OfficeSettings::ownerIds())->toBe([]);
})->with([
    'sales manager' => [Role::SalesManager],
    'service engineer' => [Role::ServiceEngineer],
    'content manager' => [Role::ContentManager],
]);

it('opens Settings with the picker for the Admin', function () {
    $this->actingAs(staffUser())->get(route('admin.office.settings'))
        ->assertOk()
        ->assertSee('data-owner-picker', false);
});

it('gives a validation message for an 11th owner', function () {
    officeSettings(['owner_telegram_ids' => range(9990000001, 9990000010)]);
    confirmAdminPassword();

    expect(fn () => app(SetOfficeOwners::class)->handle(staffUser(), SetOfficeOwners::ADD, 9990000011))->toThrow(ValidationException::class, __('office.settings.owners_max', ['max' => 10]))
        ->and(OfficeSettings::ownerIds())->toHaveCount(10);
});

it('keeps the stored owner name when an update carries no profile keys', function () {
    BusinessConnection::factory()->create(['connection_id' => OFFICE_CONNECTION_ID, 'owner_telegram_id' => OFFICE_OWNER_ID, 'owner_first_name' => 'Kept name', 'owner_username' => 'kept_user']);

    app(UpsertBusinessConnection::class)->handle([
        'connectionId' => OFFICE_CONNECTION_ID, 'ownerId' => OFFICE_OWNER_ID, 'userChatId' => OFFICE_OWNER_ID, 'isEnabled' => true, 'rights' => [], 'connectionDate' => null,
    ]);

    $connection = BusinessConnection::query()->where('connection_id', OFFICE_CONNECTION_ID)->sole();

    expect($connection->owner_first_name)->toBe('Kept name')
        ->and($connection->owner_username)->toBe('kept_user');
});

it('shows one candidate per owner, so many reconnects never crowd out others', function () {
    BusinessConnection::factory()->count(25)->create(['owner_telegram_id' => OFFICE_OWNER_ID, 'owner_first_name' => 'Ravshan']);
    BusinessConnection::factory()->create(['owner_telegram_id' => 9990000002, 'owner_first_name' => 'Ravshanbek', 'updated_at' => now()->subDay()]);

    expect(array_column(OwnerCandidates::search('ravshan'), 'telegram_id'))->toBe([OFFICE_OWNER_ID, 9990000002]);
});

it('still shows the name of a selected owner who is banned in the bot; search skips them', function () {
    TelegramUser::factory()->banned()->create(['telegram_id' => 9990000003, 'first_name' => 'Banned owner']);
    officeSettings(['owner_telegram_ids' => [9990000003]]);

    expect(OwnerCandidates::resolve([9990000003])[0]['first_name'])->toBe('Banned owner')
        ->and(OwnerCandidates::search('banned'))->toBe([]);
});
