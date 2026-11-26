<?php

use App\Actions\Office\PurgeGroupData;
use App\Actions\Office\UntrackGroup;
use App\Actions\Office\WipeBusinessData;
use App\Enums\GroupStatus;
use App\Enums\Role;
use App\Jobs\WipeGroupDataChunk;
use App\Livewire\Admin\Office\Groups\Index;
use App\Livewire\Admin\Office\Groups\Show;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\SettingsAudit;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupAnalysis;
use App\Models\TelegramGroupMember;
use App\Models\TelegramGroupMessage;
use App\Models\TelegramGroupThread;
use App\Support\Office\OfficeLock;
use App\Support\Office\OfficeSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/../../Helpers/Office.php';
require_once __DIR__.'/../../Helpers/Groups.php';

/*
| Plan 44 T7 / OG-12: Untrack and Wipe-all for Telegram groups. Synthetic data only.
*/

function trackedGroupFull(?int $assigneeId = null): array
{
    $customer = Customer::factory()->create();
    $group = TelegramGroup::factory()->tracked()->create(['assignee_id' => $assigneeId, 'customer_id' => $customer->id, 'analysis_due_at' => now()->addMinutes(5)]);
    $member = TelegramGroupMember::factory()->create(['telegram_group_id' => $group->id, 'phone' => '+998901112233']);
    TelegramGroupMessage::factory()->count(3)->create(['member_id' => $member->id, 'telegram_group_id' => $group->id]);
    TelegramGroupAnalysis::factory()->done()->create(['telegram_group_id' => $group->id, 'draft_reply' => 'Secret draft']);
    $lead = Lead::factory()->create();
    $thread = TelegramGroupThread::factory()->create(['telegram_group_id' => $group->id, 'client_member_id' => $member->id, 'lead_id' => $lead->id, 'draft' => 'Secret thread draft']);

    return [$group, $member, $lead, $thread];
}

function groupRowsFor(TelegramGroup $group): int
{
    return TelegramGroupMessage::query()->where('telegram_group_id', $group->id)->count()
        + TelegramGroupMember::query()->where('telegram_group_id', $group->id)->count()
        + TelegramGroupAnalysis::query()->where('telegram_group_id', $group->id)->count()
        + TelegramGroupThread::query()->where('telegram_group_id', $group->id)->count();
}

it('refuses Untrack without a recent password and deletes nothing', function () {
    [$group] = trackedGroupFull();
    $admin = staffUser(Role::Admin);
    $before = groupRowsFor($group);

    expect(fn () => app(UntrackGroup::class)->handle($admin, $group))->toThrow(ValidationException::class)
        ->and(groupRowsFor($group))->toBe($before)
        ->and($group->fresh()->status)->toBe(GroupStatus::Tracked);

    // From the screen: a redirect to the password page, nothing deleted.
    Livewire::actingAs($admin)->test(Show::class, ['group' => $group])->call('untrack')->assertRedirect();
    Livewire::actingAs($admin)->test(Index::class)->set('tab', 'tracked')->call('untrack', $group->id)->assertRedirect();

    expect(groupRowsFor($group))->toBe($before);
});

it('refuses Untrack with a stale password confirmation', function () {
    [$group] = trackedGroupFull();
    confirmAdminPassword(3 * 3600);

    expect(fn () => app(UntrackGroup::class)->handle(staffUser(Role::Admin), $group))->toThrow(ValidationException::class)
        ->and($group->fresh()->status)->toBe(GroupStatus::Tracked);
});

it('answers 403 to a non-Admin on Untrack, even for the assignee, with the password confirmed', function (Role $role) {
    $user = staffUser($role);
    [$group] = trackedGroupFull($user->id);
    confirmAdminPassword();
    $before = groupRowsFor($group);

    Livewire::actingAs($user)->test(Show::class, ['group' => $group])->call('untrack')->assertForbidden();
    Livewire::actingAs($user)->test(Index::class)->call('untrack', $group->id)->assertForbidden();

    expect(fn () => app(UntrackGroup::class)->handle($user, $group))->toThrow(AuthorizationException::class)
        ->and(groupRowsFor($group))->toBe($before)
        ->and($group->fresh()->status)->toBe(GroupStatus::Tracked);
})->with([Role::SalesManager, Role::ServiceEngineer]);

it('untracks: data deleted, leads kept, phone and links cleared, counts-only audit', function () {
    $admin = staffUser(Role::Admin);
    [$group, $member, $lead] = trackedGroupFull($admin->id);
    confirmAdminPassword();

    $counts = app(UntrackGroup::class)->handle($admin, $group);

    $fresh = $group->fresh();

    expect($counts)->toBe(['messages' => 3, 'threads' => 1, 'analyses' => 1, 'members' => 1])
        ->and(groupRowsFor($group))->toBe(0)
        ->and($lead->fresh())->not->toBeNull()
        ->and($fresh->status)->toBe(GroupStatus::Untracked)
        ->and($fresh->assignee_id)->toBeNull()
        ->and($fresh->customer_id)->toBeNull()
        ->and($fresh->tracked_at)->toBeNull()
        ->and($fresh->analysis_due_at)->toBeNull()
        ->and(TelegramGroupMember::query()->whereNotNull('phone')->count())->toBe(0);

    $audit = SettingsAudit::query()->where('key', 'office.group.'.$group->id.'.untracked')->sole();

    expect($audit->new_text)->toBe('messages=3 members=1 analyses=1 threads=1')
        ->and(json_encode($audit->toArray()))->not->toContain('Secret')->not->toContain($group->title);
});

it('refuses to untrack a group that is not tracked', function () {
    $group = TelegramGroup::factory()->untracked()->create();
    confirmAdminPassword();

    expect(fn () => app(UntrackGroup::class)->handle(staffUser(Role::Admin), $group))->toThrow(ValidationException::class);
});

it('refuses Untrack while an analysis holds the group lock and deletes nothing', function () {
    config()->set('office.lock_wait_seconds', 0);
    [$group] = trackedGroupFull();
    confirmAdminPassword();
    $before = groupRowsFor($group);
    $lock = OfficeLock::forGroup($group->id);
    expect($lock->get())->toBeTrue();

    expect(fn () => app(UntrackGroup::class)->handle(staffUser(Role::Admin), $group))->toThrow(ValidationException::class)
        ->and(groupRowsFor($group))->toBe($before)
        ->and($group->fresh()->status)->toBe(GroupStatus::Tracked);

    $lock->release();

    app(UntrackGroup::class)->handle(staffUser(Role::Admin), $group);

    expect(groupRowsFor($group))->toBe(0);
});

it('untracks from the groups list and from the group screen', function () {
    $admin = staffUser(Role::Admin);
    [$one] = trackedGroupFull();
    [$two] = trackedGroupFull();
    confirmAdminPassword();

    Livewire::actingAs($admin)->test(Index::class)->set('tab', 'tracked')->call('untrack', $one->id)->assertHasNoErrors();
    Livewire::actingAs($admin)->test(Show::class, ['group' => $two])->call('untrack')->assertHasNoErrors()->assertRedirect(route('admin.office.groups'));

    expect(groupRowsFor($one) + groupRowsFor($two))->toBe(0)
        ->and($one->fresh()->status)->toBe(GroupStatus::Untracked)
        ->and($two->fresh()->status)->toBe(GroupStatus::Untracked);
});

it('Wipe-all switches groups off, removes everything group-related except leads and reduces the groups to stubs', function () {
    officeSettings(['ingest_enabled' => true, 'groups_enabled' => true]);
    $admin = staffUser(Role::Admin);
    [$tracked, , $lead] = trackedGroupFull($admin->id);
    $ignored = TelegramGroup::factory()->ignored()->create(['title' => 'Stale title']);
    $untracked = TelegramGroup::factory()->untracked()->create(['seen_count' => 7]);
    TelegramGroupMessage::factory()->create(['telegram_group_id' => $untracked->id]);
    confirmAdminPassword();

    app(WipeBusinessData::class)->handle($admin);

    expect(OfficeSettings::groupsEnabled())->toBeFalse()
        ->and(OfficeSettings::groupsStored())->toBeFalse()
        ->and(TelegramGroupMessage::query()->count())->toBe(0)
        ->and(TelegramGroupMember::query()->count())->toBe(0)
        ->and(TelegramGroupAnalysis::query()->count())->toBe(0)
        ->and(TelegramGroupThread::query()->count())->toBe(0)
        ->and($lead->fresh())->not->toBeNull()
        ->and($tracked->fresh()->status)->toBe(GroupStatus::Untracked)
        ->and($tracked->fresh()->title)->toBeNull()
        ->and($tracked->fresh()->assignee_id)->toBeNull()
        ->and($tracked->fresh()->customer_id)->toBeNull()
        ->and($ignored->fresh()->status)->toBe(GroupStatus::Ignored)
        ->and($ignored->fresh()->title)->toBeNull()
        ->and($untracked->fresh()->seen_count)->toBe(7)
        ->and(SettingsAudit::query()->where('key', OfficeSettings::GROUPS)->count())->toBe(1);
});

it('stores nothing from an incoming group message after the wipe, even for a group that was tracked', function () {
    Http::preventStrayRequests();
    groupsReady();
    $group = groupTracked(GROUP_SUPER_ID);
    $admin = staffUser(Role::Admin);
    confirmAdminPassword();

    app(WipeBusinessData::class)->handle($admin);

    groupHandle(groupFixture('message_supergroup', 71));

    expect(TelegramGroupMessage::query()->count())->toBe(0)
        ->and(TelegramGroupMember::query()->count())->toBe(0)
        ->and($group->fresh()->status)->toBe(GroupStatus::Untracked);

    // Even if an Admin only switches ingest back on, groups stay off until switched on explicitly.
    officeSettings(['ingest_enabled' => true]);
    groupHandle(groupFixture('message_supergroup', 72));

    expect(TelegramGroupMessage::query()->count())->toBe(0);
});

it('Wipe-all retries a group whose analysis lock is held and finishes once it is free', function () {
    officeSettings(['ingest_enabled' => true, 'groups_enabled' => true]);
    [$group] = trackedGroupFull();
    $lock = OfficeLock::forGroup($group->id);
    expect($lock->get())->toBeTrue();
    confirmAdminPassword();

    $job = WipeGroupDataChunk::forNow();
    Queue::fake();
    $job->handle(app(PurgeGroupData::class));

    expect(groupRowsFor($group))->toBeGreaterThan(0)
        ->and($group->fresh()->status)->toBe(GroupStatus::Tracked);
    Queue::assertPushed(WipeGroupDataChunk::class);

    $lock->release();
    $job->handle(app(PurgeGroupData::class));

    expect(groupRowsFor($group))->toBe(0)
        ->and($group->fresh()->status)->toBe(GroupStatus::Untracked);
});

it('Wipe-all without a password changes nothing about groups', function () {
    officeSettings(['ingest_enabled' => true, 'groups_enabled' => true]);
    [$group] = trackedGroupFull();
    $before = groupRowsFor($group);

    expect(fn () => app(WipeBusinessData::class)->handle(staffUser(Role::Admin)))->toThrow(ValidationException::class)
        ->and(groupRowsFor($group))->toBe($before)
        ->and(OfficeSettings::groupsEnabled())->toBeTrue();
});
