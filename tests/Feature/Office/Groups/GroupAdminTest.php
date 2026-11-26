<?php

use App\Actions\Office\ForgetGroupMember;
use App\Actions\Office\SetGroupMemberRoles;
use App\Enums\GroupMemberRole;
use App\Enums\GroupStatus;
use App\Enums\Role;
use App\Enums\TelegramParty;
use App\Jobs\FetchGroupAdministrators;
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
use App\Models\User;
use App\Support\Admin\Menu;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Office\CustomerCandidates;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| Plan 44 T5: Admin → Virtual office → Telegram groups. Policy and scope
| (OG-7), Track with the privacy tick (OG-4), Ignore (OG-12), bulk roles
| and the party write-back, Forget (OG-8), the phone rule.
*/

function groupWithMembers(int $count = 2, ?User $assignee = null): array
{
    $group = TelegramGroup::factory()->tracked($assignee)->create();
    $members = TelegramGroupMember::factory()->count($count)->create(['telegram_group_id' => $group->id]);

    return [$group, $members];
}

it('lets each role open the list and decides each group by the record rule (403 otherwise)', function () {
    $sales = staffUser(Role::SalesManager);
    $engineer = staffUser(Role::ServiceEngineer);
    $mine = TelegramGroup::factory()->tracked($sales)->create();
    $theirs = TelegramGroup::factory()->tracked($engineer)->create();
    $untracked = TelegramGroup::factory()->untracked()->create(['assignee_id' => $sales->id]);
    $ignored = TelegramGroup::factory()->ignored()->create(['assignee_id' => $sales->id]);

    $this->actingAs(staffUser(Role::ContentManager))->get(route('admin.office.groups'))->assertForbidden();

    foreach ([staffUser(Role::Admin), $sales, $engineer] as $user) {
        $this->actingAs($user)->get(route('admin.office.groups'))->assertOk();
    }

    foreach ([$mine, $theirs, $untracked, $ignored] as $group) {
        $this->actingAs(staffUser(Role::Admin))->get(route('admin.office.groups.show', $group))->assertOk();
        $this->actingAs(staffUser(Role::ContentManager))->get(route('admin.office.groups.show', $group))->assertForbidden();
    }

    $this->actingAs($sales)->get(route('admin.office.groups.show', $mine))->assertOk();
    $this->actingAs($engineer)->get(route('admin.office.groups.show', $theirs))->assertOk();
    $this->actingAs($sales)->get(route('admin.office.groups.show', $theirs))->assertForbidden();
    $this->actingAs($engineer)->get(route('admin.office.groups.show', $mine))->assertForbidden();
    // Untracked and ignored groups are Admin only, even with an assignee set.
    $this->actingAs($sales)->get(route('admin.office.groups.show', $untracked))->assertForbidden();
    $this->actingAs($sales)->get(route('admin.office.groups.show', $ignored))->assertForbidden();
});

it('scopes the list to tracked assigned groups for staff and shows every group to the Admin', function () {
    $sales = staffUser(Role::SalesManager);
    TelegramGroup::factory()->tracked($sales)->create(['title' => 'Mine tracked']);
    TelegramGroup::factory()->tracked(staffUser(Role::ServiceEngineer))->create(['title' => 'Theirs tracked']);
    TelegramGroup::factory()->untracked()->create(['title' => 'Stub group']);

    expect(TelegramGroup::query()->visibleTo($sales)->pluck('title')->all())->toBe(['Mine tracked'])
        ->and(TelegramGroup::query()->visibleTo(staffUser(Role::Admin))->count())->toBe(3)
        ->and(TelegramGroup::query()->visibleTo(staffUser(Role::ContentManager))->count())->toBe(0);

    Livewire::actingAs($sales)->test(Index::class, ['tab' => 'untracked'])
        ->assertSee('Mine tracked')->assertDontSee('Theirs tracked')->assertDontSee('Stub group')
        ->assertDontSeeHtml('data-tab="untracked"');

    Livewire::actingAs(staffUser(Role::Admin))->test(Index::class)
        ->assertSee('Stub group')->assertDontSee('Mine tracked')
        ->call('$set', 'tab', 'tracked')->assertSee('Mine tracked')->assertSee('Theirs tracked');
});

it('puts the groups entry in the sidebar for staff and the Admin but not the content manager', function () {
    $keys = fn (User $user): array => collect(Menu::for($user))->flatMap(fn ($group) => array_map(fn ($item) => $item->key, $group->items))->all();

    expect($keys(staffUser(Role::Admin)))->toContain('office-groups')
        ->and($keys(staffUser(Role::SalesManager)))->toContain('office-groups')
        ->and($keys(staffUser(Role::ContentManager)))->not->toContain('office-groups');
});

it('shows the bot admin badge or the cannot-read warning', function () {
    TelegramGroup::factory()->botAdmin()->create(['title' => 'Admin bot group']);
    TelegramGroup::factory()->create(['title' => 'Limited bot group']);

    Livewire::actingAs(staffUser(Role::Admin))->test(Index::class)
        ->assertSeeHtml('data-bot-admin')->assertSeeHtml('data-bot-limited');
});

it('tracks a group with an assignee, a customer and the privacy tick, audits it and queues the admin fetch', function () {
    Queue::fake();
    $admin = staffUser(Role::Admin);
    $sales = staffUser(Role::SalesManager);
    $customer = Customer::factory()->create();
    $group = TelegramGroup::factory()->untracked()->create();

    Livewire::actingAs($admin)->test(Index::class)
        ->call('openTrack', $group->id)
        ->set('assigneeId', $sales->id)
        ->set('customerId', $customer->id)
        ->set('privacyNote', true)
        ->call('track')
        ->assertHasNoErrors();

    $group->refresh();

    expect($group->status)->toBe(GroupStatus::Tracked)
        ->and($group->assignee_id)->toBe($sales->id)
        ->and($group->customer_id)->toBe($customer->id)
        ->and($group->tracked_by_id)->toBe($admin->id)
        ->and($group->tracked_at)->not->toBeNull();

    $audit = SettingsAudit::query()->where('key', 'office.group.'.$group->id.'.track')->sole();

    expect($audit->new_text)->toBe('privacy_note_posted=1')->and($audit->user_id)->toBe($admin->id);

    Queue::assertPushed(FetchGroupAdministrators::class, fn ($job) => $job->groupId === $group->id);
});

it('refuses to track without the privacy tick, without an assignee or with a content manager as assignee', function () {
    Queue::fake();
    $group = TelegramGroup::factory()->untracked()->create();
    $component = Livewire::actingAs(staffUser(Role::Admin))->test(Index::class)->call('openTrack', $group->id);

    $component->set('assigneeId', staffUser(Role::SalesManager)->id)->call('track')->assertHasErrors('privacyNote');
    $component->set('privacyNote', true)->set('assigneeId', null)->call('track')->assertHasErrors('assigneeId');
    $component->set('assigneeId', staffUser(Role::ContentManager)->id)->call('track')->assertHasErrors('assignee');

    expect($group->fresh()->status)->toBe(GroupStatus::Untracked)
        ->and(SettingsAudit::query()->where('key', 'like', 'office.group.%')->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('lets only an Admin track or ignore', function () {
    $sales = staffUser(Role::SalesManager);
    $group = TelegramGroup::factory()->tracked($sales)->create();
    $stub = TelegramGroup::factory()->untracked()->create();

    Livewire::actingAs($sales)->test(Index::class)->call('openTrack', $stub->id)->assertForbidden();
    Livewire::actingAs($sales)->test(Index::class)->call('ignore', $stub->id)->assertForbidden();

    expect($stub->fresh()->status)->toBe(GroupStatus::Untracked)->and($group->fresh()->isTracked())->toBeTrue();
});

it('ignores a stub, nulls its title and refuses to ignore a tracked group', function () {
    $stub = TelegramGroup::factory()->untracked()->create(['title' => 'Secret Title', 'username' => 'secretgroup']);
    $tracked = TelegramGroup::factory()->tracked()->create(['title' => 'Kept']);
    $component = Livewire::actingAs(staffUser(Role::Admin))->test(Index::class);

    $component->call('ignore', $stub->id);
    $component->call('ignore', $tracked->id)->assertHasErrors('group');

    expect($stub->fresh()->status)->toBe(GroupStatus::Ignored)
        ->and($stub->fresh()->title)->toBeNull()->and($stub->fresh()->username)->toBeNull()
        ->and($tracked->fresh()->status)->toBe(GroupStatus::Tracked)->and($tracked->fresh()->title)->toBe('Kept');
});

it('searches customers with escaped LIKE wildcards, 2+ characters and at most 20 results', function () {
    Customer::factory()->create(['name' => 'Alpha Market']);
    Customer::factory()->create(['name' => 'Beta 100% Store']);
    Customer::factory()->count(25)->create(['name' => 'Gamma Trade']);

    expect(CustomerCandidates::search('a'))->toBe([])
        ->and(CustomerCandidates::search('%%'))->toBe([])
        ->and(CustomerCandidates::search('_'))->toBe([])
        ->and(collect(CustomerCandidates::search('0% s'))->pluck('name')->all())->toBe(['Beta 100% Store'])
        ->and(CustomerCandidates::search('gamma'))->toHaveCount(20)
        ->and(array_keys(CustomerCandidates::search('alpha')[0]))->toBe(['id', 'name']);
});

it('sets roles in bulk, writes the party back and audits every global write', function () {
    $admin = staffUser(Role::Admin);
    $sales = staffUser(Role::SalesManager);
    [$group, $members] = groupWithMembers(2);
    $botUser = TelegramUser::factory()->create(['telegram_id' => $members[0]->telegram_id, 'party' => null]);
    $customer = Customer::factory()->create();

    $count = app(SetGroupMemberRoles::class)->handle($admin, $group, $members->pluck('id')->all(), GroupMemberRole::Client, null, $customer->id, '127.0.0.1');

    expect($count)->toBe(2)
        ->and($members[0]->fresh()->role)->toBe(GroupMemberRole::Client)
        ->and($members[0]->fresh()->customer_id)->toBe($customer->id)
        ->and($members[0]->fresh()->role_set_by_id)->toBe($admin->id)
        ->and($members[0]->fresh()->telegram_user_id)->toBe($botUser->id)
        ->and($botUser->fresh()->party)->toBe(TelegramParty::Client)
        ->and(SettingsAudit::query()->where('key', 'office.telegram_user.'.$botUser->id.'.party')->count())->toBe(1);

    app(SetGroupMemberRoles::class)->handle($admin, $group, [$members[0]->id], GroupMemberRole::Employee, $sales->id);

    expect($members[0]->fresh()->role)->toBe(GroupMemberRole::Employee)
        ->and($members[0]->fresh()->staff_user_id)->toBe($sales->id)
        ->and($members[0]->fresh()->customer_id)->toBeNull()
        ->and($botUser->fresh()->party)->toBe(TelegramParty::Employee)
        ->and($botUser->fresh()->staff_user_id)->toBe($sales->id)
        ->and(SettingsAudit::query()->where('key', 'office.telegram_user.'.$botUser->id.'.party')->count())->toBe(2);

    // Unknown never clears a global party.
    app(SetGroupMemberRoles::class)->handle($admin, $group, [$members[0]->id], GroupMemberRole::Unknown);

    expect($botUser->fresh()->party)->toBe(TelegramParty::Employee);
});

it('refuses the bot role, a bot in the selection, a content manager as staff and a staff account for several members', function () {
    $admin = staffUser(Role::Admin);
    [$group, $members] = groupWithMembers(2);
    $bot = TelegramGroupMember::factory()->bot()->create(['telegram_group_id' => $group->id]);
    $set = fn (array $ids, GroupMemberRole $role, ?int $staff = null) => fn () => app(SetGroupMemberRoles::class)->handle($admin, $group, $ids, $role, $staff);

    expect($set([$members[0]->id], GroupMemberRole::Bot))->toThrow(ValidationException::class)
        ->and($set([$members[0]->id, $bot->id], GroupMemberRole::Client))->toThrow(ValidationException::class)
        ->and($set([$members[0]->id], GroupMemberRole::Employee, staffUser(Role::ContentManager)->id))->toThrow(ValidationException::class)
        ->and($set($members->pluck('id')->all(), GroupMemberRole::Employee, staffUser(Role::SalesManager)->id))->toThrow(ValidationException::class)
        ->and($set([], GroupMemberRole::Client))->toThrow(ValidationException::class)
        ->and($members[0]->fresh()->role)->toBe(GroupMemberRole::Unknown)
        ->and($bot->fresh()->role)->toBe(GroupMemberRole::Bot);
});

it('sets roles from the member table through the component and hides the bot checkbox', function () {
    $admin = staffUser(Role::Admin);
    [$group, $members] = groupWithMembers(2);
    $bot = TelegramGroupMember::factory()->bot()->create(['telegram_group_id' => $group->id]);

    Livewire::actingAs($admin)->test(Show::class, ['group' => $group])
        ->assertSeeHtml('data-member="'.$bot->id.'"')
        ->assertDontSeeHtml('value="'.$bot->id.'"')
        ->set('selected', [(string) $members[0]->id])
        ->set('bulkRole', 'client')
        ->call('setRole')
        ->assertHasNoErrors()
        ->assertSet('selected', []);

    expect($members[0]->fresh()->role)->toBe(GroupMemberRole::Client)->and($members[1]->fresh()->role)->toBe(GroupMemberRole::Unknown);
});

it('shows the phone only to the Admin and the assignee, and does not even select it otherwise', function () {
    $sales = staffUser(Role::SalesManager);
    [$group] = groupWithMembers(1, $sales);
    TelegramGroupMember::query()->where('telegram_group_id', $group->id)->update(['phone' => '+998901112233']);

    Livewire::actingAs(staffUser(Role::Admin))->test(Show::class, ['group' => $group])->assertSee('+998901112233');
    Livewire::actingAs($sales)->test(Show::class, ['group' => $group])->assertSee('+998901112233');

    // A viewer with office.chats-all only (neither office.mark nor the assignee) sees the group but not the phone.
    $viewer = User::factory()->withRole(Role::SalesManager)->create();
    $viewer->givePermissionTo('office.chats-all');

    Livewire::actingAs($viewer)->test(Show::class, ['group' => $group])
        ->assertOk()->assertDontSee('+998901112233')->assertDontSeeHtml('data-member-phone');
});

it('renders the transcript decrypted, escaped and 50 per page, newest page first', function () {
    $admin = staffUser(Role::Admin);
    [$group, $members] = groupWithMembers(1);
    TelegramGroupMessage::factory()->count(60)->create(['member_id' => $members[0]->id, 'telegram_group_id' => $group->id, 'text' => 'plain old message']);
    TelegramGroupMessage::factory()->create(['member_id' => $members[0]->id, 'telegram_group_id' => $group->id, 'text' => '<script>alert(1)</script> newest', 'sent_at' => now()]);

    $html = Livewire::actingAs($admin)->test(Show::class, ['group' => $group])->html();

    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt; newest')->not->toContain('<script>alert(1)')
        ->and(substr_count($html, 'wire:key="gm-'))->toBe(50);
});

it('shows the bot warning when the bot is not an admin', function () {
    [$group] = groupWithMembers(0);
    $admin = staffUser(Role::Admin);

    Livewire::actingAs($admin)->test(Show::class, ['group' => $group])->assertSeeHtml('data-bot-warning');
    $group->forceFill(['bot_is_admin' => true])->save();
    Livewire::actingAs($admin)->test(Show::class, ['group' => $group])->assertDontSeeHtml('data-bot-warning');
});

it('refreshes the admins through the throttled dispatch', function () {
    Queue::fake();
    Cache::flush();
    [$group] = groupWithMembers(0);
    $component = Livewire::actingAs(staffUser(Role::Admin))->test(Show::class, ['group' => $group]);

    $component->call('refreshAdmins')->call('refreshAdmins');

    Queue::assertPushed(FetchGroupAdministrators::class, 1);
});

it('sends the Admin to the password screen before Forget and forgets with a recent confirmation', function () {
    $admin = staffUser(Role::Admin);
    [$group, $members] = groupWithMembers(1);
    TelegramGroupMessage::factory()->create(['member_id' => $members[0]->id, 'telegram_group_id' => $group->id]);

    Livewire::actingAs($admin)->test(Show::class, ['group' => $group])
        ->call('forget', $members[0]->id)
        ->assertRedirect(PasswordConfirmation::url(route('admin.office.groups.show', $group)));

    expect(TelegramGroupMessage::query()->count())->toBe(1);

    confirmAdminPassword();

    Livewire::actingAs($admin)->test(Show::class, ['group' => $group])->call('forget', $members[0]->id)->assertHasNoErrors();

    expect(TelegramGroupMessage::query()->count())->toBe(0);
});

it('forgets a member: messages deleted, phone nulled, summaries and drafts nulled, counts-only audit', function () {
    $admin = staffUser(Role::Admin);
    [$group, $members] = groupWithMembers(2);
    $members[0]->forceFill(['phone' => '+998901112233', 'message_count' => 3])->save();
    TelegramGroupMessage::factory()->count(3)->create(['member_id' => $members[0]->id, 'telegram_group_id' => $group->id]);
    $kept = TelegramGroupMessage::factory()->create(['member_id' => $members[1]->id, 'telegram_group_id' => $group->id]);
    $analysis = TelegramGroupAnalysis::factory()->done('Secret summary')->create(['telegram_group_id' => $group->id, 'draft_reply' => 'Secret draft']);
    $mine = TelegramGroupThread::factory()->create(['telegram_group_id' => $group->id, 'client_member_id' => $members[0]->id, 'draft' => 'Secret thread draft']);
    $other = TelegramGroupThread::factory()->create(['telegram_group_id' => $group->id, 'client_member_id' => $members[1]->id, 'draft' => 'Other draft']);
    $whole = TelegramGroupThread::factory()->create(['telegram_group_id' => $group->id, 'client_member_id' => null]);
    confirmAdminPassword();

    $counts = app(ForgetGroupMember::class)->handle($admin, $group, $members[0]->id, '127.0.0.1');

    expect($counts)->toBe(['messages' => 3, 'analyses' => 1, 'threads' => 3])
        ->and($members[0]->messages()->count())->toBe(0)
        ->and($kept->fresh())->not->toBeNull()
        ->and($members[0]->fresh()->phone)->toBeNull()
        ->and($analysis->fresh()->summary)->toBeNull()->and($analysis->fresh()->next_step)->toBeNull()->and($analysis->fresh()->draft_reply)->toBeNull()
        ->and($mine->fresh()->summary)->toBeNull()->and($mine->fresh()->draft)->toBeNull()
        ->and($whole->fresh()->summary)->toBeNull()
        ->and($other->fresh()->summary)->toBeNull()->and($other->fresh()->draft)->toBeNull();

    $audit = SettingsAudit::query()->where('key', 'office.group.'.$group->id.'.member_forgotten')->sole();

    expect($audit->new_text)->toBe('messages=3 analyses=1 threads=3')
        ->and($audit->new_text.$audit->key)->not->toContain($members[0]->first_name);
});

it('shows the empty threads panel', function () {
    [$group] = groupWithMembers(0);

    Livewire::actingAs(staffUser(Role::Admin))->test(Show::class, ['group' => $group])->assertSeeHtml('data-threads-panel')->assertSee(__('office.groups.show.no_threads'));
});

it('clears the ticked members when the members page changes and resets the dependent fields on a role change', function () {
    [$group] = groupWithMembers(30);

    Livewire::actingAs(staffUser(Role::Admin))->test(Show::class, ['group' => $group])
        ->set('selected', ['1', '2'])
        ->call('nextPage', 'members')
        ->assertSet('selected', [])
        ->set('bulkRole', 'client')->set('bulkCustomerId', 5)->set('customerSearch', 'abc')
        ->set('bulkRole', 'employee')
        ->assertSet('bulkCustomerId', null)->assertSet('customerSearch', '')->assertSet('bulkStaffId', null);
});

it('goes back a page when Forget empties the current members page', function () {
    [$group, $members] = groupWithMembers(26);
    $last = $members->sortBy('message_count')->last();
    TelegramGroupMember::query()->where('telegram_group_id', $group->id)->update(['message_count' => 0]);
    $last = TelegramGroupMember::query()->where('telegram_group_id', $group->id)->orderBy('id')->get()->last();
    confirmAdminPassword();

    Livewire::actingAs(staffUser(Role::Admin))->test(Show::class, ['group' => $group])
        ->call('gotoPage', 2, 'members')
        ->call('forget', $last->id)
        ->assertHasNoErrors();

    expect(TelegramGroupMember::query()->where('telegram_group_id', $group->id)->count())->toBe(26);
});

it('says so instead of "throttled" when refreshing an untracked group is attempted, and no-ops Track without a dialog', function () {
    Queue::fake();
    $group = TelegramGroup::factory()->untracked()->create();
    $admin = staffUser(Role::Admin);

    Livewire::actingAs($admin)->test(Show::class, ['group' => $group])->call('refreshAdmins')->assertDispatched('toast');
    Livewire::actingAs($admin)->test(Index::class)->call('track')->assertHasNoErrors();

    Queue::assertNothingPushed();
});

it('nulls the topic of every thread of the group on Forget', function () {
    [$group, $members] = groupWithMembers(2);
    $thread = TelegramGroupThread::factory()->create(['telegram_group_id' => $group->id, 'client_member_id' => $members[1]->id]);
    confirmAdminPassword();

    app(ForgetGroupMember::class)->handle(staffUser(Role::Admin), $group, $members[0]->id);

    expect($thread->fresh()->summary)->toBeNull()->and($thread->fresh()->topic)->toBeNull();
});

it('renders an @username once and shows an untitled group by id', function () {
    $group = TelegramGroup::factory()->tracked()->create(['title' => null]);
    $member = TelegramGroupMember::factory()->create(['telegram_group_id' => $group->id, 'username' => 'abc_user']);
    TelegramGroupMessage::factory()->create(['member_id' => $member->id, 'telegram_group_id' => $group->id]);

    Livewire::actingAs(staffUser(Role::Admin))->test(Show::class, ['group' => $group])
        ->assertSee('(@abc_user)', false)->assertDontSee('@{{')->assertSee(__('office.groups.untitled', ['id' => $group->id]));
});

it('runs a fixed number of queries for the index, the member table and the transcript', function () {
    $admin = staffUser(Role::Admin);
    [$group, $members] = groupWithMembers(5);
    TelegramGroupMessage::factory()->count(5)->create(['member_id' => $members[0]->id, 'telegram_group_id' => $group->id]);
    TelegramGroup::factory()->tracked()->count(5)->create();
    $this->actingAs($admin)->get(route('admin.office.groups').'?tab=tracked');
    // Warm the cached office settings (the threads panel reads the analysis gates).
    $this->actingAs($admin)->get(route('admin.office.groups.show', $group));
    $baseIndex = countQueries(fn () => $this->actingAs($admin)->get(route('admin.office.groups').'?tab=tracked'));
    $baseShow = countQueries(fn () => $this->actingAs($admin)->get(route('admin.office.groups.show', $group)));

    TelegramGroup::factory()->tracked()->count(10)->create();
    TelegramGroupMember::factory()->count(10)->create(['telegram_group_id' => $group->id]);
    TelegramGroupMessage::factory()->count(20)->create(['member_id' => $members[1]->id, 'telegram_group_id' => $group->id]);

    expect(countQueries(fn () => $this->actingAs($admin)->get(route('admin.office.groups').'?tab=tracked')))->toBe($baseIndex)
        ->and(countQueries(fn () => $this->actingAs($admin)->get(route('admin.office.groups.show', $group))))->toBe($baseShow);
});
