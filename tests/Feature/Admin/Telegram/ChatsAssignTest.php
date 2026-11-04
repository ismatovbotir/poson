<?php

use App\Actions\Telegram\AssignConversation;
use App\Actions\Telegram\ChangeConversationStatus;
use App\Actions\Telegram\InboxRefused;
use App\Actions\Telegram\StoreInboundMessage;
use App\Enums\ActivityType;
use App\Enums\ConversationStatus;
use App\Enums\Role;
use App\Livewire\Admin\Telegram\Chats\Index;
use App\Livewire\Admin\Telegram\Chats\Show;
use App\Models\TelegramUser;
use App\Models\User;
use App\Support\Telegram\Bot\UpdateParser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * Plan 21 A3: assign / take and close / reopen (TG-35, T21-17).
 */
afterEach(function () {
    TelegramUser::flushEventListeners();
});

function assignManager(): User
{
    return staffUser(Role::SalesManager);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function assignThread(array $attributes = [], ?User $manager = null): TelegramUser
{
    $factory = TelegramUser::factory()->registered();

    return ($manager ? $factory->assigned($manager) : $factory)->create($attributes);
}

it('refuses every direct call on another manager\'s thread with 403 (TG-35)', function (string $method, array $params) {
    $user = assignManager();
    $owner = assignManager();
    $chat = assignThread(manager: $owner);
    $view = Livewire::actingAs($owner)->test(Show::class, ['telegramUser' => $chat]);

    $chat->forceFill(['manager_id' => assignManager()->id])->save();

    $view->call($method, ...$params)->assertForbidden();

    expect(fn () => match ($method) {
        'assign' => app(AssignConversation::class)->assign($chat, $user, $user->id),
        'take' => app(AssignConversation::class)->take($chat, $user),
        'close' => app(ChangeConversationStatus::class)->close($chat, $user),
        'reopen' => app(ChangeConversationStatus::class)->reopen($chat, $user),
    })->toThrow(AuthorizationException::class);

    expect($chat->activities()->count())->toBe(0);
})->with([
    'assign' => ['assign', []],
    'take' => ['take', []],
    'close' => ['close', []],
    'reopen' => ['reopen', []],
]);

it('allows assign by role', function (Role $role, bool $allowed) {
    $actor = staffUser($role);
    $target = assignManager();
    $chat = assignThread();
    $call = fn () => app(AssignConversation::class)->assign($chat, $actor, $target->id);

    if ($allowed) {
        expect($call())->toBeTrue()->and($chat->fresh()->manager_id)->toBe($target->id);
    } else {
        expect($call)->toThrow(AuthorizationException::class);
        expect($chat->fresh()->manager_id)->toBeNull();
    }
})->with([
    'admin' => [Role::Admin, true],
    'sales manager' => [Role::SalesManager, true],
    'content manager' => [Role::ContentManager, false],
    'service engineer' => [Role::ServiceEngineer, false],
]);

it('refuses assign and take without telegram.reassign', function () {
    $user = assignManager();
    $user->roles->first()->revokePermissionTo('telegram.reassign');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user = $user->fresh();
    $chat = assignThread();

    expect(fn () => app(AssignConversation::class)->assign($chat, $user, $user->id))->toThrow(AuthorizationException::class);
    expect(fn () => app(AssignConversation::class)->take($chat, $user))->toThrow(AuthorizationException::class);

    Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])->assertDontSeeHtml('data-take')->assertDontSeeHtml('data-assign');
});

it('accepts only active users with telegram.inbox as targets (T21-17)', function (Closure $target) {
    $chat = assignThread();

    expect(fn () => app(AssignConversation::class)->assign($chat, staffUser(Role::Admin), $target()))
        ->toThrow(fn (InboxRefused $refused) => expect($refused->reason)->toBe('invalid_target'));

    expect($chat->fresh()->manager_id)->toBeNull()->and($chat->activities()->count())->toBe(0);
})->with([
    'inactive manager' => [fn () => User::factory()->withRole(Role::SalesManager)->create(['is_active' => false])->id],
    'content manager' => [fn () => User::factory()->withRole(Role::ContentManager)->create()->id],
    'service engineer' => [fn () => User::factory()->withRole(Role::ServiceEngineer)->create()->id],
    'no role' => [fn () => User::factory()->create()->id],
    'missing user' => [fn () => 999999],
]);

it('re-checks the target at action time, not from the list on the screen', function () {
    $admin = staffUser(Role::Admin);
    $target = assignManager();
    $chat = assignThread();
    $component = Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat])->set('assignTarget', $target->id);

    $target->forceFill(['is_active' => false])->save();

    $component->call('assign')->assertDispatched('toast', type: 'error');
    expect($chat->fresh()->manager_id)->toBeNull();
});

it('refuses an empty target', function () {
    $chat = assignThread();

    Livewire::actingAs(staffUser(Role::Admin))->test(Show::class, ['telegramUser' => $chat])
        ->call('assign')
        ->assertDispatched('toast', type: 'error');
});

it('lists only eligible targets', function () {
    $admin = staffUser(Role::Admin);
    $active = User::factory()->withRole(Role::SalesManager)->create(['name' => 'Eligible Manager']);
    User::factory()->withRole(Role::SalesManager)->create(['name' => 'Gone Manager', 'is_active' => false]);
    User::factory()->withRole(Role::ContentManager)->create(['name' => 'Content Person']);
    $chat = assignThread();

    Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat])
        ->assertSeeHtml('value="'.$active->id.'"')
        ->assertDontSee('Gone Manager')
        ->assertDontSee('Content Person');
});

it('writes an Activity row with ids only on assign', function () {
    $actor = staffUser(Role::Admin);
    $from = assignManager();
    $to = assignManager();
    $chat = assignThread(manager: $from);

    expect(app(AssignConversation::class)->assign($chat, $actor, $to->id))->toBeTrue();

    $activity = $chat->activities()->sole();
    expect($activity->type)->toBe(ActivityType::Assignment)
        ->and($activity->user_id)->toBe($actor->id)
        ->and($activity->meta)->toBe(['action' => 'assign', 'from_id' => $from->id, 'to_id' => $to->id]);

    expect(app(AssignConversation::class)->assign($chat->fresh(), $actor, $to->id))->toBeFalse()
        ->and($chat->activities()->count())->toBe(1);
});

it('refuses when the conditional update loses the race', function () {
    $winner = assignManager();
    $chat = assignThread();

    TelegramUser::retrieved(function () use ($chat, $winner): void {
        TelegramUser::query()->whereKey($chat->id)->update(['manager_id' => $winner->id]);
    });

    expect(fn () => app(AssignConversation::class)->assign($chat, staffUser(Role::Admin), assignManager()->id))
        ->toThrow(fn (InboxRefused $refused) => expect($refused->reason)->toBe('taken'));
    expect($chat->activities()->count())->toBe(0);
});

it('takes an unassigned thread once; the second manager is refused', function () {
    $first = assignManager();
    $second = assignManager();
    $chat = assignThread();
    $stale = TelegramUser::query()->findOrFail($chat->id);

    expect(app(AssignConversation::class)->take($chat, $first))->toBeTrue()
        ->and($chat->fresh()->manager_id)->toBe($first->id);

    expect(fn () => app(AssignConversation::class)->take($stale, $second))->toThrow(AuthorizationException::class);
    expect(fn () => app(AssignConversation::class)->take($stale, staffUser(Role::Admin)))
        ->toThrow(fn (InboxRefused $refused) => expect($refused->reason)->toBe('taken'));

    expect($chat->fresh()->manager_id)->toBe($first->id)
        ->and($chat->activities()->sole()->meta)->toBe(['action' => 'take', 'from_id' => null, 'to_id' => $first->id]);
});

it('does not let an inactive user take a thread', function () {
    $user = assignManager();
    $user->forceFill(['is_active' => false])->save();
    $chat = assignThread();

    expect(fn () => app(AssignConversation::class)->take($chat, $user))->toThrow(InboxRefused::class);
});

it('refuses to assign an unregistered conversation, for an Admin too', function () {
    $admin = staffUser(Role::Admin);
    $chat = TelegramUser::factory()->unregistered()->create();

    expect(fn () => app(AssignConversation::class)->assign($chat, $admin, assignManager()->id))
        ->toThrow(fn (InboxRefused $refused) => expect($refused->reason)->toBe('unregistered'));
    expect(fn () => app(AssignConversation::class)->take($chat, $admin))->toThrow(InboxRefused::class);
});

it('sends a manager who gave the thread away back to the list', function () {
    $user = assignManager();
    $target = assignManager();
    $chat = assignThread(manager: $user);

    Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])
        ->set('assignTarget', $target->id)
        ->call('assign')
        ->assertRedirect(route('admin.telegram.chats'));

    expect($chat->fresh()->manager_id)->toBe($target->id);
});

it('keeps an Admin on the thread after assigning', function () {
    $chat = assignThread();
    $target = assignManager();

    Livewire::actingAs(staffUser(Role::Admin))->test(Show::class, ['telegramUser' => $chat])
        ->set('assignTarget', $target->id)
        ->call('assign')
        ->assertNoRedirect()
        ->assertSee($target->name)
        ->assertDispatched('toast', type: 'success');
});

it('takes through the screen and removes the button', function () {
    $user = assignManager();
    $chat = assignThread();

    Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])
        ->assertSeeHtml('data-take')
        ->call('take')
        ->assertDontSeeHtml('data-take')
        ->assertSee($user->name);
});

it('closes and reopens with Activity rows, only on a real change', function () {
    $user = assignManager();
    $chat = assignThread(manager: $user);
    $action = app(ChangeConversationStatus::class);

    expect($action->close($chat, $user))->toBeTrue()
        ->and($chat->fresh()->conversation_status)->toBe(ConversationStatus::Closed)
        ->and($chat->fresh()->closed_at)->not->toBeNull()
        ->and($action->close($chat, $user))->toBeFalse();

    expect($action->reopen($chat, $user))->toBeTrue()
        ->and($chat->fresh()->conversation_status)->toBe(ConversationStatus::Open)
        ->and($chat->fresh()->closed_at)->toBeNull();

    $rows = $chat->activities()->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->type)->toBe(ActivityType::StatusChange)
        ->and($rows[0]->meta)->toBe(['from' => 'open', 'to' => 'closed'])
        ->and($rows[1]->meta)->toBe(['from' => 'closed', 'to' => 'open']);
});

it('swaps close and reopen on the screen', function () {
    $user = assignManager();
    $chat = assignThread(manager: $user);

    Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])
        ->assertSeeHtml('data-close')
        ->call('close')
        ->assertSeeHtml('data-reopen')
        ->assertDontSeeHtml('data-close')
        ->call('reopen')
        ->assertSeeHtml('data-close');
});

it('reopens a closed conversation on an inbound message', function () {
    Bus::fake();
    $chat = TelegramUser::factory()->registered()->closed()->create(['telegram_id' => 5550001]);

    app(StoreInboundMessage::class)->handle($chat, UpdateParser::fromArray(telegramPrivateUpdate(1, ['text' => 'hello'], 5550001)));

    expect($chat->fresh()->conversation_status)->toBe(ConversationStatus::Open)
        ->and($chat->fresh()->closed_at)->toBeNull()
        ->and($chat->fresh()->unread_count)->toBe(1);
});

it('marks threads of an inactive manager on the Admin index only (T21-17)', function () {
    $gone = assignManager();
    $gone->forceFill(['is_active' => false])->save();
    assignThread(manager: $gone);
    assignThread(manager: assignManager());

    $admin = Livewire::actingAs(staffUser(Role::Admin))->test(Index::class)->html();
    expect(substr_count($admin, 'data-inactive-manager'))->toBeGreaterThan(0);

    $sales = assignManager();
    assignThread(manager: $sales);
    Livewire::actingAs($sales)->test(Index::class)->assertDontSeeHtml('data-inactive-manager');
});

it('does not mark an active manager\'s thread', function () {
    assignThread(manager: assignManager());

    Livewire::actingAs(staffUser(Role::Admin))->test(Index::class)->assertDontSeeHtml('data-inactive-manager');
});
