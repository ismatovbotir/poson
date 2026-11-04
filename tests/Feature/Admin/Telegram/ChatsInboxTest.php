<?php

use App\Actions\Telegram\MarkConversationRead;
use App\Enums\ConversationStatus;
use App\Enums\Role;
use App\Enums\TelegramMessageKind;
use App\Enums\TelegramMessageStatus;
use App\Livewire\Admin\Telegram\Chats\Index;
use App\Livewire\Admin\Telegram\Chats\Show;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Models\User;
use App\Support\Admin\Menu;
use App\Support\Telegram\InboxMessage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Plan 21 A1: staff inbox Index and read-only Show (T21-10, T21-11, T21-18).
 */
function inboxManager(): User
{
    return User::factory()->withRole(Role::SalesManager)->create();
}

function thread(array $attributes = [], ?User $manager = null): TelegramUser
{
    $factory = TelegramUser::factory()->registered();

    return ($manager ? $factory->assigned($manager) : $factory)->create($attributes);
}

it('opens for admin and sales manager and refuses the other roles', function (Role $role, int $status) {
    $user = staffUser($role);
    $chat = thread();

    $this->actingAs($user)->get(route('admin.telegram.chats'))->assertStatus($status);
    $this->actingAs($user)->get(route('admin.telegram.chats.show', $chat))->assertStatus($status);
})->with([
    'admin' => [Role::Admin, 200],
    'sales manager' => [Role::SalesManager, 200],
    'content manager' => [Role::ContentManager, 403],
    'service engineer' => [Role::ServiceEngineer, 403],
]);

it('redirects guests to the login', function () {
    $this->get(route('admin.telegram.chats'))->assertRedirect();
});

it('refuses another manager\'s thread by URL and by Livewire call', function () {
    $user = staffUser(Role::SalesManager);
    $chat = thread(manager: inboxManager());

    $this->actingAs($user)->get(route('admin.telegram.chats.show', $chat))->assertForbidden();
    Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])->assertForbidden();
});

it('gives 403 on the next poll after the thread was reassigned', function () {
    $user = staffUser(Role::SalesManager);
    $chat = thread(manager: $user);

    $component = Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])->assertOk();

    $chat->forceFill(['manager_id' => inboxManager()->id])->save();

    $component->call('refreshThread')->assertForbidden();
});

it('keeps unregistered conversations from non-inbox-all staff (T21-11)', function () {
    $sales = staffUser(Role::SalesManager);
    $free = TelegramUser::factory()->unregistered()->create(['first_name' => 'Visitorname']);
    $own = TelegramUser::factory()->unregistered()->assigned($sales)->create();

    $this->actingAs($sales)->get(route('admin.telegram.chats.show', $free))->assertForbidden();
    $this->actingAs($sales)->get(route('admin.telegram.chats.show', $own))->assertForbidden();
    Livewire::actingAs($sales)->test(Index::class)->assertDontSee('Visitorname');

    Livewire::actingAs(staffUser(Role::Admin))->test(Index::class)->assertSee('Visitorname');
});

it('lists only visible conversations and filters by scope, status and search', function () {
    $sales = staffUser(Role::SalesManager);
    thread(['name' => 'Mine Customer', 'unread_count' => 2], $sales);
    thread(['name' => 'Free Customer']);
    thread(['name' => 'Other Customer'], inboxManager());
    thread(['name' => 'Closed Customer', 'conversation_status' => ConversationStatus::Closed]);

    $list = Livewire::actingAs($sales)->test(Index::class);
    $list->assertSee('Mine Customer')->assertSee('Free Customer')->assertDontSee('Other Customer');

    $list->call('setScope', 'mine')->assertSee('Mine Customer')->assertDontSee('Free Customer');
    $list->call('setScope', 'unassigned')->assertDontSee('Mine Customer')->assertSee('Free Customer');
    $list->call('setScope', 'unread')->assertSee('Mine Customer')->assertDontSee('Free Customer');
    $list->call('setScope', 'all')->set('status', 'closed')->assertSee('Closed Customer')->assertDontSee('Free Customer');
    $list->set('status', '')->set('search', 'free cust')->assertSee('Free Customer')->assertDontSee('Mine Customer');

    Livewire::actingAs(staffUser(Role::Admin))->test(Index::class)->assertSee('Other Customer');
});

it('ignores a tampered scope and refuses a changed conversation id', function () {
    $user = staffUser(Role::SalesManager);
    $chat = thread();

    Livewire::actingAs($user)->test(Index::class)->set('scope', 'bogus')->assertOk();
    Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])
        ->set('conversationId', thread(manager: inboxManager())->id)
        ->assertStatus(500);
})->throws(CannotUpdateLockedPropertyException::class);

it('renders customer text escaped with line breaks and no links', function () {
    $user = staffUser(Role::Admin);
    $chat = thread(['name' => '<img src=x onerror=alert(1)>']);
    TelegramMessage::factory()->for($chat)->create(['text' => "<script>alert('x')</script>\nsee https://evil.example/a <b>bold</b>"]);
    TelegramMessage::factory()->for($chat)->media(TelegramMessageKind::Document)->create(['media' => ['file_name' => '<i>x</i>.pdf', 'mime_type' => 'application/pdf', 'file_size' => 2048]]);

    $html = Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])->html();

    expect($html)->toContain('&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;<br />')
        ->and($html)->toContain('https://evil.example/a &lt;b&gt;bold&lt;/b&gt;')
        ->and($html)->not->toContain('<script>alert')
        ->and($html)->not->toContain('<b>bold')
        ->and($html)->not->toContain('<img src=x')
        ->and($html)->not->toContain('<i>x</i>')
        ->and($html)->not->toContain('href="https://evil.example')
        ->and($html)->toContain('&lt;i&gt;x&lt;/i&gt;.pdf');

    $list = Livewire::actingAs($user)->test(Index::class)->html();
    expect($list)->not->toContain('<img src=x');
});

it('never renders or holds telegram_id or file ids', function () {
    $user = staffUser(Role::Admin);
    $chat = thread(['telegram_id' => 7_700_123_456]);
    TelegramMessage::factory()->for($chat)->media(TelegramMessageKind::Photo)->create();

    $show = Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat]);
    $index = Livewire::actingAs($user)->test(Index::class);

    foreach ([$show, $index] as $component) {
        expect($component->html())->not->toContain('7700123456')->not->toContain('file_id')
            ->and(json_encode($component->snapshot))->not->toContain('7700123456')->not->toContain('file_id')->not->toContain('telegram_id');
    }
});

it('marks only the opened thread read and refuses the badge write elsewhere', function () {
    $user = staffUser(Role::SalesManager);
    $one = thread(['unread_count' => 3], $user);
    $two = thread(['unread_count' => 5], $user);
    $foreign = thread(['unread_count' => 4], inboxManager());

    Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $one]);

    expect($one->fresh()->unread_count)->toBe(0)
        ->and($two->fresh()->unread_count)->toBe(5)
        ->and($foreign->fresh()->unread_count)->toBe(4);

    expect(fn () => app(MarkConversationRead::class)->handle($foreign, $user))->toThrow(AuthorizationException::class);
    expect($foreign->fresh()->unread_count)->toBe(4);
});

it('shows the latest 50 messages, loads older ones and highlights stuck rows', function () {
    $user = staffUser(Role::Admin);
    $chat = thread();
    TelegramMessage::factory()->for($chat)->count(60)->sequence(fn ($s) => ['text' => 'msg-'.str_pad((string) ($s->index + 1), 3, '0', STR_PAD_LEFT)])->create();
    $stuck = TelegramMessage::factory()->for($chat)->outbound($user)->create(['status' => TelegramMessageStatus::Sending, 'text' => 'stuck-out']);
    TelegramMessage::query()->whereKey($stuck->id)->update(['updated_at' => now()->subMinutes(30)]);

    $component = Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat]);

    $component->assertSee('msg-060')->assertSee('stuck-out')->assertDontSee('msg-005')->assertSee(__('telegram_inbox.show.load_older'))
        ->assertSeeHtml('data-stuck');

    $component->call('loadOlder')->assertSee('msg-005')->assertSee('msg-001')->assertDontSee(__('telegram_inbox.show.load_older'));
    expect(fn () => $component->set('limit', 5000))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('has no reply box and no write actions on the thread', function () {
    $user = staffUser(Role::Admin);
    $chat = thread();

    $component = Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat]);

    expect($component->html())->not->toContain('<textarea');
    expect(fn () => $component->call('reply', 'hi'))->toThrow(MethodNotFoundException::class);
});

it('renders the index and the thread without N+1 queries', function () {
    $user = staffUser(Role::Admin);
    $queries = function (int $count) use ($user): array {
        TelegramUser::query()->delete();
        $manager = inboxManager();

        foreach (range(1, $count) as $i) {
            $chat = thread(['unread_count' => 1], $manager);
            TelegramMessage::factory()->for($chat)->count(3)->create();
            TelegramMessage::factory()->for($chat)->outbound($manager)->create();
        }

        $chat = TelegramUser::query()->latest('id')->first();
        $this->actingAs($user);

        Livewire::test(Index::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(Index::class);
        $index = count(DB::getQueryLog());
        DB::flushQueryLog();
        Livewire::test(Show::class, ['telegramUser' => $chat]);
        $show = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$index, $show];
    };

    [$smallIndex, $smallShow] = $queries(2);
    [$bigIndex, $bigShow] = $queries(12);

    expect($bigIndex)->toBe($smallIndex)->and($bigShow)->toBe($smallShow);
});

it('adds the unread badge from visible conversations only', function () {
    $sales = staffUser(Role::SalesManager);
    thread(['unread_count' => 2], $sales);
    thread(['unread_count' => 1]);
    thread(['unread_count' => 9], inboxManager());
    TelegramUser::factory()->unregistered()->create(['unread_count' => 4]);
    thread(['unread_count' => 0]);

    expect(Menu::badges($sales)['telegram-chats'])->toBe(2)
        ->and(Menu::badges(staffUser(Role::Admin))['telegram-chats'])->toBe(4);
    expect(Menu::badges(User::factory()->withRole(Role::ServiceEngineer)->create()))->not->toHaveKey('telegram-chats');
});

it('adds the Open in admin button only on https and only to the thread URL', function (string $appUrl, bool $button) {
    config(['app.url' => $appUrl]);
    $chat = thread(['name' => 'Button Customer']);

    $buttons = InboxMessage::header($chat, 'uz')->buttons;

    if ($button) {
        expect($buttons)->toHaveCount(1)
            ->and($buttons[0]['url'])->toBe(rtrim($appUrl, '/').'/admin/telegram/chats/'.$chat->id)
            ->and($buttons[0]['text'])->toBe(__('telegram.inbox.open_in_admin', [], 'uz'));
    } else {
        expect($buttons)->toBe([]);
    }
})->with([
    'https' => ['https://poson.example.uz', true],
    'https with slash' => ['https://poson.example.uz/', true],
    'http' => ['http://poson.example.uz', false],
]);

it('gives a chat member without access 403 when following the button link (T21-18)', function () {
    config(['app.url' => 'https://poson.example.uz']);
    $chat = thread(manager: inboxManager());
    $url = InboxMessage::header($chat, 'uz')->buttons[0]['url'];
    $path = parse_url($url, PHP_URL_PATH);

    $this->actingAs(staffUser(Role::SalesManager))->get($path)->assertForbidden();
    $this->actingAs(User::factory()->withRole(Role::ContentManager)->create())->get($path)->assertForbidden();
    $this->actingAs(User::factory()->withRole(Role::Admin)->create())->get($path)->assertOk();
});
