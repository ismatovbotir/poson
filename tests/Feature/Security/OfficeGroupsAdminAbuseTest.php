<?php

use App\Actions\Office\ForgetGroupMember;
use App\Actions\Office\SetGroupMemberRoles;
use App\Actions\Office\TrackGroup;
use App\Enums\GroupMemberRole;
use App\Enums\GroupStatus;
use App\Enums\Role;
use App\Enums\TelegramParty;
use App\Livewire\Admin\Office\Groups\Index;
use App\Livewire\Admin\Office\Groups\Show;
use App\Models\Customer;
use App\Models\SettingsAudit;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupAnalysis;
use App\Models\TelegramGroupMember;
use App\Models\TelegramGroupMessage;
use App\Models\TelegramGroupThread;
use App\Models\TelegramUser;
use App\Support\Office\CustomerCandidates;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| Plan 44 §7 abuse cases 9 and 12 (admin side): groups of other staff,
| tampered member ids, Forget, the Track tick, and no write for a
| non-Admin. Synthetic data only.
*/

it('case 9: a sales user on another group gets 403 on the page and on every Livewire method', function () {
    $sales = staffUser(Role::SalesManager);
    $other = TelegramGroup::factory()->tracked(staffUser(Role::ServiceEngineer))->create();
    $member = TelegramGroupMember::factory()->create(['telegram_group_id' => $other->id, 'phone' => '+998901112233']);

    $this->actingAs($sales)->get(route('admin.office.groups.show', $other))->assertForbidden();

    // Even on a group of their own, writes are Admin only.
    $mine = TelegramGroup::factory()->tracked($sales)->create();
    $mineMember = TelegramGroupMember::factory()->create(['telegram_group_id' => $mine->id]);
    confirmAdminPassword();
    $fresh = fn () => Livewire::actingAs($sales)->test(Show::class, ['group' => $mine]);

    $fresh()->set('selected', [(string) $mineMember->id])->set('bulkRole', 'client')->call('setRole')->assertForbidden();
    $fresh()->call('forget', $mineMember->id)->assertForbidden();
    $fresh()->call('refreshAdmins')->assertForbidden();

    expect($mineMember->fresh()->role)->toBe(GroupMemberRole::Unknown)
        ->and(SettingsAudit::query()->where('key', 'like', 'office.group.%')->count())->toBe(0);
});

it('case 9: a tampered member id from another group in a bulk role is refused and nothing is written', function () {
    $admin = staffUser(Role::Admin);
    $group = TelegramGroup::factory()->tracked()->create();
    $foreignGroup = TelegramGroup::factory()->tracked()->create();
    $own = TelegramGroupMember::factory()->create(['telegram_group_id' => $group->id]);
    $foreign = TelegramGroupMember::factory()->create(['telegram_group_id' => $foreignGroup->id]);
    $botUser = TelegramUser::factory()->create(['telegram_id' => $own->telegram_id, 'party' => null]);

    Livewire::actingAs($admin)->test(Show::class, ['group' => $group])
        ->set('selected', [(string) $own->id, (string) $foreign->id])
        ->set('bulkRole', 'client')
        ->call('setRole')
        ->assertHasErrors('members');

    expect($own->fresh()->role)->toBe(GroupMemberRole::Unknown)
        ->and($foreign->fresh()->role)->toBe(GroupMemberRole::Unknown)
        ->and($botUser->fresh()->party)->toBeNull()
        ->and(SettingsAudit::query()->count())->toBe(0);

    // Non-numeric and negative ids are refused too.
    foreach ([['abc'], [-1], [0], [[1]]] as $ids) {
        expect(fn () => app(SetGroupMemberRoles::class)->handle($admin, $group, $ids, GroupMemberRole::Client))->toThrow(ValidationException::class);
    }

    // The Forget action is scoped to its group as well.
    confirmAdminPassword();
    TelegramGroupMessage::factory()->create(['member_id' => $foreign->id, 'telegram_group_id' => $foreignGroup->id]);

    expect(fn () => app(ForgetGroupMember::class)->handle($admin, $group, $foreign->id))->toThrow(ValidationException::class)
        ->and(TelegramGroupMessage::query()->count())->toBe(1);
});

it('refuses the bot role being set or removed, also through the component', function () {
    $group = TelegramGroup::factory()->tracked()->create();
    $bot = TelegramGroupMember::factory()->bot()->create(['telegram_group_id' => $group->id]);

    Livewire::actingAs(staffUser(Role::Admin))->test(Show::class, ['group' => $group])
        ->set('selected', [(string) $bot->id])->set('bulkRole', 'bot')->call('setRole')->assertHasErrors('bulkRole')
        ->set('bulkRole', 'unknown')->call('setRole')->assertHasErrors('role');

    expect($bot->fresh()->role)->toBe(GroupMemberRole::Bot);
});

it('refuses to set roles in a group that is not tracked', function () {
    $group = TelegramGroup::factory()->untracked()->create();
    $member = TelegramGroupMember::factory()->create(['telegram_group_id' => $group->id]);

    expect(fn () => app(SetGroupMemberRoles::class)->handle(staffUser(Role::Admin), $group, [$member->id], GroupMemberRole::Client))->toThrow(ValidationException::class)
        ->and($member->fresh()->role)->toBe(GroupMemberRole::Unknown);
});

it('case 12: Forget needs a recent password, deletes the messages and nulls summaries', function () {
    $admin = staffUser(Role::Admin);
    $group = TelegramGroup::factory()->tracked()->create();
    $member = TelegramGroupMember::factory()->create(['telegram_group_id' => $group->id, 'phone' => '+998901112233']);
    TelegramGroupMessage::factory()->count(2)->create(['member_id' => $member->id, 'telegram_group_id' => $group->id]);
    $analysis = TelegramGroupAnalysis::factory()->done('Secret summary')->create(['telegram_group_id' => $group->id]);
    $thread = TelegramGroupThread::factory()->create(['telegram_group_id' => $group->id, 'client_member_id' => $member->id]);

    // Without a recent confirmation nothing happens.
    expect(fn () => app(ForgetGroupMember::class)->handle($admin, $group, $member->id))->toThrow(ValidationException::class)
        ->and(TelegramGroupMessage::query()->count())->toBe(2);

    confirmAdminPassword(secondsAgo: 3600);

    expect(fn () => app(ForgetGroupMember::class)->handle($admin, $group, $member->id))->toThrow(ValidationException::class);

    confirmAdminPassword();
    app(ForgetGroupMember::class)->handle($admin, $group, $member->id);

    expect(TelegramGroupMessage::query()->count())->toBe(0)
        ->and($member->fresh()->phone)->toBeNull()
        ->and($analysis->fresh()->summary)->toBeNull()
        ->and($thread->fresh()->summary)->toBeNull();

    // A non-Admin never gets there, password or not.
    expect(fn () => app(ForgetGroupMember::class)->handle(staffUser(Role::SalesManager), $group, $member->id))->toThrow(AuthorizationException::class);
});

it('Track without the privacy tick is refused by the action itself and changes nothing', function () {
    Queue::fake();
    $group = TelegramGroup::factory()->untracked()->create(['title' => 'Stub']);
    $sales = staffUser(Role::SalesManager);

    expect(fn () => app(TrackGroup::class)->handle(staffUser(Role::Admin), $group, $sales->id, null, false))->toThrow(ValidationException::class)
        ->and(fn () => app(TrackGroup::class)->handle($sales, $group, $sales->id, null, true))->toThrow(AuthorizationException::class)
        ->and($group->fresh()->status)->toBe(GroupStatus::Untracked)
        ->and($group->fresh()->assignee_id)->toBeNull()
        ->and(SettingsAudit::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('refuses to track an already tracked group and a tampered group id in the dialog', function () {
    Queue::fake();
    $admin = staffUser(Role::Admin);
    $tracked = TelegramGroup::factory()->tracked(staffUser(Role::SalesManager))->create();

    expect(fn () => app(TrackGroup::class)->handle($admin, $tracked, staffUser(Role::ServiceEngineer)->id, null, true))->toThrow(ValidationException::class)
        ->and($tracked->fresh()->assignee_id)->not->toBeNull();

    Livewire::actingAs($admin)->test(Index::class)->call('openTrack', 999999)->assertNotFound();
});

it('treats the group id as locked', function () {
    $group = TelegramGroup::factory()->tracked()->create();
    $other = TelegramGroup::factory()->tracked()->create();

    expect(fn () => Livewire::actingAs(staffUser(Role::Admin))->test(Show::class, ['group' => $group])->set('groupId', $other->id))->toThrow(Exception::class);
    expect(fn () => Livewire::actingAs(staffUser(Role::Admin))->test(Index::class)->set('trackingId', $other->id))->toThrow(Exception::class);
});

it('never puts the transcript or a phone of another group on a page the user may open', function () {
    $sales = staffUser(Role::SalesManager);
    $mine = TelegramGroup::factory()->tracked($sales)->create();
    $other = TelegramGroup::factory()->tracked()->create();
    $foreign = TelegramGroupMember::factory()->create(['telegram_group_id' => $other->id, 'phone' => '+998907776655']);
    TelegramGroupMessage::factory()->create(['member_id' => $foreign->id, 'telegram_group_id' => $other->id, 'text' => 'foreign secret text']);

    $html = Livewire::actingAs($sales)->test(Show::class, ['group' => $mine])->html();

    expect($html)->not->toContain('foreign secret text')->not->toContain('+998907776655');
});

/*
| Plan 44 T5 abuse cases (second batch): Forget reach, snapshot leaks,
| tampered ticks, role "unknown", the Track/Ignore tabs, search wildcards
| and escaping.
*/

it('Forget of member A also nulls summary, next step, draft and topic of the thread of client B (documented group-wide reach)', function () {
    $admin = staffUser(Role::Admin);
    $group = TelegramGroup::factory()->tracked()->create();
    $a = TelegramGroupMember::factory()->create(['telegram_group_id' => $group->id]);
    $b = TelegramGroupMember::factory()->create(['telegram_group_id' => $group->id]);
    $thread = TelegramGroupThread::factory()->create(['telegram_group_id' => $group->id, 'client_member_id' => $b->id, 'draft' => 'Draft text']);

    confirmAdminPassword();
    app(ForgetGroupMember::class)->handle($admin, $group, $a->id);

    $thread->refresh();

    expect($thread->summary)->toBeNull()
        ->and($thread->next_step)->toBeNull()
        ->and($thread->draft)->toBeNull()
        ->and($thread->topic)->toBeNull();
});

it('Forget with a member id from another group is refused and nothing changes in either group', function () {
    $admin = staffUser(Role::Admin);
    $group = TelegramGroup::factory()->tracked()->create();
    $other = TelegramGroup::factory()->tracked()->create();
    $own = TelegramGroupMember::factory()->create(['telegram_group_id' => $group->id, 'phone' => '+998901110001']);
    $foreign = TelegramGroupMember::factory()->create(['telegram_group_id' => $other->id, 'phone' => '+998901110002']);
    TelegramGroupMessage::factory()->create(['member_id' => $own->id, 'telegram_group_id' => $group->id]);
    TelegramGroupMessage::factory()->create(['member_id' => $foreign->id, 'telegram_group_id' => $other->id]);
    $thread = TelegramGroupThread::factory()->create(['telegram_group_id' => $other->id, 'client_member_id' => $foreign->id]);
    $audits = SettingsAudit::query()->count();

    confirmAdminPassword();

    Livewire::actingAs($admin)->test(Show::class, ['group' => $group])
        ->call('forget', $foreign->id)
        ->assertHasErrors('members');

    expect(TelegramGroupMessage::query()->where('telegram_group_id', $group->id)->count())->toBe(1)
        ->and(TelegramGroupMessage::query()->where('telegram_group_id', $other->id)->count())->toBe(1)
        ->and($foreign->fresh()->phone)->toBe('+998901110002')
        ->and($own->fresh()->phone)->toBe('+998901110001')
        ->and($thread->fresh()->summary)->not->toBeNull()
        ->and(SettingsAudit::query()->count())->toBe($audits);
});

it('keeps the phone out of the Livewire snapshot for the assignee and for an Admin, and shows it only in the HTML', function (Role $role) {
    $user = staffUser($role);
    $group = TelegramGroup::factory()->tracked($role === Role::Admin ? null : $user)->create();
    TelegramGroupMember::factory()->create(['telegram_group_id' => $group->id, 'phone' => '+998907771234']);

    $component = Livewire::actingAs($user)->test(Show::class, ['group' => $group]);

    expect(json_encode($component->snapshot))->not->toContain('998907771234')
        ->and($component->html())->toContain('+998907771234');

    $page = $this->actingAs($user)->get(route('admin.office.groups.show', $group))->assertOk()->getContent();
    preg_match_all('/wire:snapshot="([^"]*)"/', $page, $matches);

    expect($matches[1])->not->toBeEmpty();
    foreach ($matches[1] as $snapshot) {
        expect(html_entity_decode($snapshot))->not->toContain('998907771234');
    }
})->with([Role::Admin, Role::SalesManager]);

it('Forget through the component without a recent password redirects to the confirm page and deletes nothing', function () {
    $admin = staffUser(Role::Admin);
    $group = TelegramGroup::factory()->tracked()->create();
    $member = TelegramGroupMember::factory()->create(['telegram_group_id' => $group->id, 'phone' => '+998901112299']);
    TelegramGroupMessage::factory()->create(['member_id' => $member->id, 'telegram_group_id' => $group->id]);

    Livewire::actingAs($admin)->test(Show::class, ['group' => $group])
        ->call('forget', $member->id)
        ->assertRedirect();

    expect(TelegramGroupMessage::query()->count())->toBe(1)
        ->and($member->fresh()->phone)->toBe('+998901112299')
        ->and(SettingsAudit::query()->count())->toBe(0);
});

it('refuses a tampered selected through the component and writes nothing', function (array $selected) {
    $group = TelegramGroup::factory()->tracked()->create();
    $member = TelegramGroupMember::factory()->create(['telegram_group_id' => $group->id]);

    Livewire::actingAs(staffUser(Role::Admin))->test(Show::class, ['group' => $group])
        ->set('selected', $selected)
        ->set('bulkRole', 'client')
        ->call('setRole')
        ->assertHasErrors('members');

    expect($member->fresh()->role)->toBe(GroupMemberRole::Unknown)
        ->and(SettingsAudit::query()->count())->toBe(0);
})->with([
    'nested arrays' => [[[1], [[2]]]],
    'non-numeric strings' => [['abc', '1; DROP TABLE x']],
    'negative id' => [['-5']],
    'empty selection' => [[]],
]);

it('bulk role unknown leaves the party of the bot user unchanged', function () {
    $group = TelegramGroup::factory()->tracked()->create();
    $member = TelegramGroupMember::factory()->create(['telegram_group_id' => $group->id]);
    $botUser = TelegramUser::factory()->create(['telegram_id' => $member->telegram_id, 'party' => TelegramParty::Client]);

    Livewire::actingAs(staffUser(Role::Admin))->test(Show::class, ['group' => $group])
        ->set('selected', [(string) $member->id])
        ->set('bulkRole', 'unknown')
        ->call('setRole')
        ->assertHasNoErrors();

    expect($botUser->fresh()->party)->toBe(TelegramParty::Client)
        ->and(SettingsAudit::query()->where('key', 'like', 'office.telegram_user.%')->count())->toBe(0);
});

it('Track works from the Ignored tab and Ignore on a tracked group is refused', function () {
    Queue::fake();
    $admin = staffUser(Role::Admin);
    $assignee = staffUser(Role::SalesManager);
    $ignored = TelegramGroup::factory()->ignored()->create();
    $tracked = TelegramGroup::factory()->tracked()->create();

    Livewire::actingAs($admin)->test(Index::class)
        ->set('tab', 'ignored')
        ->call('openTrack', $ignored->id)
        ->set('assigneeId', $assignee->id)
        ->set('privacyNote', true)
        ->call('track')
        ->assertHasNoErrors();

    expect($ignored->fresh()->status)->toBe(GroupStatus::Tracked)
        ->and($ignored->fresh()->assignee_id)->toBe($assignee->id);

    expect(fn () => Livewire::actingAs($admin)->test(Index::class)->call('ignore', $tracked->id)->assertHasErrors('group'))->not->toThrow(Exception::class);

    expect($tracked->fresh()->status)->toBe(GroupStatus::Tracked);
});

it('a customer search with percent or underscore does not match every customer', function (string $term) {
    Customer::factory()->count(3)->create();

    expect(CustomerCandidates::search($term))->toBe([]);
})->with(['%%', '__', '%_']);

it('renders a script payload in a member name and the transcript escaped', function () {
    $payload = '<script>alert(1)</script>';
    $group = TelegramGroup::factory()->tracked()->create();
    $member = TelegramGroupMember::factory()->create(['telegram_group_id' => $group->id, 'first_name' => $payload, 'username' => $payload]);
    TelegramGroupMessage::factory()->create(['member_id' => $member->id, 'telegram_group_id' => $group->id, 'text' => $payload]);

    $html = Livewire::actingAs(staffUser(Role::Admin))->test(Show::class, ['group' => $group])->html();

    expect($html)->not->toContain($payload)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
});
