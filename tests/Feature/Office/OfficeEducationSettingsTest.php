<?php

use App\Actions\Chat\RecordAiUsage;
use App\Actions\Office\InstallAgentProfiles;
use App\Actions\Office\PublishAgentProfileVersion;
use App\Actions\Office\SaveAgentProfileVersion;
use App\Actions\Office\SaveOfficeSettings;
use App\Actions\Office\WipeBusinessData;
use App\Actions\Telegram\HandleTelegramUpdate;
use App\Enums\ActivityType;
use App\Enums\AgentRole;
use App\Enums\AuditAction;
use App\Enums\LeadSource;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Enums\TelegramParty;
use App\Livewire\Admin\Office\Education;
use App\Livewire\Admin\Office\Settings;
use App\Models\Activity;
use App\Models\AgentProfile;
use App\Models\BusinessChat;
use App\Models\BusinessConnection;
use App\Models\BusinessMessage;
use App\Models\ChatAiUsageDaily;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\TelegramUser;
use App\Support\Office\OfficeSettings;
use Database\Seeders\OfficeAgentsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/../../Helpers/Office.php';

/*
| Plan 41 §5 and §8.5: Education (VO-10) and Settings (VO-1, VO-11, VO-12).
*/

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function officeSettingsData(array $overrides = []): array
{
    return array_replace_recursive([
        'ingest_enabled' => true,
        'ai_enabled' => false,
        'auto_lead' => true,
        'owner_ids' => '',
        'debounce_minutes' => 10,
        'per_chat_daily' => 12,
        'caps' => [
            'vo_route' => ['requests' => 400, 'tokens' => 1000000],
            'vo_sales' => ['requests' => 200, 'tokens' => 2000000],
            'vo_serv' => ['requests' => 200, 'tokens' => 2000000],
            'vo_support' => ['requests' => 100, 'tokens' => 1000000],
        ],
    ], $overrides);
}

it('installs the four agents once and never overwrites an edited one (LaunchSeeder, idempotent)', function () {
    expect(app(InstallAgentProfiles::class)->handle())->toBe(4);

    officeAgentVersion(AgentRole::Sales, ['instructions' => 'Edited by the Admin.']);

    expect(app(InstallAgentProfiles::class)->handle())->toBe(0)
        ->and(AgentProfile::forRole(AgentRole::Sales)->currentVersion->instructions)->toBe('Edited by the Admin.')
        ->and(AgentProfile::query()->count())->toBe(4);
});

it('keeps the seeded texts brand safe and within the limits', function () {
    foreach (InstallAgentProfiles::texts() as $role => $text) {
        $data = ['instructions' => $text['instructions'], 'rules' => $text['rules'] ?? null, 'examples' => $text['examples'] ?? [], 'model' => config("office.agents.{$role}.model"), 'max_tokens' => config("office.agents.{$role}.max_tokens")];

        $validator = Validator::make($data, SaveAgentProfileVersion::rules(AgentRole::from($role)));

        // The failing fields are named in the failure message (e.g. "sales: examples.1.reply").
        expect($validator->errors()->keys())->toBe([], $role.': '.implode(', ', $validator->errors()->keys()));
    }
});

it('runs from LaunchSeeder', function () {
    $this->seed(OfficeAgentsSeeder::class);
    $this->seed(OfficeAgentsSeeder::class);

    expect(AgentProfile::query()->whereNotNull('current_version_id')->count())->toBe(4)
        ->and(file_get_contents(database_path('seeders/LaunchSeeder.php')))->toContain('OfficeAgentsSeeder::class');
});

it('saves a new immutable version, publishes it with an audit and rolls back by publishing an older one', function () {
    $admin = staffUser();
    officeAgents();
    $profile = AgentProfile::forRole(AgentRole::Sales);
    $v1 = $profile->current_version_id;

    $v2 = app(SaveAgentProfileVersion::class)->handle($admin, AgentRole::Sales, ['instructions' => 'New instructions for the sales team.', 'model' => 'claude-sonnet-5-5', 'max_tokens' => 800], publish: true);

    expect($profile->fresh()->current_version_id)->toBe($v2->id)
        ->and($v2->version)->toBe(2)
        ->and(SettingsAudit::query()->where('key', 'office.education.sales')->where('action', AuditAction::Published->value)->exists())->toBeTrue();

    app(PublishAgentProfileVersion::class)->handle($admin, $profile->fresh(), $v1);

    expect($profile->fresh()->current_version_id)->toBe($v1)
        ->and(fn () => $v2->update(['instructions' => 'x']))->toThrow(LogicException::class);
});

it('rejects a model outside the allow-list, too many tokens and over-long texts (VO-10)', function (array $data) {
    expect(fn () => app(SaveAgentProfileVersion::class)->handle(staffUser(), AgentRole::Router, array_merge(['instructions' => 'Route chats.', 'model' => 'claude-haiku-5-5', 'max_tokens' => 300], $data)))
        ->toThrow(ValidationException::class);
})->with([
    'model' => [['model' => 'claude-opus-5-5']],
    'tokens' => [['max_tokens' => 8000]],
    'too long' => [['instructions' => str_repeat('a', 8001)]],
    'brand' => [['rules' => 'Offer the cheapest price.']],
]);

it('refuses Education and Settings to content and sales managers (VO-10)', function (Role $role) {
    $user = staffUser($role);

    expect(fn () => app(SaveAgentProfileVersion::class)->handle($user, AgentRole::Sales, ['instructions' => 'x', 'model' => 'claude-sonnet-5-5', 'max_tokens' => 900]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SaveOfficeSettings::class)->handle($user, officeSettingsData()))->toThrow(AuthorizationException::class);

    Livewire::actingAs($user)->test(Education::class)->assertForbidden();
    Livewire::actingAs($user)->test(Settings::class)->assertForbidden();
})->with([Role::ContentManager, Role::SalesManager, Role::ServiceEngineer]);

it('saves from the Education screen', function () {
    officeAgents();

    Livewire::actingAs(staffUser())->test(Education::class, ['role' => 'service'])
        ->assertSee(__('office.education.mandatory'))
        ->set('instructions', 'Updated service instructions.')
        ->call('saveAndPublish')
        ->assertHasNoErrors();

    expect(AgentProfile::forRole(AgentRole::Service)->currentVersion->instructions)->toBe('Updated service instructions.');
});

it('never changes the owner allow-list from Save: SetOfficeOwners owns it (plan 44 §1)', function () {
    $connection = BusinessConnection::factory()->create(['owner_telegram_id' => OFFICE_OWNER_ID, 'is_allowed' => false]);
    confirmAdminPassword();

    app(SaveOfficeSettings::class)->handle(staffUser(), officeSettingsData(['owner_ids' => (string) OFFICE_OWNER_ID]));

    expect(OfficeSettings::ownerIds())->toBe([])
        ->and(Setting::query()->where('key', OfficeSettings::OWNERS)->exists())->toBeFalse()
        ->and(SettingsAudit::query()->where('key', OfficeSettings::OWNERS)->exists())->toBeFalse()
        ->and($connection->fresh()->is_allowed)->toBeFalse();
});

it('ignores the submitted ids while OFFICE_OWNER_TELEGRAM_IDS locks the allow-list, and still saves (VO-1, H1)', function () {
    config(['office.owner_telegram_ids' => '9990000003']);
    $connection = BusinessConnection::factory()->create(['owner_telegram_id' => 9990000003, 'is_allowed' => false]);
    officeSettings(['ingest_enabled' => true]);

    // No password confirmation: the ids are not a change while locked.
    $changed = app(SaveOfficeSettings::class)->handle(staffUser(), officeSettingsData(['ingest_enabled' => false, 'owner_ids' => '9990000003']));

    expect($changed)->toBeGreaterThan(0)
        ->and(OfficeSettings::ownerIds())->toBe([9990000003])
        ->and(OfficeSettings::ingestEnabled())->toBeFalse()
        ->and(Setting::query()->where('key', OfficeSettings::OWNERS)->exists())->toBeFalse()
        ->and($connection->fresh()->is_allowed)->toBeTrue();

    // Another submitted value is ignored too.
    app(SaveOfficeSettings::class)->handle(staffUser(), officeSettingsData(['ingest_enabled' => false, 'owner_ids' => '9990000004']));

    expect(OfficeSettings::ownerIds())->toBe([9990000003]);
});

it('gives a validation message, not an error, for empty numeric inputs', function () {
    Livewire::actingAs(staffUser())->test(Settings::class)
        ->set('debounceMinutes', '')
        ->set('perChatDaily', '')
        ->set('caps.vo_route.requests', '')
        ->call('save')
        ->assertHasErrors(['debounce_minutes', 'per_chat_daily', 'caps.vo_route.requests']);
});

it('shows today’s cap state and links to the Bot page allowed-updates check', function () {
    ChatAiUsageDaily::query()->insert(['day' => RecordAiUsage::day(), 'channel' => 'vo_route', 'requests' => 400]);

    Livewire::actingAs(staffUser())->test(Settings::class)
        ->assertSeeHtml('data-cap-reached')
        ->assertSeeHtml('href="'.route('admin.telegram.bot').'"');
});

it('refuses a version of another agent in Education with a message and keeps compareId locked', function () {
    officeAgents();
    $other = AgentProfile::forRole(AgentRole::Sales)->current_version_id;

    Livewire::actingAs(staffUser())->test(Education::class, ['role' => 'router'])
        ->call('edit', $other)->assertHasErrors('version')
        ->call('compare', $other)->assertHasErrors('version')->assertSet('compareId', null)
        ->call('publish', $other)->assertHasErrors('version');

    expect(fn () => Livewire::actingAs(staffUser())->test(Education::class)->set('compareId', $other))->toThrow(Exception::class, 'Cannot update locked property');
});

it('builds an ordered line diff', function () {
    expect(Education::diff("a\nb\nc", "a\nx\nc"))->toBe([
        ['type' => 'same', 'line' => 'a'],
        ['type' => 'other', 'line' => 'x'],
        ['type' => 'published', 'line' => 'b'],
        ['type' => 'same', 'line' => 'c'],
    ]);
});

it('cannot switch the office AI on without the key, and defaults everything off', function () {
    config(['services.anthropic.key' => '']);

    expect(OfficeSettings::ingestEnabled())->toBeFalse()
        ->and(OfficeSettings::aiEnabled())->toBeFalse()
        ->and(OfficeSettings::ownerIds())->toBe([])
        ->and(fn () => app(SaveOfficeSettings::class)->handle(staffUser(), officeSettingsData(['ai_enabled' => true])))->toThrow(ValidationException::class);
});

it('wipes all business data after a password: ingest off first, chats gone, party marks cleared, leads kept (VO-12)', function () {
    $connection = officeIngestReady();
    $chat = BusinessChat::factory()->client()->create(['business_connection_id' => $connection->id, 'telegram_chat_id' => OFFICE_CLIENT_ID]);
    $chat->messages()->create(['telegram_message_id' => 1, 'author' => 'client', 'kind' => 'text', 'text' => 'Hi']);
    $chat->activities()->create(['type' => ActivityType::OfficeMark, 'meta' => ['to' => 'client'], 'created_at' => now()]);
    $lead = Lead::factory()->create(['source' => LeadSource::TelegramBusiness]);
    $lead->activities()->create(['type' => ActivityType::AiSummary, 'meta' => ['topic' => 'x'], 'created_at' => now()]);
    $botUser = TelegramUser::factory()->create();
    $botUser->forceFill(['party' => TelegramParty::Client])->save();
    $admin = staffUser();

    expect(fn () => app(WipeBusinessData::class)->handle($admin))->toThrow(ValidationException::class);

    confirmAdminPassword();
    app(WipeBusinessData::class)->handle($admin);

    expect(OfficeSettings::ingestEnabled())->toBeFalse()
        ->and(BusinessChat::query()->count())->toBe(0)
        ->and(BusinessMessage::query()->count())->toBe(0)
        ->and(BusinessConnection::query()->count())->toBe(0)
        ->and($botUser->fresh()->party)->toBeNull()
        ->and(Activity::query()->where('subject_type', 'business_chat')->count())->toBe(0)
        ->and($lead->fresh())->not->toBeNull()
        ->and($lead->activities()->where('type', 'ai_summary')->value('meta'))->toBeNull()
        ->and(SettingsAudit::query()->where('area', SettingsArea::Office->value)->where('key', WipeBusinessData::AUDIT_KEY)->count())->toBe(1);

    // After the wipe new business messages store nothing.
    BusinessConnection::factory()->create(['connection_id' => OFFICE_CONNECTION_ID, 'owner_telegram_id' => OFFICE_OWNER_ID]);
    app(HandleTelegramUpdate::class)->handle(officeFixture('message_client', 50));

    expect(BusinessChat::query()->count())->toBe(0);
});

it('saves settings from the screen', function () {
    Livewire::actingAs(staffUser())->test(Settings::class)
        ->set('ingestEnabled', true)
        ->set('debounceMinutes', 15)
        ->call('save')
        ->assertHasNoErrors();

    expect(OfficeSettings::ingestEnabled())->toBeTrue()
        ->and(OfficeSettings::debounceMinutes())->toBe(15);
});
