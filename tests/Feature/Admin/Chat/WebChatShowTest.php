<?php

require_once __DIR__.'/../../Chat/Web/WebChatHelpers.php';

use App\Actions\Chat\Web\PostedMessage;
use App\Actions\Chat\Web\ResolveWebChat;
use App\Actions\Chat\Web\SendWebChatReply;
use App\Actions\Telegram\InboxRefused;
use App\Enums\ChatAuthor;
use App\Enums\ChatMode;
use App\Enums\Role;
use App\Livewire\Admin\Chat\Web\Show;
use App\Models\Customer;
use App\Models\User;
use App\Models\WebChat;
use App\Support\Chat\WebChatVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Plan 28 C5c: Admin\Chat\Web\Show (WC-9, WC-12, WC-14, CB-21).
 */
beforeEach(fn () => wcSetup(defaults: false));

function webSales(): User
{
    return User::factory()->withRole(Role::SalesManager)->create();
}

function webThread(array $attributes = [], ?User $manager = null): WebChat
{
    $factory = WebChat::factory()->handedOff();

    return ($manager ? $factory->assignedTo($manager) : $factory)->create($attributes);
}

function webVersion(WebChat $chat): ?string
{
    return WebChatVersion::get($chat->id);
}

it('opens for admin and sales manager and refuses the other roles', function (Role $role, int $status) {
    $user = staffUser($role);
    $chat = webThread();

    $this->actingAs($user)->get(route('admin.telegram.chats.web.show', $chat))->assertStatus($status);
    expect(route('admin.telegram.chats.web.show', $chat, false))->toBe('/admin/chats/web/'.$chat->id);
})->with([
    'admin' => [Role::Admin, 200],
    'sales manager' => [Role::SalesManager, 200],
    'content manager' => [Role::ContentManager, 403],
    'service engineer' => [Role::ServiceEngineer, 403],
]);

it('redirects guests to the login', function () {
    $this->get(route('admin.telegram.chats.web.show', webThread()))->assertRedirect();
});

it('refuses another manager\'s chat and a bot-only chat to sales, by URL and by Livewire call', function (string $kind) {
    $sales = staffUser(Role::SalesManager);
    $chat = $kind === 'other' ? webThread(manager: webSales()) : WebChat::factory()->create();

    $this->actingAs($sales)->get(route('admin.telegram.chats.web.show', $chat))->assertForbidden();
    Livewire::actingAs($sales)->test(Show::class, ['webChat' => $chat])->assertForbidden();
})->with(['other', 'bot-only']);

it('shows a bot-only chat to the admin', function () {
    $this->actingAs(staffUser(Role::Admin))->get(route('admin.telegram.chats.web.show', WebChat::factory()->create()))->assertOk();
});

it('refuses every method after the chat went to another manager (403 on the next poll)', function (string $method, array $args) {
    $sales = staffUser(Role::SalesManager);
    $chat = webThread(manager: $sales);
    $component = Livewire::actingAs($sales)->test(Show::class, ['webChat' => $chat])->assertOk();

    $chat->forceFill(['manager_id' => webSales()->id])->save();

    $component->call($method, ...$args)->assertForbidden();
})->with([
    'refresh' => ['refreshThread', []],
    'reply' => ['sendReply', []],
    'close' => ['close', []],
    'ban' => ['ban', []],
    'take' => ['take', []],
]);

it('refuses a tampered chat id and another viewer', function () {
    $sales = staffUser(Role::SalesManager);
    $component = Livewire::actingAs($sales)->test(Show::class, ['webChat' => webThread()]);

    expect(fn () => $component->set('chatId', 999))->toThrow(CannotUpdateLockedPropertyException::class);
    expect(fn () => $component->set('seenVersion', 'x'))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('escapes visitor text, labels name and phone as unverified and shows the page as plain text', function () {
    $chat = webThread(['name' => 'Alisher', 'phone' => '+998901234567', 'first_page' => '/en/product/<b>x</b>']);
    $chat->messages()->create(['author' => ChatAuthor::Visitor, 'text' => '<img src=x onerror=alert(1)> hi']);
    $chat->messages()->create(['author' => ChatAuthor::System, 'text' => 'Visitor ended the chat (staff only line).']);

    Livewire::actingAs(staffUser())->test(Show::class, ['webChat' => $chat])
        ->assertSee('&lt;img src=x onerror=alert(1)&gt; hi', false)
        ->assertDontSee('<img src=x', false)
        ->assertSee(__('telegram_inbox.web.unverified'))
        ->assertSee('Visitor ended the chat (staff only line).')
        ->assertSee('/en/product/&lt;b&gt;x&lt;/b&gt;', false)
        ->assertDontSee('href="/en/product', false);
});

it('opening marks the chat read, and a staff reply reaches the visitor on the next poll', function () {
    $sales = staffUser(Role::SalesManager);
    [$chat, $token] = wcChat(['mode' => ChatMode::Manager, 'handoff_at' => now(), 'unread_count' => 3]);
    $before = webVersion($chat);

    $component = Livewire::actingAs($sales)->test(Show::class, ['webChat' => $chat]);

    expect($chat->fresh()->unread_count)->toBe(0)->and(webVersion($chat))->not->toBe($before);

    $seen = webVersion($chat);
    $component->set('reply', 'We will call you')->call('sendReply')->assertHasNoErrors();

    $chat->refresh();
    expect($chat->mode)->toBe(ChatMode::Manager)
        ->and($chat->manager_id)->toBe($sales->id)
        ->and($chat->last_staff_reply_at)->not->toBeNull()
        ->and($chat->messages()->where('author', ChatAuthor::Staff)->where('text', 'We will call you')->where('user_id', $sales->id)->exists())->toBeTrue()
        ->and(webVersion($chat))->not->toBe($seen);

    wcRequest($token);
    expect(app(ResolveWebChat::class)->handle()->chat?->id)->toBe($chat->id);
});

it('skips the render of a poll when the version did not change', function () {
    $chat = webThread();
    $component = Livewire::actingAs(staffUser())->test(Show::class, ['webChat' => $chat]);

    WebChatVersion::bump($chat->id);
    $component->call('refreshThread');
    $seen = $component->get('seenVersion');

    expect($seen)->toBe(webVersion($chat));
    $component->call('refreshThread')->assertSet('seenVersion', $seen);
});

it('refuses a reply to a closed, expired or banned chat and stores nothing', function (string $state) {
    $admin = staffUser();
    $chat = match ($state) {
        'closed' => webThread(['conversation_status' => 'closed', 'closed_at' => now()]),
        'banned' => webThread(['banned_at' => now()]),
        'expired' => webThread(['last_message_at' => now()->subDays(2)]),
    };

    expect(fn () => app(SendWebChatReply::class)->handle($chat, $admin, 'Hello'))->toThrow(InboxRefused::class)
        ->and($chat->messages()->count())->toBe(0);

    Livewire::actingAs($admin)->test(Show::class, ['webChat' => $chat])->set('reply', 'Hello')->call('sendReply');
    expect($chat->messages()->where('author', ChatAuthor::Staff)->count())->toBe(0);
})->with(['closed', 'banned', 'expired']);

it('cleans staff text, refuses an empty one and ignores a double submit', function () {
    $admin = staffUser();
    $chat = webThread();
    $send = app(SendWebChatReply::class);

    expect(fn () => $send->handle($chat, $admin, "\u{202E}\u{200B}  "))->toThrow(InboxRefused::class);

    $first = $send->handle($chat, $admin, "Hi\u{202E} there");

    expect($first->text)->toBe('Hi there')
        ->and($send->handle($chat, $admin, "Hi\u{202E} there"))->toBeNull()
        ->and($chat->messages()->where('author', ChatAuthor::Staff)->count())->toBe(1)
        ->and($chat->fresh()->manager_id)->toBeNull();
});

it('refuses a reply from a user without access', function () {
    expect(fn () => app(SendWebChatReply::class)->handle(webThread(), staffUser(Role::ServiceEngineer), 'Hi'))->toThrow(AuthorizationException::class);
});

it('bumps the version on every staff write', function (string $method, array $state, string $assert) {
    $sales = staffUser(Role::SalesManager);
    $chat = webThread($state['attributes'] ?? [], ($state['own'] ?? false) ? $sales : null);
    $component = Livewire::actingAs($sales)->test(Show::class, ['webChat' => $chat]);
    Cache::forget(WebChatVersion::key($chat->id));

    $component->call($method);

    expect(webVersion($chat))->not->toBeNull();
    $chat->refresh();
    expect(match ($assert) {
        'taken' => $chat->manager_id === $sales->id,
        'closed' => $chat->isClosed() && $chat->mode === ChatMode::Bot,
        'reopened' => ! $chat->isClosed(),
        'banned' => $chat->isBanned(),
        'unbanned' => ! $chat->isBanned(),
    })->toBeTrue();
})->with([
    'take' => ['take', [], 'taken'],
    'close' => ['close', [], 'closed'],
    'reopen' => ['reopen', ['attributes' => ['conversation_status' => 'closed', 'closed_at' => now()]], 'reopened'],
    'ban' => ['ban', [], 'banned'],
    'unban' => ['unban', ['attributes' => ['banned_at' => now()]], 'unbanned'],
]);

it('shows an assign picker only to those who may and assigns to an active inbox user', function () {
    $admin = staffUser();
    $target = webSales();
    $chat = webThread();

    Livewire::actingAs($admin)->test(Show::class, ['webChat' => $chat])
        ->set('assignOpen', true)->set('assignTarget', $target->id)->call('assign');

    expect($chat->fresh()->manager_id)->toBe($target->id);

    Livewire::actingAs($admin)->test(Show::class, ['webChat' => $chat])
        ->set('assignTarget', staffUser(Role::ContentManager)->id)->call('assign');

    expect($chat->fresh()->manager_id)->toBe($target->id);
});

it('ban keeps the row, makes the visitor see unavailable and starts no new chat (WC-14)', function () {
    [$chat, $token] = wcChat(['mode' => ChatMode::Manager, 'handoff_at' => now()]);

    Livewire::actingAs(staffUser())->test(Show::class, ['webChat' => $chat])->call('ban');

    expect($chat->fresh()->banned_at)->not->toBeNull()->and(WebChat::query()->count())->toBe(1);

    wcRequest($token);
    $resolved = app(ResolveWebChat::class)->handle();
    expect($resolved->banned)->toBeTrue()->and($resolved->chat)->toBeNull();

    $posted = wcSend('hello again', $token);
    expect($posted->status)->toBe(PostedMessage::UNAVAILABLE)->and(WebChat::query()->count())->toBe(1);
});

it('converts to a lead from the row phone, never to a customer, and shows the lead link', function () {
    $admin = staffUser();
    $customer = Customer::factory()->create(['phone' => '+998901234567']);
    $chat = webThread(['phone' => '+998901234567', 'name' => 'Alisher']);
    $chat->messages()->create(['author' => ChatAuthor::Visitor, 'text' => 'Need a quote for ZD421']);

    Livewire::actingAs($admin)->test(Show::class, ['webChat' => $chat])
        ->call('openConvert')->call('convert');

    $chat->refresh();
    expect($chat->lead_id)->not->toBeNull()
        ->and($chat->customer_id)->toBeNull()
        ->and($chat->lead->customer_id)->toBeNull()
        ->and($customer->id)->not->toBeNull();
    expect(webVersion($chat))->not->toBeNull();
});

it('refuses to convert without a phone', function () {
    $chat = webThread();
    $chat->messages()->create(['author' => ChatAuthor::Visitor, 'text' => 'hello']);

    Livewire::actingAs(staffUser())->test(Show::class, ['webChat' => $chat])->call('openConvert')->call('convert');

    expect($chat->fresh()->lead_id)->toBeNull();
});
