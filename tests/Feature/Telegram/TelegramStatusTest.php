<?php

use App\Enums\Role;
use App\Enums\TelegramForwardStatus;
use App\Enums\TelegramMessageStatus;
use App\Events\Contracts\TelegramAlert;
use App\Events\Telegram\TelegramInboxMessage;
use App\Listeners\SendTelegramAlerts;
use App\Listeners\SendTelegramInboxAlert;
use App\Livewire\Admin\Telegram\Bot;
use App\Livewire\Admin\Telegram\Status;
use App\Models\MonitoringState;
use App\Models\Page;
use App\Models\Setting;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Support\Monitoring\FailedJobs;
use App\Support\Monitoring\Heartbeat;
use App\Support\Telegram\Bot\BotSwitch;
use App\Support\Telegram\Bot\TelegramStatus;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * Plan 21 E2: /admin/telegram/status (telegram.bot, Admin only), T21-7.
 * phpunit.xml forces QUEUE_CONNECTION=sync, so the healthy baseline overrides it.
 */
function statusBaseline(array $snapshot = []): void
{
    telegramBotConfigured();
    config(['queue.default' => 'database']);
    Setting::query()->updateOrCreate(['key' => BotSwitch::KEY], ['group' => 'telegram', 'value' => true]);
    Page::factory()->create(['slug' => 'privacy', 'is_active' => true, 'needs_review' => false]);
    Heartbeat::tick(Heartbeat::SCHEDULER);
    Heartbeat::tick(Heartbeat::WORKER);

    statusSnapshot($snapshot);
}

function statusSnapshot(array $snapshot = []): void
{
    MonitoringState::query()->updateOrCreate(['key' => 'telegram:status'], ['ticked_at' => now(), 'meta' => array_merge([
        'checked_at' => now()->toIso8601String(),
        'webhook_match' => true,
        'last_error_at' => null,
        'pending' => 0,
        'get_me' => 'ok',
        'chat' => 'pass',
        'chat_key' => 'sales',
        'code' => null,
    ], $snapshot)]);
}

function failedTelegramJob(string $displayName = 'App\\Listeners\\SendTelegramAlerts@handle'): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => $displayName]),
        'exception' => 'RuntimeException: MARKER-EXCEPTION-TEXT',
        'failed_at' => now(),
    ]);
}

function statusPage(): Testable
{
    return Livewire::actingAs(staffUser())->test(Status::class);
}

it('is forbidden for every non-Admin role, on load, update and action', function (Role $role) {
    Http::fake();
    $user = staffUser($role);

    $this->actingAs($user)->get(route('admin.telegram.status'))->assertForbidden();
    Livewire::actingAs($user)->test(Status::class)->assertForbidden();
    Http::assertNothingSent();
})->with([
    'content manager' => [Role::ContentManager],
    'sales manager' => [Role::SalesManager],
    'service engineer' => [Role::ServiceEngineer],
]);

it('authorizes every request: a demoted admin is refused on the next one', function () {
    Http::fake();
    statusBaseline();
    $admin = staffUser();
    Livewire::actingAs($admin)->test(Status::class)->assertOk();

    $admin->syncRoles([Role::SalesManager->value]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Livewire::actingAs($admin->fresh())->test(Status::class)->assertForbidden();
    $this->actingAs($admin->fresh())->get(route('admin.telegram.status'))->assertForbidden();
    Http::assertNothingSent();
});

it('shows the page to an admin and sends nothing to Telegram on mount, poll or the full page', function () {
    Http::fake();
    statusBaseline();

    $this->actingAs(staffUser())->get(route('admin.telegram.status'))
        ->assertOk()
        ->assertSee('wire:poll.60s.visible', false)
        ->assertSee('data-action="check-now"', false);

    statusPage()->assertOk()->call('$refresh')->call('$refresh')->assertOk();

    Http::assertNothingSent();
});

/**
 * @return array<string, array{0: string, 1: string, 2: Closure(): void}>
 */
function statusCases(): array
{
    return [
        'bot_switch ok' => ['bot_switch', 'ok', fn () => null],
        'bot_switch off is neutral' => ['bot_switch', 'neutral', fn () => Setting::query()->where('key', BotSwitch::KEY)->delete()],

        'token ok' => ['token', 'ok', fn () => null],
        'token problem (getMe 401)' => ['token', 'problem', fn () => statusSnapshot(['get_me' => 'fail', 'code' => 401])],
        'token problem (open problem)' => ['token', 'problem', fn () => MonitoringState::query()->create(['key' => 'problem:telegram_token', 'problem_since' => now()])],
        'token unknown (no snapshot)' => ['token', 'unknown', fn () => MonitoringState::query()->where('key', 'telegram:status')->delete()],
        'token unknown (snapshot older than 30 min)' => ['token', 'unknown', fn () => statusSnapshot(['checked_at' => now()->subMinutes(31)->toIso8601String()])],
        'token neutral (no token, bot off)' => ['token', 'neutral', function () {
            freshSecrets();
            Setting::query()->where('key', BotSwitch::KEY)->delete();
        }],

        'webhook ok' => ['webhook', 'ok', fn () => null],
        'webhook problem (url differs)' => ['webhook', 'problem', fn () => statusSnapshot(['webhook_match' => false])],
        'webhook problem (backlog)' => ['webhook', 'problem', fn () => statusSnapshot(['pending' => 500])],
        'webhook problem (recent error)' => ['webhook', 'problem', fn () => statusSnapshot(['last_error_at' => now()->subMinutes(2)->toIso8601String()])],
        'webhook unknown' => ['webhook', 'unknown', fn () => MonitoringState::query()->where('key', 'telegram:status')->delete()],

        'chat ok' => ['chat', 'ok', fn () => null],
        'chat problem (refused)' => ['chat', 'problem', fn () => statusSnapshot(['chat' => 'fail'])],
        'chat problem (not a group id)' => ['chat', 'problem', fn () => telegramBotConfigured(['services.telegram.chats.sales' => '@public_channel'])],
        'chat unknown (transient)' => ['chat', 'unknown', fn () => statusSnapshot(['chat' => 'unknown'])],
        'chat neutral (none, bot off)' => ['chat', 'neutral', function () {
            telegramBotConfigured(['services.telegram.chats.sales' => null]);
            Setting::query()->where('key', BotSwitch::KEY)->delete();
        }],

        'management_chat ok' => ['management_chat', 'ok', fn () => null],
        'management_chat problem' => ['management_chat', 'problem', fn () => telegramBotConfigured(['services.telegram.chats.management' => null])],

        'privacy ok' => ['privacy', 'ok', fn () => null],
        'privacy problem (bot on, page fails the gate)' => ['privacy', 'problem', fn () => Page::query()->where('slug', 'privacy')->update(['needs_review' => true])],
        'privacy neutral (bot off, page missing)' => ['privacy', 'neutral', function () {
            Page::query()->where('slug', 'privacy')->delete();
            Setting::query()->where('key', BotSwitch::KEY)->delete();
        }],

        'worker ok' => ['worker', 'ok', fn () => null],
        'worker problem' => ['worker', 'problem', fn () => MonitoringState::query()->where('key', Heartbeat::WORKER)->update(['ticked_at' => now()->subMinutes(10)])],
        'worker unknown (never ticked)' => ['worker', 'unknown', fn () => MonitoringState::query()->where('key', Heartbeat::WORKER)->delete()],
        'worker unknown (not a database queue)' => ['worker', 'unknown', fn () => config(['queue.default' => 'sync'])],

        'cron ok' => ['cron', 'ok', fn () => null],
        'cron problem' => ['cron', 'problem', fn () => MonitoringState::query()->where('key', Heartbeat::SCHEDULER)->update(['ticked_at' => now()->subMinutes(10)])],
        'cron unknown' => ['cron', 'unknown', fn () => MonitoringState::query()->where('key', Heartbeat::SCHEDULER)->delete()],

        'oldest_job ok' => ['oldest_job', 'ok', fn () => null],
        'oldest_job problem' => ['oldest_job', 'problem', fn () => DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->subMinutes(15)->getTimestamp(), 'created_at' => now()->subMinutes(15)->getTimestamp()])],
        'oldest_job unknown (not a database queue)' => ['oldest_job', 'unknown', fn () => config(['queue.default' => 'redis'])],

        'failed_jobs ok' => ['failed_jobs', 'ok', fn () => failedTelegramJob('App\\Jobs\\SomethingElse')],
        'failed_jobs problem (listener)' => ['failed_jobs', 'problem', fn () => failedTelegramJob()],
        'failed_jobs problem (job)' => ['failed_jobs', 'problem', fn () => failedTelegramJob('App\\Jobs\\SendTelegramMessage')],

        'invalid_env ok' => ['invalid_env', 'ok', fn () => null],
        'invalid_env problem' => ['invalid_env', 'problem', fn () => telegramBotConfigured(['services.telegram.notify_inbox' => 'MARKER-maybe'])],

        'inbox_listener ok' => ['inbox_listener', 'ok', fn () => null],
        'inbox_listener problem' => ['inbox_listener', 'problem', fn () => Event::forget(TelegramInboxMessage::class)],

        'queue_connection ok' => ['queue_connection', 'ok', fn () => null],
        'queue_connection problem (sync)' => ['queue_connection', 'problem', fn () => config(['queue.default' => 'sync'])],
    ];
}

it('shows each row in the right state', function (string $row, string $state, Closure $arrange) {
    statusBaseline();
    $arrange();

    statusPage()->assertSee('data-row="'.$row.'" data-state="'.$state.'"', false);
})->with(statusCases());

it('gives every row a label and next step in all three locales, with identical key sets', function (string $locale) {
    statusBaseline(['get_me' => 'fail', 'webhook_match' => false, 'chat' => 'fail']);
    app()->setLocale($locale);

    $rows = app(TelegramStatus::class)->result()['rows'];

    foreach ($rows as $row) {
        expect(trans('telegram_status.rows.'.$row['id'].'.label', [], $locale))->not->toStartWith('telegram_status.');

        if ($row['next'] !== null) {
            expect(trans($row['next'], [], $locale))->not->toBe($row['next']);
        }
    }

    foreach (['en', 'ru', 'uz'] as $other) {
        expect(array_keys(Arr::dot(trans('telegram_status', [], $other))))->toBe(array_keys(Arr::dot(trans('telegram_status', [], 'en'))));
    }
})->with(['en', 'ru', 'uz']);

it('checks the inbox listener against the installed framework: removed inbox listener, SendTelegramAlerts stays', function () {
    statusBaseline();

    $raw = Event::getRawListeners();
    expect(collect($raw[TelegramInboxMessage::class])->implode(','))->toContain(SendTelegramInboxAlert::class)
        ->and(collect($raw[TelegramAlert::class])->implode(','))->toContain(SendTelegramAlerts::class);

    Event::forget(TelegramInboxMessage::class);

    $raw = Event::getRawListeners();
    expect($raw[TelegramInboxMessage::class] ?? [])->toBe([])
        ->and(collect($raw[TelegramAlert::class])->implode(','))->toContain(SendTelegramAlerts::class);

    statusPage()->assertSee('data-row="inbox_listener" data-state="problem"', false);
});

it('reads the queue connection from config, not from phpunit.xml', function () {
    statusBaseline();
    expect(config('queue.default'))->toBe('database');
    statusPage()->assertSee('data-row="queue_connection" data-state="ok"', false);

    Cache::flush();
    config(['queue.default' => 'sync']);
    statusPage()->assertSee('data-row="queue_connection" data-state="problem"', false);
});

it('counts failed Telegram jobs by a fixed class list only', function () {
    failedTelegramJob('App\\Listeners\\SendTelegramInboxAlert@handle');
    failedTelegramJob('App\\Jobs\\ForwardTelegramMedia');
    failedTelegramJob('App\\Jobs\\SendTelegramMessageExtra');
    failedTelegramJob('App\\Jobs\\Other');

    expect(FailedJobs::countForClasses(TelegramStatus::FAILED_JOB_CLASSES))->toBe(2)
        ->and(FailedJobs::countForClasses([]))->toBe(0);
});

it('never renders a marker name, phone or message text, a chat id, job error text or the token', function () {
    statusBaseline();
    $user = TelegramUser::factory()->create(['name' => 'MARKERNAME Ivanov', 'phone' => '+998901112233', 'state' => 'registered', 'unread_count' => 3, 'first_name' => 'MARKERFIRST', 'username' => 'markeruser']);
    TelegramMessage::factory()->for($user)->create(['text' => 'MARKER message text', 'status' => TelegramMessageStatus::Sending, 'forward_status' => TelegramForwardStatus::Failed, 'updated_at' => now()->subMinutes(30)]);
    failedTelegramJob();

    $html = $this->actingAs(staffUser())->get(route('admin.telegram.status'))->assertOk()->getContent();

    foreach (['MARKER', '998901112233', 'markeruser', '-1001111111111', TG_MANAGEMENT_CHAT, TG_BOT_TOKEN, TG_WEBHOOK_SECRET, 'poson.example.uz/api'] as $secret) {
        expect($html)->not->toContain($secret);
    }

    expect($html)->toContain('data-count="stuck">1<')->toContain('data-count="failed_forwards">1<')->toContain('data-count="unread">3<')->toContain('data-count="registered">1<');
});

it('names the invalid env keys but never their values', function () {
    statusBaseline();
    telegramBotConfigured(['services.telegram.bot_token' => 'MARKER-not-a-token']);

    $html = $this->actingAs(staffUser())->get(route('admin.telegram.status'))->getContent();

    expect($html)->toContain('TELEGRAM_BOT_TOKEN')->not->toContain('MARKER');
});

it('keeps queries bounded: cold render under the ceiling, warm poll almost free', function () {
    Http::fake();
    statusBaseline();
    $component = Livewire::actingAs(staffUser())->test(Status::class);
    Cache::flush();

    DB::enableQueryLog();
    $component->call('$refresh');
    $cold = count(DB::getQueryLog());

    DB::flushQueryLog();
    $component->call('$refresh');
    $warm = count(DB::getQueryLog());

    expect($cold)->toBeLessThanOrEqual(20)
        ->and($warm)->toBeLessThanOrEqual(2)
        ->and($warm)->toBeLessThan($cold);
    Http::assertNothingSent();
});

it('caches the result about 55 seconds', function () {
    statusBaseline();
    statusPage()->assertSee('data-row="inbox_listener" data-state="ok"', false);

    Event::forget(TelegramInboxMessage::class);
    statusPage()->assertSee('data-row="inbox_listener" data-state="ok"', false);

    $this->travel(56)->seconds();
    statusPage()->assertSee('data-row="inbox_listener" data-state="problem"', false);
});

describe('check now', function () {
    beforeEach(fn () => statusBaseline());

    function checkNowTelegram(): void
    {
        Http::fake(['api.telegram.org/*' => function ($request) {
            return match (basename(parse_url($request->url(), PHP_URL_PATH))) {
                'getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => 'https://poson.example.uz/api/telegram/webhook', 'pending_update_count' => 0]]),
                'getChat' => Http::response(['ok' => true, 'result' => ['id' => -1001111111111, 'type' => 'supergroup', 'title' => 'MARKER-TITLE']]),
                default => Http::response(['ok' => true, 'result' => true]),
            };
        }]);
    }

    it('runs the check, stores a safe verdict and refuses a second call right away', function () {
        checkNowTelegram();
        MonitoringState::query()->where('key', 'telegram:status')->delete();
        $component = statusPage();

        $component->call('checkNow')->assertDispatched('toast', type: 'success');
        $calls = collect(telegramApiCalls())->pluck('method')->all();

        expect($calls)->toBe(['getWebhookInfo', 'getChat'])
            ->and(MonitoringState::query()->find('telegram:status')->meta['chat'])->toBe('pass')
            ->and(json_encode(MonitoringState::query()->find('telegram:status')->meta))->not->toContain('MARKER');

        $component->call('checkNow')->assertDispatched('toast', type: 'error');
        expect(count(telegramApiCalls()))->toBe(2);
    });

    it('does not run while another check holds the lock', function () {
        checkNowTelegram();
        $lock = Cache::lock(TelegramStatus::LOCK_KEY, 30);
        $lock->get();

        statusPage()->call('checkNow')->assertDispatched('toast', type: 'error');

        expect(telegramApiCalls())->toBe([]);
        $lock->release();
    });

    it('releases the lock and reports unknown when Telegram fails', function () {
        Http::fake(['api.telegram.org/*' => Http::response('', 502)]);

        statusPage()->call('checkNow')->assertDispatched('toast', type: 'success');

        expect(Cache::lock(TelegramStatus::LOCK_KEY, 5)->get())->toBeTrue()
            ->and(MonitoringState::query()->find('telegram:status')->meta)->get_me->toBe('unknown')->chat->toBe('unknown');
    });
});

it('puts a summary line on the bot page that links to the panel', function () {
    statusBaseline(['get_me' => 'fail']);

    Livewire::actingAs(staffUser())->test(Bot::class)
        ->assertSee('data-status-summary="problems"', false)
        ->assertSee(route('admin.telegram.status'), false);
});

it('hides the Settings and Monitoring links from users without the permission, and the page works without the settings route', function () {
    statusBaseline(['get_me' => 'fail']);
    $html = $this->actingAs(staffUser())->get(route('admin.telegram.status'))->getContent();

    expect($html)->toContain('data-link="settings"');
    expect(Route::has('admin.telegram.settings'))->toBeFalse();
});
