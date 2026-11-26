<?php

use App\Actions\Telegram\RequestMiniAppMenuSync;
use App\Actions\Telegram\SetBotEnabled;
use App\Actions\Telegram\SetMiniAppEnabled;
use App\Actions\Telegram\TelegramBotException;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Events\Orders\OrderPlaced;
use App\Jobs\SyncMiniAppMenuButton;
use App\Jobs\SyncMiniAppMenus;
use App\Livewire\Admin\Orders\Index as OrdersIndex;
use App\Livewire\Admin\Telegram\MiniApp;
use App\Models\Order;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\TelegramUser;
use App\Models\User;
use App\Rules\BrandSafeText;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Telegram\MiniApp\BotUsername;
use App\Support\Telegram\MiniApp\MiniAppMenuSync;
use App\Support\Telegram\MiniApp\MiniAppSwitch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\UniqueLock;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Attributes\Locked;
use Livewire\Livewire;

require_once __DIR__.'/../../Helpers/MiniApp.php';

/*
| Plan 20 M6 security sign-off (§22): abuse cases 1-14, one test (or
| dataset) each. In-process, test DB only, fake token, Http::fake only.
*/
beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['id' => 4242, 'is_bot' => true, 'username' => 'poson_test_bot']])]);
    miniAppReady();
});

/**
 * Per-chat menu buttons Telegram received: chat id => last button type.
 *
 * @return array<string, string>
 */
function m6FinalButtons(): array
{
    $buttons = [];

    foreach (telegramApiCalls() as $call) {
        if ($call['method'] === 'setChatMenuButton' && isset($call['data']['chat_id'])) {
            $buttons[(string) $call['data']['chat_id']] = $call['data']['menu_button']['type'];
        }
    }

    return $buttons;
}

/**
 * Run every queued fan-out step and per-user job (Queue::fake), oldest first, as a worker would.
 */
function m6RunQueued(): void
{
    $client = app(TelegramClient::class);
    $done = [];

    do {
        $ran = false;

        foreach ([SyncMiniAppMenus::class, SyncMiniAppMenuButton::class] as $class) {
            foreach (Queue::pushed($class) as $job) {
                $id = spl_object_id($job);

                if (! isset($done[$id])) {
                    $done[$id] = true;
                    $job->handle($client);
                    $ran = true;
                }
            }
        }
    } while ($ran);
}

function m6Admin(): User
{
    confirmAdminPassword();

    return staffUser();
}

// 1
it('forbids non-Admin roles the page, enable / disable and sync: no row, no audit, no job', function (Role $role) {
    Queue::fake();
    Setting::query()->where('key', MiniAppSwitch::KEY)->delete();
    confirmAdminPassword();
    $user = staffUser($role);

    $this->actingAs($user)->get(route('admin.telegram.mini-app'))->assertForbidden();
    Livewire::actingAs($user)->test(MiniApp::class)->assertForbidden();

    expect(fn () => app(SetMiniAppEnabled::class)->enable($user, '10.0.0.1'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SetMiniAppEnabled::class)->disable($user, '10.0.0.1'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RequestMiniAppMenuSync::class)->handle($user, '10.0.0.1'))->toThrow(AuthorizationException::class)
        ->and(Setting::query()->where('key', MiniAppSwitch::KEY)->exists())->toBeFalse()
        ->and(SettingsAudit::query()->exists())->toBeFalse()
        ->and(MiniAppMenuSync::current())->toBe(0);

    Queue::assertNothingPushed();
})->with([
    'content manager' => Role::ContentManager,
    'sales manager' => Role::SalesManager,
    'service engineer' => Role::ServiceEngineer,
]);

// 2
it('writes nothing without a recent password and redirects to the confirmation', function (?int $secondsAgo) {
    Queue::fake();

    if ($secondsAgo !== null) {
        confirmAdminPassword($secondsAgo);
    }

    $admin = staffUser();

    foreach (['enable', 'syncMenus'] as $method) {
        Livewire::actingAs($admin)->test(MiniApp::class)->call($method)->assertRedirect(route('admin.confirm-password'));
    }

    tmaEnabled();
    Livewire::actingAs($admin)->test(MiniApp::class)->call('disable')->assertRedirect(route('admin.confirm-password'));

    expect(MiniAppSwitch::isEnabled())->toBeTrue()
        ->and(SettingsAudit::query()->where('key', MiniAppSwitch::KEY)->exists())->toBeFalse()
        ->and(MiniAppMenuSync::current())->toBe(0)
        ->and(fn () => app(SetMiniAppEnabled::class)->disable($admin, null))->toThrow(TelegramBotException::class);

    Queue::assertNothingPushed();
})->with([
    'never confirmed' => null,
    'expired' => 16 * 60,
    'in the future' => -600,
]);

// 3
it('refuses a failing checklist on the server whatever the client claims', function () {
    Queue::fake();
    $admin = m6Admin();
    Setting::query()->where('key', BotUsername::KEY)->delete();

    $component = Livewire::actingAs($admin)->test(MiniApp::class);

    // The only public state is the display checklist, and it is locked: the client cannot forge it.
    $public = array_values(array_map(fn (ReflectionProperty $property): string => $property->getName(), array_filter((new ReflectionClass(MiniApp::class))->getProperties(ReflectionProperty::IS_PUBLIC), fn (ReflectionProperty $property): bool => $property->class === MiniApp::class)));
    expect($public)->toBe(['checklist'])
        ->and((new ReflectionProperty(MiniApp::class, 'checklist'))->getAttributes(Locked::class))->toHaveCount(1)
        ->and(fn () => $component->set('checklist.username', true))->toThrow(Exception::class)
        ->and(fn () => $component->set('ready', true))->toThrow(Exception::class);

    Livewire::actingAs($admin)->test(MiniApp::class)
        ->call('enable')
        ->assertDispatched('toast', type: 'error', body: __('admin.telegram_bot.errors.mini_app_not_ready'));

    expect(fn () => app(SetMiniAppEnabled::class)->enable($admin, null))
        ->toThrow(fn (TelegramBotException $exception) => expect($exception->failing)->toBe(['username']));

    expect(MiniAppSwitch::isStoredOn())->toBeFalse();
    Queue::assertNothingPushed();
});

// 4
it('stops the stale "on" fan-out after a fast on → off: nobody ends with a web_app button', function () {
    Queue::fake([SyncMiniAppMenus::class, SyncMiniAppMenuButton::class]);
    $admin = m6Admin();
    $users = collect(range(1, 4))->map(fn () => tmaUser());

    app(SetMiniAppEnabled::class)->enable($admin, null);
    // The "on" fan-out already queued its per-user jobs before the switch went off …
    Queue::pushed(SyncMiniAppMenus::class)->first()->handle(app(TelegramClient::class));
    app(SetMiniAppEnabled::class)->disable($admin, null);

    // … and a second "on" step (generation 1) would now stop at once.
    Queue::fake([SyncMiniAppMenus::class, SyncMiniAppMenuButton::class]);
    (new SyncMiniAppMenus(1, $users->last()->id))->handle(app(TelegramClient::class));
    Queue::assertNothingPushed();

    Queue::fake([SyncMiniAppMenus::class, SyncMiniAppMenuButton::class]);
    (new SyncMiniAppMenus(2))->handle(app(TelegramClient::class));
    m6RunQueued();
    // The per-user jobs of generation 1 run late: they compute the button now.
    foreach ($users as $user) {
        (new SyncMiniAppMenuButton($user->id))->handle(app(TelegramClient::class));
    }

    $buttons = m6FinalButtons();

    expect($buttons)->toHaveCount(4)
        ->and(array_unique(array_values($buttons)))->toBe(['default']);
});

// 5
it('leaves nobody with the default button after a fast off → on', function () {
    Queue::fake([SyncMiniAppMenus::class, SyncMiniAppMenuButton::class]);
    tmaEnabled();
    $admin = m6Admin();
    $users = collect(range(1, 4))->map(fn () => tmaUser());

    app(SetMiniAppEnabled::class)->disable($admin, null);
    Queue::pushed(SyncMiniAppMenus::class)->first()->handle(app(TelegramClient::class));
    $offJobs = Queue::pushed(SyncMiniAppMenuButton::class)->all();
    app(SetMiniAppEnabled::class)->enable($admin, null);

    Queue::fake([SyncMiniAppMenus::class, SyncMiniAppMenuButton::class]);
    (new SyncMiniAppMenus(2))->handle(app(TelegramClient::class));
    m6RunQueued();

    // The "off" per-user jobs run last: they carry no flag and compute the button now.
    foreach ($offJobs as $job) {
        $job->handle(app(TelegramClient::class));
    }

    $buttons = m6FinalButtons();

    expect($offJobs)->toHaveCount(4)
        ->and($buttons)->toHaveCount(4)
        ->and(array_unique(array_values($buttons)))->toBe(['web_app']);
});

// 6
it('queues the reset fan-out when the bot goes off while the Mini App row is on', function () {
    Queue::fake([SyncMiniAppMenus::class, SyncMiniAppMenuButton::class]);
    tmaEnabled();
    $user = tmaUser();

    app(SetBotEnabled::class)->disable(m6Admin(), null);

    Queue::assertPushed(SyncMiniAppMenus::class, 1);
    m6RunQueued();

    expect(MiniAppSwitch::isStoredOn())->toBeTrue()
        ->and(MiniAppSwitch::isEnabled())->toBeFalse()
        ->and(m6FinalButtons())->toBe([(string) $user->telegram_id => 'default']);
});

// 7
it('disables while the Telegram API answers 5xx: still off and audited', function () {
    Queue::fake([SyncMiniAppMenus::class]);
    tmaEnabled();
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 502], 502)]);
    $admin = m6Admin();

    Livewire::actingAs($admin)->test(MiniApp::class)
        ->call('disable')
        ->assertDispatched('toast', type: 'success');

    expect(MiniAppSwitch::isEnabled())->toBeFalse()
        ->and(SettingsAudit::query()->where('key', MiniAppSwitch::KEY)->sole()->action)->toBe(AuditAction::Disabled)
        ->and(telegramApiCalls())->toBe([]);

    // The queued reset fan-out retries later (5xx is not permanent); the switch stays off.
    expect(fn () => Queue::pushed(SyncMiniAppMenus::class)->first()->handle(app(TelegramClient::class)))->toThrow(TelegramException::class)
        ->and(MiniAppSwitch::isStoredOn())->toBeFalse();
});

// 8
it('refuses the 4th enable or sync within 10 minutes, but never a disable', function () {
    Queue::fake([SyncMiniAppMenus::class]);
    $admin = m6Admin();
    $component = Livewire::actingAs($admin)->test(MiniApp::class);
    $limited = __('admin.telegram_mini_app.errors.too_many_toggles', ['minutes' => 10]);

    // enable, sync, enable use the 3 slots; the disables in between use none.
    $component->call('enable')->call('syncMenus')->call('disable')->call('enable');
    expect(MiniAppSwitch::isStoredOn())->toBeTrue();

    $component->call('syncMenus')->assertDispatched('toast', type: 'error', body: $limited);
    $component->call('disable')->assertDispatched('toast', type: 'success', body: __('admin.telegram_mini_app.flash.disabled'));
    $component->call('enable')->assertDispatched('toast', type: 'error', body: $limited);
    $component->call('disable')->call('disable')->call('disable');

    expect(MiniAppSwitch::isStoredOn())->toBeFalse()
        ->and(SettingsAudit::query()->where('key', MiniAppSwitch::KEY)->pluck('action')->map->value->all())->toBe(['enabled', 'disabled', 'enabled', 'disabled'])
        ->and(SettingsAudit::query()->where('key', MiniAppMenuSync::STATE_KEY)->count())->toBe(1);

    Queue::assertPushed(SyncMiniAppMenus::class, 5);

    $this->travel(11)->minutes();
    confirmAdminPassword();
    Livewire::actingAs($admin)->test(MiniApp::class)->call('enable');

    expect(MiniAppSwitch::isStoredOn())->toBeTrue();
});

it('lets the kill switch through after enable → disable → enable → sync used the limit: one fan-out, then unchanged', function () {
    Queue::fake([SyncMiniAppMenus::class]);
    $component = Livewire::actingAs(m6Admin())->test(MiniApp::class);

    $component->call('enable')->call('disable')->call('enable')->call('syncMenus');
    $component->call('syncMenus')->assertDispatched('toast', type: 'error');
    $before = Queue::pushed(SyncMiniAppMenus::class)->count();

    $component->call('disable')->assertDispatched('toast', type: 'success', body: __('admin.telegram_mini_app.flash.disabled'));
    expect(MiniAppSwitch::isStoredOn())->toBeFalse()
        ->and(Queue::pushed(SyncMiniAppMenus::class)->count())->toBe($before + 1)
        ->and(Queue::pushed(SyncMiniAppMenus::class)->last()->generation)->toBe(MiniAppMenuSync::current());

    $component->call('disable')->assertDispatched('toast', type: 'success', body: __('admin.telegram_mini_app.flash.unchanged'));
    expect(Queue::pushed(SyncMiniAppMenus::class)->count())->toBe($before + 1);
});

it('gives the slot back when an enable is refused by the checklist', function () {
    Queue::fake([SyncMiniAppMenus::class]);
    $admin = m6Admin();
    Setting::query()->where('key', BotUsername::KEY)->delete();
    $component = Livewire::actingAs($admin)->test(MiniApp::class);

    foreach (range(1, MiniApp::TOGGLES_PER_WINDOW + 2) as $ignored) {
        $component->call('enable')->assertDispatched('toast', body: __('admin.telegram_bot.errors.mini_app_not_ready'));
    }

    BotUsername::remember('poson_test_bot');
    $component->call('enable')->assertDispatched('toast', type: 'success');

    expect(MiniAppSwitch::isStoredOn())->toBeTrue();
});

// 9
it('queues at most 100 menu jobs at once for 1000 users and an order alert is not stuck behind them', function () {
    $this->freezeTime();
    config(['queue.default' => 'database']);
    $now = now();
    TelegramUser::query()->insert(collect(range(1, 1000))->map(fn (int $i): array => TelegramUser::factory()->registered()->raw([
        'telegram_id' => 7_000_000 + $i, 'created_at' => $now, 'updated_at' => $now,
    ]))->map(fn (array $row): array => array_map(fn ($value) => $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : (is_array($value) ? json_encode($value) : $value), $row))->all());

    $generation = MiniAppMenuSync::start();
    // As a worker would: take the queued fan-out off the queue (its unique lock is released when processing starts).
    DB::table('jobs')->delete();
    $job = new SyncMiniAppMenus($generation);
    (new UniqueLock(Cache::driver()))->release($job);

    $job->handle(app(TelegramClient::class));
    event(new OrderPlaced(Order::factory()->create()));

    $jobs = DB::table('jobs')->orderBy('id')->get()->map(fn ($row): array => [
        'name' => json_decode($row->payload, true)['displayName'],
        'due' => $row->available_at <= now()->getTimestamp(),
        'delay' => $row->available_at - now()->getTimestamp(),
    ]);
    $menu = $jobs->where('name', SyncMiniAppMenuButton::class);
    $next = $jobs->where('name', SyncMiniAppMenus::class)->sole();
    $dueBeforeAlert = $jobs->takeUntil(fn (array $job): bool => $job['name'] !== SyncMiniAppMenuButton::class && $job['name'] !== SyncMiniAppMenus::class)->where('due', true);

    expect($menu)->toHaveCount(SyncMiniAppMenus::CHUNK)
        ->and($menu->where('due', true))->toHaveCount(SyncMiniAppMenus::PER_SECOND)
        ->and($menu->max('delay'))->toBeLessThan(intdiv(SyncMiniAppMenus::CHUNK, SyncMiniAppMenus::PER_SECOND))
        ->and($next['delay'])->toBeGreaterThanOrEqual(intdiv(SyncMiniAppMenus::CHUNK, SyncMiniAppMenus::PER_SECOND))
        ->and($jobs->where('name', '!=', SyncMiniAppMenuButton::class)->where('name', '!=', SyncMiniAppMenus::class)->where('due', true))->not->toBeEmpty()
        ->and($dueBeforeAlert->count())->toBeLessThanOrEqual(SyncMiniAppMenus::PER_SECOND)
        ->and(DB::table('jobs')->pluck('queue')->unique()->values()->all())->toBe(['default']);
});

// 10
it('queues no job (and holds no unique lock) when the toggle transaction rolls back', function () {
    Queue::fake([SyncMiniAppMenus::class]);
    $admin = m6Admin();

    try {
        DB::transaction(function () use ($admin): void {
            app(SetMiniAppEnabled::class)->enable($admin, null);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    Queue::assertNothingPushed();
    expect(MiniAppSwitch::isStoredOn())->toBeFalse()
        ->and(MiniAppMenuSync::current())->toBe(0)
        ->and(SettingsAudit::query()->where('key', MiniAppSwitch::KEY)->exists())->toBeFalse();

    // The same generation number is used again by the next real toggle, and is not blocked.
    app(SetMiniAppEnabled::class)->enable($admin, null);
    Queue::assertPushed(SyncMiniAppMenus::class, fn (SyncMiniAppMenus $job): bool => $job->generation === 1);
});

// 11
it('ignores an invalid or array source filter without a TypeError', function (array $query) {
    $tma = Order::factory()->telegram(tmaUser())->create();
    $site = Order::factory()->create();

    $this->actingAs(staffUser())->get(route('admin.orders.index', $query))
        ->assertOk()
        ->assertSee('data-order="'.$tma->id.'"', false)
        ->assertSee('data-order="'.$site->id.'"', false);
})->with([
    'unknown value' => [['source' => 'foo']],
    'array' => [['source' => ['telegram']]],
    'empty array' => [['source' => []]],
    'case variant' => [['source' => 'Telegram']],
]);

it('ignores an unknown source set through Livewire too', function () {
    $tma = Order::factory()->telegram(tmaUser())->create();
    $site = Order::factory()->create();

    Livewire::actingAs(staffUser())->test(OrdersIndex::class)
        ->set('source', 'foo')
        ->assertSee('data-order="'.$tma->id.'"', false)
        ->assertSee('data-order="'.$site->id.'"', false);
});

// 12
it('shows no chat link to a role without TelegramUserPolicy access and the chat URL stays 403', function (Closure $setup) {
    [$viewer, $telegramUser] = $setup();
    $order = Order::factory()->telegram($telegramUser)->create();

    $this->actingAs($viewer)->get(route('admin.orders.show', $order))
        ->assertOk()
        ->assertSee('data-source-badge="telegram"', false)
        ->assertDontSee('data-telegram-chat-link', false)
        ->assertDontSee(route('admin.telegram.chats.show', $telegramUser), false);

    $this->actingAs($viewer)->get(route('admin.telegram.chats.show', $telegramUser))->assertForbidden();
})->with([
    'service engineer (no inbox)' => [fn (): array => [staffUser(Role::ServiceEngineer), tmaUser()]],
    'sales manager, chat of another manager' => [fn (): array => [staffUser(Role::SalesManager), tmaUser(['manager_id' => User::factory()->withRole(Role::SalesManager)->create()->id])]],
]);

// 13
it('keeps values and secrets out of the audit row and tokens and Telegram ids out of the page', function () {
    Queue::fake([SyncMiniAppMenus::class]);
    $admin = m6Admin();
    $user = tmaUser(['telegram_id' => 5559913]);
    Order::factory()->telegram($user)->create();

    $component = Livewire::actingAs($admin)->test(MiniApp::class)->call('checkBot')->call('enable');

    $audit = SettingsAudit::query()->where('key', MiniAppSwitch::KEY)->sole();
    $html = $component->html().$this->actingAs($admin)->get(route('admin.telegram.mini-app'))->assertOk()->getContent();

    expect($audit->old_text)->toBeNull()
        ->and($audit->new_text)->toBeNull()
        ->and(json_encode($audit->getAttributes()))->not->toContain('true')
        ->and(json_encode($audit->getAttributes()))->not->toContain(TG_BOT_TOKEN)
        ->and($html)->not->toContain(TG_BOT_TOKEN)
        ->and($html)->not->toContain(TG_WEBHOOK_SECRET)
        ->and($html)->not->toContain('5559913')
        ->and($html)->not->toContain('4242')
        ->and($html)->not->toContain('is_bot')
        ->and(json_encode(MiniAppMenuSync::state()))->not->toContain('5559913');
});

// 14
it('fails the brand rule on a forbidden word in any new M6 key, and every new key passes', function () {
    $keys = static function (string $locale): array {
        app()->setLocale($locale);

        return array_merge(
            Arr::dot(__('admin.telegram_mini_app'), 'admin.telegram_mini_app.'),
            Arr::dot(__('telegram_status.rows.mini_app'), 'telegram_status.rows.mini_app.'),
            Arr::dot(__('telegram_bot.order_status'), 'telegram_bot.order_status.'),
            [
                'telegram_status.links.mini_app' => __('telegram_status.links.mini_app'),
                'orders.telegram_chat' => __('orders.telegram_chat'),
                'admin.telegram_bot.errors.mini_app_not_ready' => __('admin.telegram_bot.errors.mini_app_not_ready'),
                'admin.telegram_bot.errors.password_required' => __('admin.telegram_bot.errors.password_required'),
            ],
        );
    };

    $english = array_keys($keys('en'));

    foreach (['uz', 'ru', 'en'] as $locale) {
        $strings = $keys($locale);

        expect(array_keys($strings))->toBe($english, "{$locale}: same keys as en");

        foreach ($strings as $key => $value) {
            expect($value)->not->toBe($key, "{$locale}.{$key} missing")
                ->and(BrandSafeText::violates((string) $value))->toBeFalse("{$locale}.{$key}")
                ->and(preg_match('/\b(payme|click)\b/iu', (string) $value))->toBe(0, "{$locale}.{$key}");

            if ($locale === 'uz') {
                expect(preg_match("/[a-z]['‘’][a-z]/iu", (string) $value))->toBe(0, "uz.{$key}: use ʻ / ʼ");
            }

            // The same check catches a forbidden word slipped into any of these keys.
            foreach (['chegirma', 'скидки', 'cheapest'] as $poison) {
                expect(BrandSafeText::violates($value.' '.$poison))->toBeTrue("{$locale}.{$key} + {$poison}");
            }
        }
    }
});
