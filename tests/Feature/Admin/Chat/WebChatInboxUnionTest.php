<?php

require_once __DIR__.'/../../Chat/Web/WebChatHelpers.php';

use App\Enums\Role;
use App\Livewire\Admin\Telegram\Chats\Index;
use App\Models\TelegramUser;
use App\Models\User;
use App\Models\WebChat;
use App\Support\Admin\Menu;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Plan 28 C5c, WC-12: the inbox lists both channels (UNION ALL only for "all"),
 * each branch through its own visibleTo scope. Group "mysql-union": run it on
 * MySQL once before C5-rev (php artisan test --group=mysql-union with DB_CONNECTION=mysql).
 */
beforeEach(fn () => Http::preventStrayRequests());

function inboxTelegram(array $attributes = [], ?User $manager = null): TelegramUser
{
    $factory = TelegramUser::factory()->registered();

    return ($manager ? $factory->assigned($manager) : $factory)->create($attributes);
}

function inboxWeb(array $attributes = [], ?User $manager = null): WebChat
{
    $factory = WebChat::factory()->handedOff();

    return ($manager ? $factory->assignedTo($manager) : $factory)->create($attributes);
}

it('lists both channels in one list, newest first, and filters by channel', function () {
    $sales = staffUser(Role::SalesManager);
    inboxTelegram(['name' => 'Tgperson', 'last_message_at' => now()->subMinutes(5)]);
    inboxWeb(['name' => 'Webperson', 'last_message_at' => now()->subMinutes(1)]);

    $list = Livewire::actingAs($sales)->test(Index::class);
    $list->assertSeeInOrder(['Webperson', 'Tgperson'])->assertSee(__('telegram_inbox.channels.web'));

    $list->call('setChannel', 'telegram')->assertSee('Tgperson')->assertDontSee('Webperson');
    $list->call('setChannel', 'web')->assertSee('Webperson')->assertDontSee('Tgperson');
})->group('mysql-union');

it('scopes each branch: sales sees handed-off unassigned or own web chats and never bot-only or others', function () {
    $sales = staffUser(Role::SalesManager);
    inboxWeb(['name' => 'Ownweb'], $sales);
    inboxWeb(['name' => 'Freeweb']);
    inboxWeb(['name' => 'Otherweb'], User::factory()->withRole(Role::SalesManager)->create());
    WebChat::factory()->create(['name' => 'Botonlyweb']);
    inboxTelegram(['name' => 'Othertg'], User::factory()->withRole(Role::SalesManager)->create());

    Livewire::actingAs($sales)->test(Index::class)
        ->assertSee('Ownweb')->assertSee('Freeweb')
        ->assertDontSee('Otherweb')->assertDontSee('Botonlyweb')->assertDontSee('Othertg');

    Livewire::actingAs(staffUser())->test(Index::class)
        ->assertSee('Otherweb')->assertSee('Botonlyweb');
})->group('mysql-union');

it('treats an unknown channel as all, and a tampered scope as all', function () {
    $sales = staffUser(Role::SalesManager);
    inboxTelegram(['name' => 'Tgperson']);
    inboxWeb(['name' => 'Webperson']);

    Livewire::actingAs($sales)->test(Index::class)->set('channel', 'x" OR 1=1 --')->set('scope', 'zzz')
        ->assertSee('Tgperson')->assertSee('Webperson');
})->group('mysql-union');

it('queries only the Telegram branch for the Telegram-only unlinked scope', function () {
    $sales = staffUser(Role::SalesManager);
    inboxTelegram(['name' => 'Unlinkedtg']);
    inboxWeb(['name' => 'Webperson']);

    Livewire::actingAs($sales)->test(Index::class)->call('setScope', 'unlinked')
        ->assertSee('Unlinkedtg')->assertDontSee('Webperson');
});

it('searches web chats by name, phone digits and W-id, with wildcards matching literally', function () {
    $admin = staffUser();
    $a = inboxWeb(['name' => 'Alisher', 'phone' => '+998901112233']);
    inboxWeb(['name' => 'Bobur', 'phone' => '+998907778899']);

    $list = Livewire::actingAs($admin)->test(Index::class)->call('setChannel', 'web');

    $list->set('search', 'alish')->assertSee('Alisher')->assertDontSee('Bobur');
    $list->set('search', '1112233')->assertSee('Alisher')->assertDontSee('Bobur');
    $list->set('search', 'W-'.$a->id)->assertSee('Alisher')->assertDontSee('Bobur');
    $list->set('search', 'W-%')->assertDontSee('Alisher')->assertDontSee('Bobur');
    $list->set('search', '100%_')->assertDontSee('Alisher')->assertDontSee('Bobur');
})->group('mysql-union');

it('paginates stably with equal timestamps and does not lazy load', function () {
    $admin = staffUser();
    $at = now()->startOfMinute();

    foreach (range(1, 15) as $i) {
        inboxTelegram(['last_message_at' => $at]);
        inboxWeb(['last_message_at' => $at]);
    }

    $first = Livewire::actingAs($admin)->test(Index::class)->viewData('items');
    $second = Livewire::actingAs($admin)->test(Index::class)->call('gotoPage', 2)->viewData('items');

    $keys = fn ($page) => $page->getCollection()->map(fn (array $row) => $row['channel'].$row['chat']->id)->all();

    expect($first->total())->toBe(30)
        ->and(count($keys($first)))->toBe(25)
        ->and(count($keys($second)))->toBe(5)
        ->and(array_intersect($keys($first), $keys($second)))->toBe([])
        ->and($first->getCollection()->every(fn (array $row) => $row['chat']->relationLoaded('manager')))->toBeTrue();
})->group('mysql-union');

it('keeps the query count flat as rows grow', function () {
    $admin = staffUser();
    $count = function (int $rows) use ($admin): int {
        TelegramUser::query()->delete();
        WebChat::query()->delete();

        foreach (range(1, $rows) as $i) {
            inboxTelegram();
            inboxWeb();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($admin)->test(Index::class);

        return count(DB::getQueryLog());
    };

    $count(2); // warms the permission cache
    $small = $count(3);

    expect($count(12))->toBe($small);
});

it('adds web unread to the menu badge, each channel through its own scope', function () {
    $sales = staffUser(Role::SalesManager);
    inboxTelegram(['unread_count' => 2], $sales);
    inboxWeb(['unread_count' => 1], $sales);
    inboxWeb(['unread_count' => 4]);
    inboxWeb(['unread_count' => 5], User::factory()->withRole(Role::SalesManager)->create());
    WebChat::factory()->create(['unread_count' => 7]);

    expect(Menu::badges($sales)['telegram-chats'])->toBe(3)
        ->and(Menu::badges(staffUser(Role::Admin))['telegram-chats'])->toBe(5);
    expect(Menu::badges(User::factory()->withRole(Role::ServiceEngineer)->create()))->not->toHaveKey('telegram-chats');
});

it('refuses the inbox to content managers and service engineers', function (Role $role) {
    $this->actingAs(staffUser($role))->get(route('admin.telegram.chats'))->assertForbidden();
})->with([Role::ContentManager, Role::ServiceEngineer]);

it('resets the Telegram-only unlinked scope when the web channel is chosen, in either order', function () {
    $sales = staffUser(Role::SalesManager);
    inboxWeb(['name' => 'Webperson']);

    Livewire::actingAs($sales)->test(Index::class)->call('setScope', 'unlinked')->call('setChannel', 'web')
        ->assertSet('scope', 'all')->assertSee('Webperson');

    Livewire::actingAs($sales)->test(Index::class)->call('setChannel', 'web')->call('setScope', 'unlinked')
        ->assertSet('scope', 'all')->assertSee('Webperson');
});
