<?php

use App\Actions\Office\AssignBusinessChat;
use App\Actions\Office\MarkBusinessChat;
use App\Actions\Office\RequestBusinessChatAnalysis;
use App\Enums\BusinessChatKind;
use App\Enums\Role;
use App\Enums\TelegramParty;
use App\Livewire\Admin\Office\Chats\Index;
use App\Livewire\Admin\Office\Chats\Show;
use App\Livewire\Admin\Office\Unmarked;
use App\Models\BusinessChat;
use App\Models\BusinessChatAnalysis;
use App\Models\Lead;
use App\Models\SettingsAudit;
use App\Models\TelegramUser;
use App\Models\User;
use App\Support\Admin\Menu;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/../../Helpers/Office.php';

/*
| Plan 41 §7–§8: who sees and does what (VO-9). Admin every client chat;
| staff only chats assigned to them; unassigned / unmarked / marking,
| Education and Settings Admin only.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function officeUser(Role $role): User
{
    return User::factory()->withRole($role)->create();
}

it('lets each role open only the screens it may (403 otherwise)', function (string $route, array $allowed) {
    foreach ([Role::Admin, Role::SalesManager, Role::ServiceEngineer, Role::ContentManager] as $role) {
        $response = $this->actingAs(officeUser($role))->get(route($route));

        in_array($role, $allowed, true) ? $response->assertOk() : $response->assertForbidden();
    }
})->with([
    'chats' => ['admin.office.chats.index', [Role::Admin, Role::SalesManager, Role::ServiceEngineer]],
    'unmarked' => ['admin.office.unmarked', [Role::Admin]],
    'education' => ['admin.office.education', [Role::Admin]],
    'settings' => ['admin.office.settings', [Role::Admin]],
]);

it('lists only assigned client chats for staff and every client chat for the Admin', function () {
    $sales = officeUser(Role::SalesManager);
    $mine = BusinessChat::factory()->client($sales)->create(['first_name' => 'Mine']);
    BusinessChat::factory()->client(officeUser(Role::SalesManager))->create(['first_name' => 'Theirs']);
    BusinessChat::factory()->client()->create(['first_name' => 'Nobody']);
    BusinessChat::factory()->create(['first_name' => 'Stub']);

    Livewire::actingAs($sales)->test(Index::class)
        ->assertSee('Mine')->assertDontSee('Theirs')->assertDontSee('Nobody')->assertDontSee('Stub');

    Livewire::actingAs(officeUser(Role::Admin))->test(Index::class)
        ->assertSee('Mine')->assertSee('Theirs')->assertSee('Nobody')->assertDontSee('Stub');

    expect(BusinessChat::query()->visibleTo($sales)->pluck('id')->all())->toBe([$mine->id]);
});

it('refuses another user’s, an unassigned and an unmarked chat to staff (403)', function (string $case) {
    $sales = officeUser(Role::SalesManager);
    $chat = match ($case) {
        'other' => BusinessChat::factory()->client(officeUser(Role::SalesManager))->create(),
        'unassigned' => BusinessChat::factory()->client()->create(),
        'unmarked' => BusinessChat::factory()->create(['assignee_id' => $sales->id]),
        'employee' => BusinessChat::factory()->employee()->create(['assignee_id' => $sales->id]),
    };

    $this->actingAs($sales)->get(route('admin.office.chats.show', $chat))->assertForbidden();
})->with(['other', 'unassigned', 'unmarked', 'employee']);

it('opens an assigned chat for its assignee and every client chat for the Admin', function () {
    $engineer = officeUser(Role::ServiceEngineer);
    $chat = BusinessChat::factory()->client($engineer)->create();

    $this->actingAs($engineer)->get(route('admin.office.chats.show', $chat))->assertOk();
    $this->actingAs(officeUser(Role::Admin))->get(route('admin.office.chats.show', BusinessChat::factory()->client()->create()))->assertOk();
});

it('refuses analyse, assign and mark on a chat the user may not touch (tampered ids)', function () {
    officeSettings(['ai_enabled' => true]);
    config(['services.anthropic.key' => 'sk-ant-test-fake-key']);
    $sales = officeUser(Role::SalesManager);
    $chat = BusinessChat::factory()->client(officeUser(Role::SalesManager))->create();

    expect(fn () => app(RequestBusinessChatAnalysis::class)->handle($sales, $chat))->toThrow(AuthorizationException::class)
        ->and(fn () => app(AssignBusinessChat::class)->handle($sales, $chat, $sales->id))->toThrow(AuthorizationException::class)
        ->and(fn () => app(MarkBusinessChat::class)->handle($sales, $chat, BusinessChatKind::Ignored))->toThrow(AuthorizationException::class);
});

it('lets a sales manager hand an own chat to another office user, never to a content manager (VO-9)', function () {
    $sales = officeUser(Role::SalesManager);
    $colleague = officeUser(Role::ServiceEngineer);
    $chat = BusinessChat::factory()->client($sales)->create();

    expect(fn () => app(AssignBusinessChat::class)->handle($sales, $chat, officeUser(Role::ContentManager)->id))->toThrow(ValidationException::class)
        ->and(fn () => app(AssignBusinessChat::class)->handle($sales, $chat, null))->toThrow(AuthorizationException::class);

    app(AssignBusinessChat::class)->handle($sales, $chat, $colleague->id);

    expect($chat->fresh()->assignee_id)->toBe($colleague->id)
        ->and($chat->fresh()->isVisibleTo($sales))->toBeFalse();
});

it('marks a chat from the Unmarked screen; a client chat id is not found there', function () {
    $admin = officeUser(Role::Admin);
    $sales = officeUser(Role::SalesManager);
    $stub = BusinessChat::factory()->create();
    $client = BusinessChat::factory()->client()->create();

    Livewire::actingAs($admin)->test(Unmarked::class)
        ->set("assignees.{$stub->id}", (string) $sales->id)
        ->call('markClient', $stub->id)
        ->assertHasNoErrors();

    expect($stub->fresh()->kind)->toBe(BusinessChatKind::Client)
        ->and($stub->fresh()->assignee_id)->toBe($sales->id)
        ->and($stub->fresh()->marked_by_id)->toBe($admin->id);

    Livewire::actingAs($admin)->test(Unmarked::class)->call('ignore', $client->id)->assertNotFound();
});

it('needs a password to unmark a client chat, then deletes its messages and analyses but keeps the lead (VO-12)', function () {
    $admin = officeUser(Role::Admin);
    $lead = Lead::factory()->create();
    $chat = BusinessChat::factory()->client()->create();
    $chat->forceFill(['lead_id' => $lead->id])->save();
    $chat->messages()->create(['telegram_message_id' => 1, 'author' => 'client', 'kind' => 'text', 'text' => 'Hello']);
    BusinessChatAnalysis::factory()->done()->create(['business_chat_id' => $chat->id]);

    expect(fn () => app(MarkBusinessChat::class)->handle($admin, $chat, BusinessChatKind::Employee))->toThrow(ValidationException::class);

    confirmAdminPassword();
    app(MarkBusinessChat::class)->handle($admin, $chat, BusinessChatKind::Employee);

    expect($chat->messages()->count())->toBe(0)
        ->and($chat->analyses()->count())->toBe(0)
        ->and($lead->fresh())->not->toBeNull()
        ->and(SettingsAudit::query()->where('key', 'office.chat.'.$chat->id.'.kind')->count())->toBe(1);
});

it('links the bot user with the same Telegram id and sets the party', function () {
    $admin = officeUser(Role::Admin);
    $chat = BusinessChat::factory()->create(['telegram_chat_id' => OFFICE_CLIENT_ID]);
    $botUser = TelegramUser::factory()->create(['telegram_id' => OFFICE_CLIENT_ID]);

    app(MarkBusinessChat::class)->handle($admin, $chat, BusinessChatKind::Employee, staffUserId: $admin->id);

    expect($botUser->fresh()->party)->toBe(TelegramParty::Employee)
        ->and($botUser->fresh()->staff_user_id)->toBe($admin->id)
        ->and($chat->fresh()->telegram_user_id)->toBe($botUser->id);
});

it('shows the office menu by permission', function () {
    $keys = fn (User $user): array => collect(Menu::for($user))->firstWhere('key', 'office')?->items ? array_map(fn ($item) => $item->key, collect(Menu::for($user))->firstWhere('key', 'office')->items) : [];

    expect($keys(officeUser(Role::Admin)))->toBe(['office-chats', 'office-groups', 'office-unmarked', 'office-education', 'office-settings'])
        ->and($keys(officeUser(Role::SalesManager)))->toBe(['office-chats', 'office-groups'])
        ->and($keys(officeUser(Role::ContentManager)))->toBe([]);
});

it('never sends anything from the chat screen and offers copy only', function () {
    $admin = officeUser(Role::Admin);
    $chat = BusinessChat::factory()->client()->create();
    BusinessChatAnalysis::factory()->done()->create(['business_chat_id' => $chat->id, 'draft_reply' => 'Draft text']);

    Livewire::actingAs($admin)->test(Show::class, ['businessChat' => $chat])
        ->assertSee('Draft text')
        ->assertSee(__('office.show.draft_not_sent'));
});
