<?php

use App\Actions\Telegram\MarkConversationRead;
use App\Enums\ConversationStatus;
use App\Enums\Role;
use App\Enums\TelegramMessageKind;
use App\Http\Middleware\EnsureStaff;
use App\Livewire\Admin\Telegram\Chats\Index;
use App\Livewire\Admin\Telegram\Chats\Show;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Models\User;
use App\Support\Telegram\InboxMessage;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Plan 21 A1: abuse cases for the staff inbox (Index and read-only Show).
 * Complements tests/Feature/Admin/Telegram/ChatsInboxTest.php.
 */
/**
 * Trusted hosts are static on the Symfony request and leak between tests that make web requests;
 * reset them before and after so no test depends on file order.
 */
beforeEach(function () {
    Http::fake();
    SymfonyRequest::setTrustedHosts([]);
});

afterEach(function () {
    SymfonyRequest::setTrustedHosts([]);
});

function abuseManager(): User
{
    return User::factory()->withRole(Role::SalesManager)->create();
}

/**
 * A registered thread with neutral names so the search tests stay deterministic.
 *
 * @param  array<string, mixed>  $attributes
 */
function abuseThread(array $attributes = [], ?User $manager = null): TelegramUser
{
    $factory = TelegramUser::factory()->registered();

    return ($manager ? $factory->assigned($manager) : $factory)->create($attributes + [
        'username' => null,
        'first_name' => 'Fname',
        'last_name' => 'Lname',
    ]);
}

/**
 * Whether the call was refused (any exception) before it could act.
 */
function abuseRefused(Closure $call): bool
{
    try {
        $call();
    } catch (Throwable) {
        return true;
    }

    return false;
}

// 1

it('clears unread across two viewers only for the manager or on an unassigned thread (T21-26)', function () {
    $first = staffUser(Role::SalesManager);
    $second = abuseManager();
    $admin = staffUser(Role::Admin);
    $chat = abuseThread(['unread_count' => 3]);

    Livewire::actingAs($first)->test(Show::class, ['telegramUser' => $chat]);
    expect($chat->fresh()->unread_count)->toBe(0);

    $chat->forceFill(['unread_count' => 2])->save();
    Livewire::actingAs($second)->test(Show::class, ['telegramUser' => $chat])->call('refreshThread')->assertOk();
    expect($chat->fresh()->unread_count)->toBe(0);

    $chat->forceFill(['manager_id' => $first->id, 'unread_count' => 4])->save();

    $adminView = Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat]);
    $adminView->call('refreshThread');
    expect($chat->fresh()->unread_count)->toBe(4);

    Livewire::actingAs($second)->test(Show::class, ['telegramUser' => $chat])->assertForbidden();
    expect($chat->fresh()->unread_count)->toBe(4);

    Livewire::actingAs($first)->test(Show::class, ['telegramUser' => $chat]);
    expect($chat->fresh()->unread_count)->toBe(0);
});

// 2

it('leaves unread unchanged on a poll or loadOlder refused after reassignment', function () {
    $user = staffUser(Role::SalesManager);
    $chat = abuseThread(['unread_count' => 0], $user);
    $poll = Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])->assertOk();
    $older = Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])->assertOk();

    $chat->forceFill(['manager_id' => abuseManager()->id, 'unread_count' => 5])->save();

    $poll->call('refreshThread')->assertForbidden();
    expect($chat->fresh()->unread_count)->toBe(5);

    $older->call('loadOlder')->assertForbidden();
    expect($chat->fresh()->unread_count)->toBe(5);
});

// 3

it('refuses polls and marks nothing read for staff deactivated mid-session', function () {
    $user = staffUser(Role::SalesManager);
    $chat = abuseThread(['unread_count' => 0], $user);
    Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])->assertOk();

    $chat->forceFill(['unread_count' => 3])->save();
    $user->forceFill(['is_active' => false])->save();

    // Livewire::test() does not run the persistent middleware; real update requests are covered in the two update-request tests below.
    expect(Livewire::getPersistentMiddleware())->toContain(EnsureStaff::class);

    $this->actingAs($user->fresh())->get(route('admin.telegram.chats.show', $chat))->assertRedirect(route('admin.login'));
    expect($chat->fresh()->unread_count)->toBe(3);

    foreach ([route('admin.telegram.chats'), route('admin.telegram.chats.show', $chat)] as $url) {
        $this->actingAs($user->fresh())->get($url, ['X-Livewire' => 'true'])->assertForbidden();
        expect($chat->fresh()->unread_count)->toBe(3);
    }
});

it('refuses a bare $refresh on Show after reassignment and leaks no new message (hydrate)', function () {
    $user = staffUser(Role::SalesManager);
    $chat = abuseThread([], $user);
    $component = Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])->assertOk();

    $chat->forceFill(['manager_id' => abuseManager()->id])->save();
    TelegramMessage::factory()->for($chat)->create(['text' => 'brand-new-secret-message']);

    $component->call('$refresh')->assertForbidden()->assertDontSee('brand-new-secret-message');
});

/**
 * Real Livewire update request for a user deactivated after the page was rendered.
 * One update request per test: several in one application instance wrongly returned 200.
 */
function deactivatedUpdateRequest(string $page, string $snapshotKey, string $method): void
{
    test()->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->withRole(Role::SalesManager)->create();
    $chat = abuseThread(['unread_count' => 0], $user);
    $url = $page === 'show' ? route('admin.telegram.chats.show', $chat) : route('admin.telegram.chats');

    $html = test()->actingAs($user)->get($url)->assertOk()->getContent();
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

    $snapshot = collect($matches[1])
        ->map(fn (string $raw) => html_entity_decode($raw, ENT_QUOTES))
        ->first(fn (string $candidate) => array_key_exists($snapshotKey, json_decode($candidate, true)['data'] ?? []));
    expect($snapshot)->not->toBeNull();

    $chat->forceFill(['unread_count' => 3])->save();
    $user->forceFill(['is_active' => false])->save();

    test()->withHeaders(['X-Livewire' => '1'])
        ->postJson(app('livewire')->getUpdateUri(), [
            'components' => [['snapshot' => $snapshot, 'calls' => [['method' => $method, 'params' => [], 'metadata' => []]], 'updates' => []]],
        ])
        ->assertForbidden();

    test()->assertGuest();
    expect($chat->fresh()->unread_count)->toBe(3);
}

it('returns 403 to a real Show update request after the manager is deactivated', function () {
    deactivatedUpdateRequest('show', 'conversationId', 'refreshThread');
});

it('returns 403 to a real Index update request after the manager is deactivated', function () {
    deactivatedUpdateRequest('index', 'scope', '$refresh');
});

it('does not clear unread through a model loaded before the thread was reassigned', function () {
    $user = staffUser(Role::SalesManager);
    $chat = abuseThread(['unread_count' => 3]);
    $stale = TelegramUser::query()->findOrFail($chat->id);

    TelegramUser::query()->whereKey($chat->id)->update(['manager_id' => abuseManager()->id]);

    expect(abuseRefused(fn () => app(MarkConversationRead::class)->handle($stale, $user)))->toBeFalse()
        ->and($chat->fresh()->unread_count)->toBe(3);
});

// 4

it('gives 403 on the next Index and Show poll once telegram.inbox is revoked', function () {
    $user = staffUser(Role::SalesManager);
    $chat = abuseThread(['unread_count' => 0], $user);
    $show = Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])->assertOk();
    $index = Livewire::actingAs($user)->test(Index::class)->assertOk();

    $chat->forceFill(['unread_count' => 2])->save();
    $user->roles->first()->revokePermissionTo('telegram.inbox');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user->unsetRelation('roles')->unsetRelation('permissions');

    $show->call('refreshThread')->assertForbidden();
    $index->call('$refresh')->assertForbidden();
    expect($chat->fresh()->unread_count)->toBe(2);

    $this->actingAs($user)->get(route('admin.telegram.chats'))->assertForbidden();
    $this->actingAs($user)->get(route('admin.telegram.chats.show', $chat))->assertForbidden();
});

// 5

it('gives 404 on the Show poll after the thread was deleted', function () {
    $user = staffUser(Role::Admin);
    $chat = abuseThread();
    $component = Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])->assertOk();

    $chat->delete();

    $component->call('refreshThread')->assertNotFound();
});

// 6

it('redirects a guest on the thread URL to the login', function () {
    $this->get(route('admin.telegram.chats.show', abuseThread()))->assertRedirect(route('admin.login'));
});

// 7

it('never renders another thread\'s messages on Show', function () {
    $user = staffUser(Role::SalesManager);
    $mine = abuseThread([], $user);
    $other = abuseThread([], $user);
    TelegramMessage::factory()->for($mine)->create(['text' => 'own-message-text']);
    TelegramMessage::factory()->for($other)->count(60)->create(['text' => 'neighbour-secret-text']);

    $component = Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $mine]);
    $component->assertSee('own-message-text')->assertDontSee('neighbour-secret-text');

    $component->call('loadOlder')->call('refreshThread')->assertDontSee('neighbour-secret-text');
});

// 8

it('stops at the cap after repeated loadOlder', function () {
    $chat = abuseThread();
    TelegramMessage::factory()->for($chat)->count(1100)->create();

    $component = Livewire::actingAs(staffUser(Role::Admin))->test(Show::class, ['telegramUser' => $chat]);
    foreach (range(1, 30) as $_) {
        $component->call('loadOlder');
    }

    expect($component->get('limit'))->toBe(Show::MAX_MESSAGES);
    $component->assertDontSeeHtml('data-load-older')
        ->assertDontSeeHtml('wire:poll')
        ->assertSeeHtml('data-older-hidden')
        ->assertSee(__('telegram_inbox.show.older_hidden', ['count' => Show::MAX_MESSAGES]));
    expect(substr_count($component->html(), 'data-message='))->toBe(Show::MAX_MESSAGES);
});

// 9

it('treats a tampered scope or status on Index as the defaults', function () {
    $sales = staffUser(Role::SalesManager);
    abuseThread(['name' => 'Free Open']);
    abuseThread(['name' => 'Free Closed', 'conversation_status' => ConversationStatus::Closed]);
    abuseThread(['name' => 'Foreign Row'], abuseManager());

    $viaSet = Livewire::actingAs($sales)->test(Index::class)->set('scope', 'bogus');
    $viaSet->assertOk()->assertSee('Free Open')->assertSee('Free Closed')->assertDontSee('Foreign Row');

    $viaCall = Livewire::actingAs($sales)->test(Index::class)->call('setScope', 'mine')->call('setScope', 'bogus');
    expect($viaCall->get('scope'))->toBe('all');
    $viaCall->assertSee('Free Open')->assertDontSee('Foreign Row');

    Livewire::actingAs($sales)->test(Index::class)->set('status', 'bogus')
        ->assertOk()->assertSee('Free Open')->assertSee('Free Closed')->assertDontSee('Foreign Row');
});

it('changes nothing when setScope gets an array', function () {
    $sales = staffUser(Role::SalesManager);
    abuseThread(['name' => 'Foreign Row'], abuseManager());
    $component = Livewire::actingAs($sales)->test(Index::class)->call('setScope', 'unread');

    abuseRefused(fn () => $component->call('setScope', ['all', 'mine']));

    expect($component->get('scope'))->toBe('unread');
    $component->assertDontSee('Foreign Row');
});

// 10

it('survives query-string tampering without foreign rows or error detail', function (string $query) {
    config(['app.debug' => false]);
    $sales = staffUser(Role::SalesManager);
    abuseThread(['name' => 'Foreign Row'], abuseManager());
    abuseThread(['name' => 'Free Row']);

    foreach ([route('admin.telegram.chats'), route('admin.telegram.chats.show', abuseThread(['name' => 'Other Foreign'], abuseManager()))] as $url) {
        $response = $this->actingAs($sales)->get($url.'?'.$query);
        $body = $response->getContent();

        expect($body)->not->toContain('Foreign Row')->not->toContain('Other Foreign')
            ->not->toContain('TypeError')->not->toContain('Stack trace')
            ->not->toContain('vendor/')->not->toContain(base_path());
    }

    $this->actingAs($sales)->get(route('admin.telegram.chats').'?'.$query)->assertOk();
})->with([
    'search array' => ['search[]=x'],
    'status array' => ['status[]=x'],
    'scope array' => ['scope[]=x'],
    'negative page' => ['page=-1'],
    'huge page' => ['page=99999999'],
]);

// 11

it('does not find another manager\'s thread by name, username, company or phone digits', function (string $term) {
    $sales = staffUser(Role::SalesManager);
    abuseThread([
        'name' => 'Hiddenname Person', 'username' => 'hiddenuser', 'declared_company' => 'HiddenCorp LLC', 'phone' => '+998907776655',
    ], abuseManager());
    $own = abuseThread(['name' => 'Ownname Person', 'username' => 'ownuser', 'declared_company' => 'OwnCorp', 'phone' => '+998901112233']);

    $component = Livewire::actingAs($sales)->test(Index::class)->set('search', $term);
    $component->assertDontSeeHtml('data-chat="'.TelegramUser::query()->where('username', 'hiddenuser')->value('id').'"');
    $component->assertDontSee('Hiddenname');
})->with(['hiddenname', 'hiddenuser', 'hiddencorp', '7776655', '998907776655']);

it('finds visible threads by the same fields as a control', function (string $term) {
    $sales = staffUser(Role::SalesManager);
    $own = abuseThread(['name' => 'Ownname Person', 'username' => 'ownuser', 'declared_company' => 'OwnCorp', 'phone' => '+998901112233']);

    Livewire::actingAs($sales)->test(Index::class)->set('search', $term)->assertSeeHtml('data-chat="'.$own->id.'"');
})->with(['ownname', 'ownuser', 'owncorp', '9011122']);

it('hides unregistered visitors from search for staff without inbox-all', function (string $term) {
    $visitor = TelegramUser::factory()->unregistered()->create(['username' => 'visitoruser', 'first_name' => 'Visitorfirst', 'last_name' => 'Visitorlast']);
    $sales = staffUser(Role::SalesManager);

    Livewire::actingAs($sales)->test(Index::class)->set('search', $term)->assertDontSeeHtml('data-chat="'.$visitor->id.'"');
    Livewire::actingAs(staffUser(Role::Admin))->test(Index::class)->set('search', $term)->assertSeeHtml('data-chat="'.$visitor->id.'"');
})->with(['visitoruser', 'visitorfirst', 'visitorlast']);

it('matches %, _ and ! only literally', function (string $term, string $literal, string $other) {
    $user = staffUser(Role::Admin);
    $hit = abuseThread(['name' => $literal]);
    $miss = abuseThread(['name' => $other]);

    $component = Livewire::actingAs($user)->test(Index::class)->set('search', $term);
    $component->assertSeeHtml('data-chat="'.$hit->id.'"')->assertDontSeeHtml('data-chat="'.$miss->id.'"');
})->with([
    'percent' => ['%', 'Fifty% done', 'Plain customer'],
    'underscore' => ['a_b', 'Cust a_b', 'Cust axb'],
    'escape char' => ['hi!', 'Say hi!', 'Say hithere'],
]);

it('bounds a search term to 100 characters', function () {
    $user = staffUser(Role::Admin);
    $chat = abuseThread(['name' => str_repeat('a', 100)]);

    $component = Livewire::actingAs($user)->test(Index::class)->set('search', str_repeat('a', 150));

    $component->assertOk()->assertSeeHtml('data-chat="'.$chat->id.'"');
    Livewire::actingAs($user)->test(Index::class)->set('search', str_repeat('a', 20000))->assertOk();
});

// 12

it('refuses direct calls to lifecycle and private component methods', function (string $component, string $method) {
    $user = staffUser(Role::SalesManager);
    $chat = abuseThread(['unread_count' => 4], abuseManager());
    $mine = abuseThread(['unread_count' => 4], $user);
    $params = $component === Show::class ? ['telegramUser' => $mine] : [];

    $mounted = Livewire::actingAs($user)->test($component, $params);
    TelegramUser::query()->whereKey($mine->id)->update(['unread_count' => 4]);

    expect(abuseRefused(fn () => $mounted->call($method, $chat->id)))->toBeTrue()
        ->and($chat->fresh()->unread_count)->toBe(4)
        ->and($mine->fresh()->unread_count)->toBe(4);
})->with([
    'index mount' => [Index::class, 'mount'],
    'index hydrate' => [Index::class, 'hydrate'],
    'index user' => [Index::class, 'user'],
    'show mount' => [Show::class, 'mount'],
    'show hydrate' => [Show::class, 'hydrate'],
    'show conversation' => [Show::class, 'conversation'],
    'show user' => [Show::class, 'user'],
]);

it('lets refreshThread with client arguments touch only the locked thread', function () {
    $user = staffUser(Role::SalesManager);
    $locked = abuseThread(['unread_count' => 0], $user);
    $component = Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $locked]);

    $unassigned = abuseThread(['unread_count' => 2]);
    $own = abuseThread(['unread_count' => 3], $user);
    $foreign = abuseThread(['unread_count' => 4], abuseManager());
    $locked->forceFill(['unread_count' => 1])->save();

    abuseRefused(fn () => $component->call('refreshThread', $foreign->id));
    abuseRefused(fn () => $component->call('refreshThread', $unassigned->id, $own->id));
    abuseRefused(fn () => $component->call('refreshThread', ['id' => $own->id], $user->id));
    $component->call('refreshThread');

    expect($unassigned->fresh()->unread_count)->toBe(2)
        ->and($own->fresh()->unread_count)->toBe(3)
        ->and($foreign->fresh()->unread_count)->toBe(4)
        ->and($locked->fresh()->unread_count)->toBe(0);
});

// 13

it('escapes company, INN and username on Show and Index', function () {
    $admin = staffUser(Role::Admin);
    $chat = abuseThread([
        'name' => 'Escaped Customer',
        'declared_company' => '<script>alert("co")</script>',
        'declared_inn' => '"><svg onload=alert(1)>',
        'username' => '"><img src=x onerror=alert(2)>',
    ]);

    $show = Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat])->html();
    $index = Livewire::actingAs($admin)->test(Index::class)->html();

    expect($show)->toContain('&lt;script&gt;alert(&quot;co&quot;)&lt;/script&gt;')
        ->toContain('&quot;&gt;&lt;svg onload=alert(1)&gt;')
        ->toContain('&quot;&gt;&lt;img src=x onerror=alert(2)&gt;')
        ->and($index)->toContain('&lt;script&gt;alert(&quot;co&quot;)&lt;/script&gt;')
        ->toContain('&quot;&gt;&lt;img src=x onerror=alert(2)&gt;');

    foreach ([$show, $index] as $html) {
        expect($html)->not->toContain('<script>alert("co")')->not->toContain('<svg onload')->not->toContain('<img src=x onerror');
    }
});

it('escapes a hostile company in the mobile card as well', function () {
    $chat = abuseThread(['name' => '<b>Card</b> Name', 'declared_company' => '<script>alert("card")</script>']);

    $html = Livewire::actingAs(staffUser(Role::Admin))->test(Index::class)->html();
    $card = substr($html, (int) strpos($html, 'chat-card-'.$chat->id));

    expect($card)->not->toContain('<script>alert')->not->toContain('<b>Card</b>')
        ->and($card)->toContain('&lt;b&gt;Card&lt;/b&gt; Name');
});

// 14

it('never renders or holds message ids, media group ids, forwarded ids or file_id', function () {
    $admin = staffUser(Role::Admin);
    $chat = abuseThread();
    $message = TelegramMessage::factory()->for($chat)->media(TelegramMessageKind::Photo)->forwarded()->create([
        'media' => ['file_name' => 'x.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 100, 'file_id' => 'AgACAgIDSecretFileId', 'file_unique_id' => 'UniqueSecretXyz'],
    ]);
    TelegramMessage::query()->whereKey($message->id)->update([
        'telegram_message_id' => 918273645,
        'media_group_id' => 'mgrp-SECRET-77',
        'forwarded_message_id' => 556677889,
    ]);

    $show = Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat])->call('loadOlder')->call('refreshThread');
    $index = Livewire::actingAs($admin)->test(Index::class);

    foreach ([$show, $index] as $component) {
        $blob = $component->html().json_encode($component->snapshot);

        foreach (['918273645', 'mgrp-SECRET-77', '556677889', 'AgACAgIDSecretFileId', 'UniqueSecretXyz', 'file_id', 'telegram_message_id', 'media_group_id', 'forwarded_message_id'] as $secret) {
            expect($blob)->not->toContain($secret);
        }
    }
});

// 15

it('builds the alert button from APP_URL only and only over https', function (string $appUrl, bool $button) {
    config(['app.url' => $appUrl]);
    $chat = abuseThread(['name' => 'Alert Customer']);
    $message = TelegramMessage::factory()->for($chat)->create(['text' => 'hello']);

    $buttons = InboxMessage::alert($chat, $message, 'uz')->buttons;

    if ($button) {
        expect($buttons)->toHaveCount(1)->and($buttons[0]['url'])->toBe('https://poson.example.uz/admin/telegram/chats/'.$chat->id);
    } else {
        expect($buttons)->toBe([]);
    }
})->with([
    'https' => ['https://poson.example.uz', true],
    'http' => ['http://poson.example.uz', false],
]);

it('ignores a forged request Host when building the alert URL', function (string $appUrl, bool $button) {
    // Trusted hosts are static on the Symfony request and leak from earlier web requests in the same process.
    SymfonyRequest::setTrustedHosts([]);
    config(['app.url' => $appUrl]);
    $chat = abuseThread();
    $message = TelegramMessage::factory()->for($chat)->create(['text' => 'hello']);

    $forged = Request::create('https://evil.example/admin', 'GET', server: ['HTTP_HOST' => 'evil.example', 'HTTP_X_FORWARDED_HOST' => 'evil.example', 'HTTPS' => 'on']);
    $forged->headers->set('X-Forwarded-Host', 'evil.example');
    app()->instance('request', $forged);

    $buttons = InboxMessage::alert($chat, $message, 'uz')->buttons;

    if ($button) {
        expect($buttons)->toHaveCount(1)
            ->and($buttons[0]['url'])->toBe('https://poson.example.uz/admin/telegram/chats/'.$chat->id)
            ->and($buttons[0]['url'])->not->toContain('evil.example');
    } else {
        expect($buttons)->toBe([]);
    }
})->with([
    'https' => ['https://poson.example.uz', true],
    'http' => ['http://poson.example.uz', false],
]);

it('keeps the read action bound to the passed thread', function () {
    $user = staffUser(Role::SalesManager);
    $one = abuseThread(['unread_count' => 2]);
    $two = abuseThread(['unread_count' => 2]);

    expect(app(MarkConversationRead::class)->handle($one, $user))->toBeTrue()
        ->and($two->fresh()->unread_count)->toBe(2);
});
