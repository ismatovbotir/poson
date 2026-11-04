<?php

use App\Actions\Settings\ClearSecret;
use App\Actions\Settings\SaveIntegrationSettings;
use App\Actions\Settings\SetSecret;
use App\Actions\Telegram\CheckTelegramWebhook;
use App\Actions\Telegram\SetBotEnabled;
use App\Actions\Telegram\TelegramBotException;
use App\Livewire\Admin\Telegram\Status;
use App\Models\IntegrationSecret;
use App\Models\MonitoringState;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Support\Monitoring\Heartbeat;
use App\Support\Telegram\Bot\BotChecklist;
use App\Support\Telegram\Bot\BotSwitch;
use App\Support\Telegram\Bot\InboxChat;
use App\Support\Telegram\Bot\TelegramStatus;
use Illuminate\Cache\ArrayStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Plan 21 status panel, abuse cases (security-reviewer T21-5, 6, 7, 22, 27, 28).
 * Happy paths live in tests/Feature/Telegram/TelegramStatusTest.php and
 * TelegramCheckWebhookTest.php; here only the hostile and failure angles.
 * Bot API faked (fake token), test DB only.
 *
 * Not repeated here, already covered:
 *  - case 8 (SaveIntegrationSettings chat / SetSecret / ClearSecret clear all
 *    verdicts incl. webhook_match, a Payme key clears nothing): TelegramStatusTest
 *    "clears the verdicts after a committed token or chat change, and for nothing else".
 *  - case 12 (Content manager + custom telegram.bot role: no settings / monitoring
 *    links): TelegramStatusTest "shows a user with only telegram.bot no Settings or Monitoring links".
 */
const TSA_URL = 'https://poson.example.uz/api/telegram/webhook';

const TSA_SALES_CHAT = '-1001111111111';

const TSA_SNAPSHOT_KEYS = ['checked_at', 'webhook_match', 'last_error_at', 'pending', 'get_me', 'chat', 'chat_key', 'code'];

/**
 * Per-test answers of the fake Bot API (the first Http::fake() wins, so tests swap this holder).
 * Each value is a Response or a Closure returning one; null means the healthy default.
 *
 * @param  array<string, mixed>|null  $set  merged into the holder; null reads it
 * @return array<string, mixed>
 */
function tsaAnswers(?array $set = null, bool $reset = false): array
{
    static $answers = [];

    if ($reset) {
        $answers = [];
    }

    if ($set !== null) {
        $answers = [...$answers, ...$set];
    }

    return $answers;
}

function tsaRespond(string $method): mixed
{
    $answer = tsaAnswers()[$method] ?? null;
    $answer = $answer instanceof Closure ? $answer() : $answer;

    if ($answer !== null) {
        return $answer;
    }

    return match ($method) {
        'getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => TSA_URL, 'pending_update_count' => 0]]),
        'getChat' => Http::response(['ok' => true, 'result' => ['id' => -1001111111111, 'type' => 'supergroup']]),
        default => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    };
}

function tsaBadWebhook(): mixed
{
    return Http::response(['ok' => true, 'result' => ['url' => 'https://attacker.example/hook', 'pending_update_count' => 0]]);
}

function tsaApiError(int $status): mixed
{
    return Http::response(['ok' => false, 'error_code' => $status, 'description' => 'MARKER-API-ERROR'], $status);
}

/**
 * Bot on, bot configured, healthy surroundings; optionally a stored snapshot.
 *
 * @param  array<string, mixed>|null  $snapshot  meta overrides; null stores no snapshot
 */
function tsaBaseline(?array $snapshot = null): void
{
    telegramBotConfigured();
    config(['queue.default' => 'database']);
    Setting::query()->updateOrCreate(['key' => BotSwitch::KEY], ['group' => 'telegram', 'value' => true]);
    Page::factory()->create(['slug' => 'privacy', 'is_active' => true, 'needs_review' => false]);
    Heartbeat::tick(Heartbeat::SCHEDULER);
    Heartbeat::tick(Heartbeat::WORKER);

    if ($snapshot !== null) {
        tsaSnapshot($snapshot);
    }
}

/**
 * @param  array<string, mixed>  $meta
 */
function tsaSnapshot(array $meta = []): void
{
    MonitoringState::query()->updateOrCreate(['key' => CheckTelegramWebhook::SNAPSHOT_KEY], ['ticked_at' => now(), 'meta' => [
        'checked_at' => now()->toIso8601String(),
        'webhook_match' => true,
        'last_error_at' => null,
        'pending' => 0,
        'get_me' => 'ok',
        'chat' => 'pass',
        'chat_key' => 'sales',
        'code' => null,
        ...$meta,
    ]]);
}

/**
 * @return array<string, mixed>
 */
function tsaMeta(): array
{
    return MonitoringState::query()->find(CheckTelegramWebhook::SNAPSHOT_KEY)->meta;
}

function tsaPage(?User $user = null): Testable
{
    return Livewire::actingAs($user ?? staffUser())->test(Status::class);
}

/**
 * The texts sent to the management chat.
 *
 * @return list<string>
 */
function tsaAlerts(): array
{
    return collect(telegramApiCalls())
        ->filter(fn (array $call): bool => $call['method'] === 'sendMessage' && ($call['data']['chat_id'] ?? null) === TG_MANAGEMENT_CHAT)
        ->map(fn (array $call): string => (string) $call['data']['text'])
        ->values()->all();
}

/**
 * @return list<string>
 */
function tsaMethods(): array
{
    return collect(telegramApiCalls())->pluck('method')->all();
}

function tsaWebhookProblemOpen(): bool
{
    return MonitoringState::query()->find(CheckTelegramWebhook::WEBHOOK_KEY)?->problem_since !== null;
}

function tsaRow(string $id): array
{
    return collect(app(TelegramStatus::class)->result()['rows'])->firstWhere('id', $id);
}

beforeEach(function () {
    tsaAnswers([], reset: true);

    Http::fake(['api.telegram.org/*' => fn ($request) => tsaRespond(basename(parse_url($request->url(), PHP_URL_PATH)))]);
});

describe('alerts through check-now', function () {
    beforeEach(fn () => tsaBaseline());

    it('1: sends exactly one management message for a persistent webhook problem over five check-nows', function () {
        tsaAnswers(['getWebhookInfo' => fn () => tsaBadWebhook()]);
        $page = tsaPage();

        foreach (range(1, 5) as $ignored) {
            $page->call('checkNow')->assertDispatched('toast', type: 'success');
            $this->travel(21)->seconds();
        }

        expect(tsaAlerts())->toHaveCount(1)
            ->and(tsaWebhookProblemOpen())->toBeTrue()
            ->and(count(array_filter(tsaMethods(), fn (string $method): bool => $method === 'getWebhookInfo')))->toBe(5);
    });

    it('2: alerts once per real transition and never on a limited or busy check-now', function () {
        $page = tsaPage();

        // Healthy -> problem: one alert.
        tsaAnswers(['getWebhookInfo' => fn () => tsaBadWebhook()]);
        $page->call('checkNow')->assertDispatched('toast', type: 'success');
        expect(tsaAlerts())->toHaveCount(1);

        // Problem -> healthy, but the check-now is limited: nothing sent, nothing called.
        tsaAnswers(['getWebhookInfo' => null]);
        $calls = count(telegramApiCalls());
        $page->call('checkNow')->assertDispatched('toast', type: 'error');
        expect(tsaAlerts())->toHaveCount(1)->and(count(telegramApiCalls()))->toBe($calls)->and(tsaWebhookProblemOpen())->toBeTrue();

        // Cooldown over, but another run holds the lock: busy, nothing sent, nothing called.
        $this->travel(21)->seconds();
        $lock = Cache::lock(TelegramStatus::LOCK_KEY, 30);
        expect($lock->get())->toBeTrue();
        $page->call('checkNow')->assertDispatched('toast', type: 'error', body: __('telegram_status.flash.busy'));
        $lock->release();
        expect(tsaAlerts())->toHaveCount(1)->and(count(telegramApiCalls()))->toBe($calls)->and(tsaWebhookProblemOpen())->toBeTrue();

        // Problem -> healthy for real: one recovered message.
        $page->call('checkNow')->assertDispatched('toast', type: 'success');
        expect(tsaAlerts())->toHaveCount(2)->and(tsaWebhookProblemOpen())->toBeFalse();

        // Healthy -> problem again, first one limited, then a real one.
        tsaAnswers(['getWebhookInfo' => fn () => tsaBadWebhook()]);
        $page->call('checkNow')->assertDispatched('toast', type: 'error');
        expect(tsaAlerts())->toHaveCount(2);

        $this->travel(21)->seconds();
        $page->call('checkNow')->assertDispatched('toast', type: 'success');

        $texts = tsaAlerts();
        expect($texts)->toHaveCount(3)
            ->and($texts[0])->toContain(e(__('telegram.monitoring.events.telegram_webhook', [], 'uz')))
            ->and($texts[1])->toContain(e(__('telegram.monitoring.recovered.telegram_webhook', [], 'uz')))
            ->and($texts[2])->toContain(e(__('telegram.monitoring.events.telegram_webhook', [], 'uz')))
            ->and(tsaWebhookProblemOpen())->toBeTrue();
    });

    it('3: neither alerts nor clears an open problem when Telegram answers 5xx or times out', function (Closure $failure) {
        $page = tsaPage();
        tsaAnswers(['getWebhookInfo' => fn () => tsaBadWebhook()]);
        $page->call('checkNow')->assertDispatched('toast', type: 'success');
        expect(tsaAlerts())->toHaveCount(1);

        $this->travel(21)->seconds();
        tsaAnswers(['getWebhookInfo' => $failure]);
        $page->call('checkNow')->assertDispatched('toast', type: 'error', body: __('telegram_status.flash.unreachable'));

        expect(tsaAlerts())->toHaveCount(1)
            ->and(tsaWebhookProblemOpen())->toBeTrue()
            ->and(MonitoringState::query()->find(CheckTelegramWebhook::WEBHOOK_KEY)->problem_since)->not->toBeNull()
            ->and(Cache::lock(TelegramStatus::LOCK_KEY, 5)->get())->toBeTrue();

        // Telegram back and healthy: the still-open problem now recovers (one message).
        $this->travel(21)->seconds();
        tsaAnswers(['getWebhookInfo' => null]);
        $page->call('checkNow')->assertDispatched('toast', type: 'success');
        expect(tsaAlerts())->toHaveCount(2)->and(tsaWebhookProblemOpen())->toBeFalse();
    })->with([
        '502' => [fn () => Http::response('', 502)],
        '500 with a body' => [fn () => tsaApiError(500)],
        'timeout' => [function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        }],
    ]);
});

describe('chat verdict', function () {
    beforeEach(fn () => tsaBaseline());

    it('4: a scheduled run after a pass keeps it until the cache ages out, then a kicked bot (403) is fail, not unknown', function () {
        $check = app(CheckTelegramWebhook::class);

        $check->handle();
        expect(tsaMeta()['chat'])->toBe('pass');

        // Same verdict from the cache even though the bot has been kicked since.
        tsaAnswers(['getChat' => fn () => tsaApiError(403)]);
        $check->handle();
        expect(tsaMeta()['chat'])->toBe('pass');

        $this->travel(InboxChat::VERIFY_TTL + 1)->seconds();
        $check->handle();

        expect(tsaMeta()['chat'])->toBe('fail')->and(tsaMeta()['chat_key'])->toBe('sales');
    });

    it('4: a bot kicked from the first run on is fail', function () {
        tsaAnswers(['getChat' => fn () => tsaApiError(403)]);

        expect(app(CheckTelegramWebhook::class)->handle())->toBe(CheckTelegramWebhook::OK)
            ->and(tsaMeta()['chat'])->toBe('fail');
    });

    it('5: a refused-token run (401 / 404) makes no getChat call, leaves chat unknown and does not touch the verify cache', function (int $status) {
        $client = app(TelegramClient::class);
        expect(InboxChat::verify($client, TSA_SALES_CHAT))->toBeTrue();

        tsaAnswers(['getWebhookInfo' => fn () => tsaApiError($status), 'getChat' => fn () => Http::response(['ok' => true, 'result' => ['id' => -1001111111111, 'type' => 'supergroup', 'username' => 'public_now']])]);
        app(CheckTelegramWebhook::class)->handle();

        expect(array_count_values(tsaMethods())['getChat'])->toBe(1)
            ->and(tsaMeta())->chat->toBe('unknown')->get_me->toBe('fail')->code->toBe($status)
            // The cached pass survived: a verify now would otherwise read the public chat as fail.
            ->and(InboxChat::verify($client, TSA_SALES_CHAT))->toBeTrue()
            ->and(array_count_values(tsaMethods())['getChat'])->toBe(1);
    })->with([401, 404]);

    it('5: a refused-token run never caches the chat as bad', function (int $status) {
        tsaAnswers(['getWebhookInfo' => fn () => tsaApiError($status)]);
        app(CheckTelegramWebhook::class)->handle();

        expect(tsaMethods())->not->toContain('getChat');

        tsaAnswers(['getWebhookInfo' => null]);

        expect(InboxChat::verify(app(TelegramClient::class), TSA_SALES_CHAT))->toBeTrue()
            ->and(tsaMethods())->toContain('getChat');
    })->with([401, 404]);

    it('6: check-now with a cached pass still calls getChat, and a 403 gives fail', function () {
        expect(InboxChat::verify(app(TelegramClient::class), TSA_SALES_CHAT))->toBeTrue();
        $before = count(array_filter(tsaMethods(), fn (string $method): bool => $method === 'getChat'));
        tsaAnswers(['getChat' => fn () => tsaApiError(403)]);

        $page = tsaPage();
        $page->call('checkNow')->assertDispatched('toast', type: 'success');

        expect(count(array_filter(tsaMethods(), fn (string $method): bool => $method === 'getChat')))->toBe($before + 1)
            ->and(tsaMeta()['chat'])->toBe('fail');
        $page->assertSee('data-row="chat" data-state="problem"', false);
    });

    it('10: a verdict about the sales chat reads as unknown once the inbox chat is in use', function () {
        telegramBotConfigured(['services.telegram.chats.inbox' => '-1003333333333']);
        tsaSnapshot(['chat' => 'pass', 'chat_key' => 'sales']);

        expect(InboxChat::key())->toBe('inbox')
            ->and(tsaRow('chat'))->state->toBe('unknown');
        tsaPage()->assertSee('data-row="chat" data-state="unknown"', false);
    });
});

describe('snapshot content', function () {
    beforeEach(fn () => tsaBaseline());

    it('7: stores exactly the allow-listed keys and no getChat or getWebhookInfo marker, after check-now and after a scheduled run', function (string $path, bool $public) {
        $chat = ['id' => -1007777777777, 'type' => 'supergroup', 'title' => 'MARKER-TITLE', 'description' => 'MARKER-DESC', 'invite_link' => 'https://t.me/+MARKERINVITE'];

        if ($public) {
            $chat['username'] = 'marker_public';
        }

        tsaAnswers([
            'getChat' => fn () => Http::response(['ok' => true, 'result' => $chat]),
            'getWebhookInfo' => fn () => Http::response(['ok' => true, 'result' => [
                'url' => 'https://attacker-MARKER.example/hook',
                'pending_update_count' => 3,
                'last_error_date' => now()->subMinutes(2)->unix(),
                'last_error_message' => 'MARKER-ERROR-TEXT',
                'ip_address' => '203.0.113.99',
            ]]),
        ]);

        if ($path === 'check-now') {
            $component = tsaPage();
            $component->call('checkNow')->assertDispatched('toast', type: 'success');
            $cached = json_encode(Cache::get(TelegramStatus::CACHE_KEY));
            $html = $component->html();
        } else {
            app(CheckTelegramWebhook::class)->handle();
            $cached = '';
            $html = '';
        }

        $state = MonitoringState::query()->find(CheckTelegramWebhook::SNAPSHOT_KEY);

        expect(array_keys($state->meta))->toEqualCanonicalizing(TSA_SNAPSHOT_KEYS)
            ->and($state->meta['chat'])->toBe($public ? 'fail' : 'pass')
            ->and($state->meta['get_me'])->toBe('ok')
            ->and($state->meta['pending'])->toBe(3);

        $everything = json_encode($state->getAttributes()).$cached.$html;

        foreach (['MARKER', 'marker_public', '7777777777', '1111111111', '203.0.113', 'attacker', TG_BOT_TOKEN, '123456789'] as $leak) {
            expect($everything)->not->toContain($leak);
        }
    })->with([
        'check-now, private group' => ['check-now', false],
        'check-now, public group' => ['check-now', true],
        'scheduled, private group' => ['scheduled', false],
        'scheduled, public group' => ['scheduled', true],
    ]);

    it('9: a token or chat saved while check-now is in flight leaves no pass or ok', function (string $phase) {
        $bump = function (): void {
            InboxChat::forget();
        };

        tsaAnswers([
            $phase => function () use ($bump, $phase) {
                $bump();

                return $phase === 'getChat'
                    ? Http::response(['ok' => true, 'result' => ['id' => -1001111111111, 'type' => 'supergroup']])
                    : Http::response(['ok' => true, 'result' => ['url' => TSA_URL, 'pending_update_count' => 0]]);
            },
        ]);

        tsaPage()->call('checkNow')->assertDispatched('toast', type: 'success');

        $meta = tsaMeta();
        expect($meta)->chat->toBe('unknown')->get_me->toBe('unknown')->webhook_match->toBeNull()->chat_key->toBeNull()->pending->toBeNull()
            ->and(json_encode($meta))->not->toContain('"pass"')->not->toContain('"ok"');

        foreach (['token', 'webhook', 'chat'] as $id) {
            expect(tsaRow($id)['state'])->toBe('unknown');
        }
    })->with(['getWebhookInfo', 'getChat']);

    it('20: a green snapshot never feeds the bot switch or the checklist', function () {
        // Snapshot says everything is fine; the real world says otherwise.
        telegramBotConfigured(['services.telegram.chats.sales' => '@public_channel']);
        Setting::query()->where('key', BotSwitch::KEY)->delete();
        tsaSnapshot(['chat' => 'pass', 'chat_key' => 'sales', 'get_me' => 'ok', 'webhook_match' => true]);
        tsaAnswers(['getWebhookInfo' => fn () => tsaBadWebhook()]);
        $before = tsaMeta();

        $admin = staffUser();
        $refusal = null;

        try {
            app(SetBotEnabled::class)->enable($admin, null);
        } catch (TelegramBotException $exception) {
            $refusal = $exception;
        }

        expect($refusal)->not->toBeNull()
            ->and($refusal->reason)->toBe('not_ready')
            ->and($refusal->failing)->toContain('chat')->toContain('webhook')
            ->and(app(BotChecklist::class)->local()['chat'])->toBeFalse()
            ->and(BotSwitch::isEnabled())->toBeFalse()
            ->and(tsaMeta())->toBe($before);
    });

    it('20: a failing snapshot does not block a bot switch the live checks allow', function () {
        Setting::query()->where('key', BotSwitch::KEY)->delete();
        tsaSnapshot(['chat' => 'fail', 'get_me' => 'fail', 'webhook_match' => false, 'code' => 401]);

        expect(app(SetBotEnabled::class)->enable(staffUser(), null))->toBeTrue()
            ->and(BotSwitch::isEnabled())->toBeTrue();
    });

    it('20: a pass snapshot does not make verify() accept a chat it refuses', function () {
        tsaSnapshot(['chat' => 'pass', 'chat_key' => 'sales']);
        tsaAnswers(['getChat' => fn () => Http::response(['ok' => true, 'result' => ['id' => -1001111111111, 'type' => 'supergroup', 'username' => 'public_group']])]);

        expect(InboxChat::verify(app(TelegramClient::class), TSA_SALES_CHAT))->toBeFalse()
            ->and(tsaMethods())->toContain('getChat');
    });
});

describe('verdict clearing', function () {
    beforeEach(function () {
        tsaBaseline([]);
    });

    it('11: a rolled-back settings transaction leaves the verdicts and the generation alone', function (Closure $change) {
        $before = tsaMeta();
        $generation = InboxChat::generation();

        try {
            DB::transaction(function () use ($change): void {
                $change();

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            // Expected.
        }

        expect(tsaMeta())->toBe($before)->and(InboxChat::generation())->toBe($generation);

        // Control: the same change committed does clear them.
        $change();

        expect(tsaMeta())->get_me->toBe('unknown')->chat->toBe('unknown')->webhook_match->toBeNull()
            ->and(InboxChat::generation())->not->toBe($generation);
    })->with([
        'save a chat id' => [fn () => app(SaveIntegrationSettings::class)->handle(['telegram.chats.service' => '-1002222222222'], null, null)],
        'set the bot token' => [fn () => app(SetSecret::class)->handle('telegram.bot_token', '987654321:AAOtherFakeTokenForTestsOnly_0123456789', null, null)],
        'clear the bot token' => [function () {
            IntegrationSecret::query()->firstOrCreate(['key' => 'telegram.bot_token'], ['value' => '987654321:AAOtherFakeTokenForTestsOnly_0123456789', 'hint' => null, 'updated_by' => null]);
            app(ClearSecret::class)->handle('telegram.bot_token', null, null);
        }],
    ]);
});

describe('check-now guards', function () {
    beforeEach(fn () => tsaBaseline());

    it('13: a second user gets busy while the first holds the lock; the same user is limited, and neither leaves the lock taken', function () {
        $userA = staffUser();
        $userB = staffUser();
        $status = fn (): TelegramStatus => app(TelegramStatus::class);
        $during = [];

        tsaAnswers(['getWebhookInfo' => function () use (&$during, $userA, $userB, $status) {
            $during = [$status()->refresh($userB->id), $status()->refresh($userA->id)];

            return null;
        }]);

        expect($status()->refresh($userA->id))->toBe(TelegramStatus::REFRESHED)
            ->and($during)->toBe([TelegramStatus::BUSY, TelegramStatus::BUSY]);
        tsaAnswers(['getWebhookInfo' => null]);

        $calls = count(telegramApiCalls());

        // Lock free again: the same user inside the cooldown is limited and does not keep the lock.
        expect($status()->refresh($userA->id))->toBe(TelegramStatus::LIMITED)
            ->and(count(telegramApiCalls()))->toBe($calls)
            ->and(Cache::lock(TelegramStatus::LOCK_KEY, 5)->get())->toBeTrue();
        Cache::lock(TelegramStatus::LOCK_KEY)->forceRelease();

        // The busy answer used up nothing of user B's allowance.
        expect(RateLimiter::attempts('telegram-status-check:'.$userB->id))->toBe(0)
            ->and($status()->refresh($userB->id))->toBe(TelegramStatus::REFRESHED);
    });

    it('14: releases the lock when handle() throws a non-Telegram Throwable and shows only an error toast', function () {
        Exceptions::fake();
        $this->mock(CheckTelegramWebhook::class, fn ($mock) => $mock->shouldReceive('handle')->andThrow(new LogicException('MARKER-BOOM +998901234567')));

        $component = tsaPage();
        $component->call('checkNow')->assertOk()->assertDispatched('toast', type: 'error', body: __('telegram_status.flash.failed'));

        expect($component->html())->not->toContain('MARKER-BOOM')->not->toContain('998901234567')
            ->and(Cache::lock(TelegramStatus::LOCK_KEY, 5)->get())->toBeTrue();
        Exceptions::assertReported(LogicException::class);
    });

    it('15: a tampered checkNow call with arguments cannot dodge the limit or act for another user', function (array $arguments) {
        $admin = staffUser();
        $component = tsaPage($admin);
        $component->call('checkNow')->assertDispatched('toast', type: 'success');

        $calls = count(telegramApiCalls());
        $before = tsaMeta();
        $status = app(TelegramStatus::class)->result();

        $component->call('checkNow', ...$arguments)->assertOk()->assertDispatched('toast', type: 'error');

        expect(count(telegramApiCalls()))->toBe($calls)
            ->and(tsaMeta())->toBe($before)
            ->and(app(TelegramStatus::class)->result())->toBe($status)
            ->and(RateLimiter::attempts('telegram-status-check:99999'))->toBe(0)
            ->and(RateLimiter::attempts('telegram-status-check:other-user'))->toBe(0);
    })->with([
        'another user id' => [[99999]],
        'a string id' => [['other-user']],
        'an array' => [[['user' => 99999, 'force' => true]]],
        'several' => [[99999, 'other-user', null, true]],
    ]);

    it('15: a tampered first call still limits and locks by the signed-in user only', function () {
        $component = tsaPage();

        $component->call('checkNow', 99999, 'force')->assertOk();

        expect(RateLimiter::attempts('telegram-status-check:99999'))->toBe(0)
            ->and(tsaMethods())->toBe(['getWebhookInfo', 'getChat']);
    });

    it('19: a cache failure after the Telegram call is an error toast, not a 500, and the lock is not left taken', function () {
        Cache::extend('failing-epoch', fn () => Cache::repository(new class extends ArrayStore
        {
            public function put($key, $value, $seconds)
            {
                if ($key === TelegramStatus::EPOCH_KEY) {
                    throw new RuntimeException('cache down MARKER-CACHE');
                }

                return parent::put($key, $value, $seconds);
            }
        }));
        Exceptions::fake();
        $component = tsaPage();
        config(['cache.stores.failing-epoch' => ['driver' => 'failing-epoch'], 'cache.default' => 'failing-epoch']);

        $component->call('checkNow')->assertOk()->assertDispatched('toast', type: 'error', body: __('telegram_status.flash.failed'));

        expect($component->html())->not->toContain('MARKER-CACHE')
            ->and(Cache::lock(TelegramStatus::LOCK_KEY, 5)->get())->toBeTrue();
        Exceptions::assertReported(RuntimeException::class);
    });
});

describe('data that must not reach the panel', function () {
    beforeEach(fn () => tsaBaseline([]));

    it('16: counts a failed Telegram job without rendering or caching its payload or exception', function () {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'App\\Listeners\\SendTelegramAlerts@handle',
                'data' => ['command' => 'name=MARKER-NAME phone=+998909998877 text=MARKER-EXCERPT'],
            ]),
            'exception' => "RuntimeException: MARKER-EXCEPTION +998909998877\n#0 MARKER-TRACE",
            'failed_at' => now(),
        ]);

        $html = $this->actingAs(staffUser())->get(route('admin.telegram.status'))->assertOk()->getContent();
        $cached = json_encode(Cache::get(TelegramStatus::CACHE_KEY));
        $row = tsaRow('failed_jobs');

        expect($row['state'])->toBe('problem')->and($row['params']['count'])->toBe(1);

        foreach (['MARKER', '998909998877'] as $leak) {
            expect($html)->not->toContain($leak)->and($cached)->not->toContain($leak);
        }
    });

    it('17: a failing failed_jobs query makes only that row unknown and the panel still renders', function () {
        Exceptions::fake();
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{"displayName": MARKER-malformed',
            'exception' => 'RuntimeException: MARKER-EXCEPTION',
            'failed_at' => now(),
        ]);

        $html = $this->actingAs(staffUser())->get(route('admin.telegram.status'))->assertOk()
            ->assertSee('data-row="failed_jobs" data-state="unknown"', false)
            ->assertSee('data-row="token" data-state="ok"', false)
            ->getContent();

        expect($html)->not->toContain('MARKER')->and(json_encode(Cache::get(TelegramStatus::CACHE_KEY)))->not->toContain('MARKER');
    });

    it('18: a bad .env value never reaches the cached result, the page, the Livewire snapshot or the log', function (string $env, string $reason) {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $exception = $event->context['exception'] ?? null;
            $logged[] = $event->message.json_encode(Arr::except($event->context, ['exception'])).($exception instanceof Throwable ? $exception->getMessage().$exception->getTraceAsString() : '');
        });

        telegramBotConfigured([$env => 'MARKER-'.$reason.'-value']);

        $html = $this->actingAs(staffUser())->get(route('admin.telegram.status'))->assertOk()
            ->assertSee('data-invalid-env', false)
            ->getContent();
        $row = tsaRow('invalid_env');
        $cached = json_encode(Cache::get(TelegramStatus::CACHE_KEY));

        expect($row['items'])->toHaveCount(1)->and($row['items'][0]['reason'])->toBe($reason)
            ->and($html)->toContain('wire:snapshot');

        foreach ([$html, $cached, implode("\n", $logged)] as $haystack) {
            expect($haystack)->not->toContain('MARKER');
        }
    })->with([
        'format (token)' => ['services.telegram.bot_token', 'format'],
        'ignored (bool switch)' => ['services.telegram.notify_inbox', 'ignored'],
    ]);

    it('18: an exception while judging an .env value becomes the unknown reason without its message', function () {
        Exceptions::fake();
        telegramBotConfigured();
        Validator::shouldReceive('make')->andThrow(new RuntimeException('MARKER-VALIDATOR '.TG_BOT_TOKEN));

        $html = $this->actingAs(staffUser())->get(route('admin.telegram.status'))->assertOk()->getContent();
        $row = tsaRow('invalid_env');

        expect($row['state'])->toBe('unknown')
            ->and(collect($row['items'])->pluck('reason')->unique()->all())->toBe(['unknown'])
            ->and($html)->not->toContain('MARKER')->not->toContain(TG_BOT_TOKEN)
            ->and(json_encode(Cache::get(TelegramStatus::CACHE_KEY)))->not->toContain('MARKER')->not->toContain(TG_BOT_TOKEN);
    });
});
