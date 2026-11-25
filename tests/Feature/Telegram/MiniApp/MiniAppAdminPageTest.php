<?php

use App\Actions\Telegram\SetBotEnabled;
use App\Actions\Telegram\SetMiniAppEnabled;
use App\Actions\Telegram\TelegramBotException;
use App\Enums\AuditAction;
use App\Enums\OrderSource;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Jobs\SyncMiniAppMenuButton;
use App\Jobs\SyncMiniAppMenus;
use App\Livewire\Admin\Orders\Index as OrdersIndex;
use App\Livewire\Admin\Telegram\MiniApp;
use App\Models\Order;
use App\Models\Page;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Services\Telegram\TelegramClient;
use App\Support\Admin\Menu;
use App\Support\Telegram\Bot\BotSwitch;
use App\Support\Telegram\Bot\TelegramStatus;
use App\Support\Telegram\MiniApp\BotUsername;
use App\Support\Telegram\MiniApp\MiniAppMenuSync;
use App\Support\Telegram\MiniApp\MiniAppSwitch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

require_once __DIR__.'/../../../Helpers/MiniApp.php';

/*
| Plan 20 M6: Telegram → Mini App admin page, SetMiniAppEnabled, the menu
| button fan-out, the console path, the Status row and the orders badge,
| filter and chat link. Fake token, Http::fake only.
*/
beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['id' => 42, 'is_bot' => true, 'username' => 'poson_test_bot']])]);
    miniAppReady();
});

function m6PageCalls(string $method): array
{
    return array_values(array_filter(telegramApiCalls(), fn (array $call): bool => $call['method'] === $method));
}

it('shows the switch, checklist and status line to an Admin without calling Telegram', function () {
    $admin = staffUser();

    $this->actingAs($admin)->get(route('admin.telegram.mini-app'))
        ->assertOk()
        ->assertSee('data-mini-app-state="disabled"', false)
        ->assertSee('data-check="bot_enabled" data-ok="1"', false)
        ->assertSee('data-check="payment" data-ok="1"', false)
        ->assertSee('data-sync-state="never"', false)
        ->assertSee('@poson_test_bot')
        ->assertSee('https://poson.example.uz/tma')
        ->assertSee(__('admin.telegram_mini_app.title'))
        ->assertDontSee(TG_BOT_TOKEN);

    expect(telegramApiCalls())->toBe([])
        ->and(collect(Menu::for($admin))->flatMap(fn ($group) => $group->items)->first(fn ($item) => $item->key === 'telegram-mini-app')->isAvailable())->toBeTrue();
});

it('forbids the page and every action to the other roles', function (Role $role) {
    $user = staffUser($role);

    $this->actingAs($user)->get(route('admin.telegram.mini-app'))->assertForbidden();
    Livewire::actingAs($user)->test(MiniApp::class)->assertForbidden();

    expect(fn () => app(SetMiniAppEnabled::class)->disable($user, null))->toThrow(AuthorizationException::class)
        ->and(collect(Menu::for($user))->flatMap(fn ($group) => $group->items)->pluck('key')->all())->not->toContain('telegram-mini-app');
})->with([
    'content manager' => Role::ContentManager,
    'sales manager' => Role::SalesManager,
    'service engineer' => Role::ServiceEngineer,
]);

it('enables and disables with an audit row each time and one fan-out per change', function () {
    Queue::fake([SyncMiniAppMenus::class]);
    confirmAdminPassword();
    $admin = staffUser();

    Livewire::actingAs($admin)->test(MiniApp::class)
        ->call('enable')
        ->assertDispatched('toast', type: 'success')
        ->assertSee('data-mini-app-state="enabled"', false);

    expect(MiniAppSwitch::isEnabled())->toBeTrue()
        ->and(MiniAppMenuSync::current())->toBe(1);

    Livewire::actingAs($admin)->test(MiniApp::class)
        ->call('enable')
        ->assertDispatched('toast', body: __('admin.telegram_mini_app.flash.unchanged'))
        ->call('disable')
        ->assertDispatched('toast', body: __('admin.telegram_mini_app.flash.disabled'));

    $audits = SettingsAudit::query()->where('key', MiniAppSwitch::KEY)->orderBy('id')->get();

    expect(MiniAppSwitch::isStoredOn())->toBeFalse()
        ->and(MiniAppMenuSync::current())->toBe(2)
        ->and($audits->pluck('action')->all())->toBe([AuditAction::Enabled, AuditAction::Disabled])
        ->and($audits->first()->user_id)->toBe($admin->id)
        ->and($audits->first()->area)->toBe(SettingsArea::TelegramBot);

    Queue::assertPushed(SyncMiniAppMenus::class, 2);
    Queue::assertPushedOn('default', SyncMiniAppMenus::class);
    Queue::assertPushed(SyncMiniAppMenus::class, fn (SyncMiniAppMenus $job): bool => $job->generation === 2 && $job->afterId === 0);
});

it('refuses to enable on the server when a checklist item fails', function (Closure $break, string $item) {
    Queue::fake([SyncMiniAppMenus::class]);
    confirmAdminPassword();
    $break();

    Livewire::actingAs(staffUser())->test(MiniApp::class)
        ->assertSee('data-check="'.$item.'" data-ok="0"', false)
        ->call('enable')
        ->assertDispatched('toast', type: 'error', body: __('admin.telegram_bot.errors.mini_app_not_ready'));

    expect(MiniAppSwitch::isStoredOn())->toBeFalse()
        ->and(SettingsAudit::query()->where('key', MiniAppSwitch::KEY)->exists())->toBeFalse();

    Queue::assertNotPushed(SyncMiniAppMenus::class);
})->with([
    'bot off' => [fn () => Setting::query()->where('key', BotSwitch::KEY)->update(['value' => json_encode(false)]), 'bot_enabled'],
    'no username' => [fn () => Setting::query()->where('key', BotUsername::KEY)->delete(), 'username'],
    'http APP_URL' => [fn () => config(['app.url' => 'http://poson.example.uz']), 'app_url'],
    'no payment method' => [fn () => config(['telegram.mini_app.payment_methods' => ['payme', 'click', 'uzqr_dynamic']]), 'payment'],
    'privacy page needs review' => [fn () => Page::query()->where('slug', 'privacy')->update(['needs_review' => true]), 'privacy'],
]);

it('checks the bot with getMe, stores the username and is rate-limited', function () {
    Setting::query()->where('key', BotUsername::KEY)->delete();
    $component = Livewire::actingAs(staffUser())->test(MiniApp::class);

    $component->call('checkBot')->assertDispatched('toast', type: 'success');
    expect(BotUsername::get())->toBe('poson_test_bot');

    foreach (range(2, MiniApp::CHECKS_PER_MINUTE) as $ignored) {
        $component->call('checkBot');
    }

    $component->call('checkBot')->assertDispatched('toast', type: 'error');

    expect(m6PageCalls('getMe'))->toHaveCount(MiniApp::CHECKS_PER_MINUTE);
});

it('does not store an invalid username from getMe', function () {
    Http::swap(new Factory);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['username' => '"/../']])]);
    Setting::query()->where('key', BotUsername::KEY)->delete();

    Livewire::actingAs(staffUser())->test(MiniApp::class)
        ->call('checkBot')
        ->assertDispatched('toast', type: 'error', body: __('admin.telegram_mini_app.errors.invalid_username'));

    expect(BotUsername::get())->toBeNull();
});

it('queues a new fan-out generation from "Sync menu buttons"', function () {
    Queue::fake([SyncMiniAppMenus::class]);
    confirmAdminPassword();

    Livewire::actingAs(staffUser())->test(MiniApp::class)
        ->call('syncMenus')
        ->assertDispatched('toast', type: 'success')
        ->assertSee('data-sync-state="queued"', false);

    Queue::assertPushed(SyncMiniAppMenus::class, fn (SyncMiniAppMenus $job): bool => $job->generation === 1);
});

it('disables from the console without a user and shows the state', function () {
    Queue::fake([SyncMiniAppMenus::class]);
    tmaEnabled();

    $this->artisan('telegram:mini-app', ['action' => 'status'])->expectsOutput(__('admin.telegram_mini_app.state_enabled'))->assertSuccessful();
    $this->artisan('telegram:mini-app', ['action' => 'disable'])->expectsOutput(__('admin.telegram_mini_app.flash.disabled'))->assertSuccessful();
    $this->artisan('telegram:mini-app', ['action' => 'enable'])->assertExitCode(2);

    $audit = SettingsAudit::query()->where('key', MiniAppSwitch::KEY)->sole();

    expect(MiniAppSwitch::isEnabled())->toBeFalse()
        ->and($audit->action)->toBe(AuditAction::Disabled)
        ->and($audit->user_id)->toBeNull();

    Queue::assertPushed(SyncMiniAppMenus::class, 1);
});

it('starts the fan-out when the bot switch changes while the Mini App row is on, not while it is off', function () {
    Queue::fake([SyncMiniAppMenus::class]);
    tmaEnabled(miniApp: false);

    app(SetBotEnabled::class)->disable(null, null);
    Queue::assertNotPushed(SyncMiniAppMenus::class);

    tmaEnabled();
    app(SetBotEnabled::class)->disable(null, null);
    Queue::assertPushed(SyncMiniAppMenus::class, 1);

    $this->artisan('telegram:bot', ['action' => 'disable'])->assertSuccessful();
    Queue::assertPushed(SyncMiniAppMenus::class, 1);
});

it('runs the fan-out: getMe, the bot-wide default, then one per-user job per registered, unblocked chat', function () {
    Queue::fake([SyncMiniAppMenuButton::class]);
    $first = tmaUser();
    $second = tmaUser();
    tmaUser(['blocked_at' => now()]);

    $generation = MiniAppMenuSync::start();

    expect(m6PageCalls('getMe'))->toHaveCount(1)
        ->and(collect(m6PageCalls('setChatMenuButton'))->pluck('data')->all())->toBe([['menu_button' => ['type' => 'default']]])
        ->and(MiniAppMenuSync::state())->toMatchArray(['generation' => $generation, 'state' => MiniAppMenuSync::DONE, 'synced' => 2]);

    Queue::assertPushed(SyncMiniAppMenuButton::class, 2);
    Queue::assertPushed(SyncMiniAppMenuButton::class, fn (SyncMiniAppMenuButton $job): bool => in_array($job->telegramUserId, [$first->id, $second->id], true) && ! $job->reset);
});

it('stops a fan-out whose generation is stale', function () {
    Queue::fake([SyncMiniAppMenuButton::class, SyncMiniAppMenus::class]);
    tmaUser();
    MiniAppMenuSync::start();
    MiniAppMenuSync::start();

    (new SyncMiniAppMenus(1))->handle(app(TelegramClient::class));

    expect(telegramApiCalls())->toBe([])
        ->and(MiniAppMenuSync::state()['generation'])->toBe(2);

    Queue::assertNotPushed(SyncMiniAppMenuButton::class);
});

it('marks the fan-out failed without a token', function () {
    Queue::fake([SyncMiniAppMenus::class]);
    $generation = MiniAppMenuSync::start();
    telegramBotConfigured(['services.telegram.bot_token' => null]);

    (new SyncMiniAppMenus($generation))->handle(app(TelegramClient::class));

    expect(MiniAppMenuSync::state()['state'])->toBe(MiniAppMenuSync::FAILED);
});

it('shows one Mini App row on the Telegram status panel', function () {
    $row = fn (): array => collect(app(TelegramStatus::class)->result()['rows'])->firstWhere('id', 'mini_app');

    expect($row()['state'])->toBe(TelegramStatus::NEUTRAL);

    cache()->forget(TelegramStatus::CACHE_KEY);
    tmaEnabled(bot: false);
    expect($row())->toMatchArray(['state' => TelegramStatus::PROBLEM, 'text' => 'bot_off']);

    cache()->forget(TelegramStatus::CACHE_KEY);
    tmaEnabled();
    expect($row())->toMatchArray(['state' => TelegramStatus::OK, 'params' => [], 'link' => 'mini_app']);
});

it('counts real Mini App orders of the last 30 days only', function () {
    Order::factory()->telegram(tmaUser())->count(2)->create();
    Order::factory()->telegram(tmaUser())->create(['is_test' => true]);
    Order::factory()->telegram(tmaUser())->create(['created_at' => now()->subDays(31)]);
    Order::factory()->create();

    $this->actingAs(staffUser())->get(route('admin.telegram.mini-app'))->assertOk()->assertSee('data-orders-30>2<', false);
});

it('filters admin orders by the Mini App source and shows the source badge', function () {
    $tma = Order::factory()->telegram(tmaUser())->create();
    $site = Order::factory()->create(['source' => OrderSource::Site]);

    $this->actingAs(staffUser(Role::SalesManager))
        ->get(route('admin.orders.index', ['source' => 'telegram']))
        ->assertOk()
        ->assertSee('data-order="'.$tma->id.'"', false)
        ->assertDontSee('data-order="'.$site->id.'"', false)
        ->assertSee('data-source-badge="telegram"', false);

    Livewire::actingAs(staffUser())->test(OrdersIndex::class)
        ->set('source', 'site')
        ->assertSee('data-order="'.$site->id.'"', false)
        ->assertDontSee('data-order="'.$tma->id.'"', false);
});

it('links a Mini App order to its Telegram chat for staff who may open the chat', function () {
    $user = tmaUser();
    $order = Order::factory()->telegram($user)->create();

    $this->actingAs(staffUser())->get(route('admin.orders.show', $order))
        ->assertOk()
        ->assertSee('data-source-badge="telegram"', false)
        ->assertSee('data-telegram-chat-link', false)
        ->assertSee(route('admin.telegram.chats.show', $user), false)
        ->assertDontSee((string) $user->telegram_id);

    $this->actingAs(staffUser(Role::SalesManager))->get(route('admin.orders.show', $order))
        ->assertOk()
        ->assertSee('data-telegram-chat-link', false);
});

it('keeps the polished order status text to number and status in every locale', function (string $locale) {
    $text = __('telegram_bot.order_status.text', ['number' => 'O-2026-0001', 'status' => __('enums.order_status.shipped', [], $locale)], $locale);

    expect($text)->toContain('O-2026-0001')
        ->toContain(__('enums.order_status.shipped', [], $locale))
        ->not->toContain(':number')
        ->not->toContain(':status');
})->with(['uz', 'ru', 'en']);

it('refuses an enable while the password confirmation is missing in the action itself', function () {
    expect(fn () => app(SetMiniAppEnabled::class)->enable(staffUser(), null))
        ->toThrow(fn (TelegramBotException $exception) => expect($exception->reason)->toBe('password_required'));

    expect(MiniAppSwitch::isStoredOn())->toBeFalse();
});
