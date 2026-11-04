<?php

use App\Actions\Telegram\CheckTelegramWebhook;
use App\Models\MonitoringState;
use App\Models\Setting;
use App\Support\Monitoring\MonitoredTasks;
use App\Support\Telegram\Bot\BotSwitch;
use App\Support\Telegram\Bot\InboxChat;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

/**
 * Plan 14 v2 B2, TG-38: telegram:check-webhook alerts via the Plan 17 monitor.
 */
const TG_EXPECTED_URL = 'https://poson.example.uz/api/telegram/webhook';

/**
 * Queue of getWebhookInfo answers (the first Http::fake() wins); sendMessage always ok.
 *
 * @param  list<mixed>|null  $queue
 */
function webhookInfoAnswers(?array $queue = null): mixed
{
    static $answers = [];

    if ($queue !== null) {
        $answers = $queue;

        return null;
    }

    return array_shift($answers);
}

/**
 * @return list<string>
 */
function webhookAlertTexts(): array
{
    return collect(telegramApiCalls())
        ->filter(fn (array $call): bool => $call['method'] === 'sendMessage' && ($call['data']['chat_id'] ?? null) === TG_MANAGEMENT_CHAT)
        ->map(fn (array $call): string => (string) $call['data']['text'])
        ->values()->all();
}

/**
 * The getChat answer of the next scheduled runs (the first Http::fake() wins, so this swaps a holder).
 */
function webhookChatAnswers(mixed $answer = null): mixed
{
    static $holder = null;

    if (func_num_args() > 0) {
        $holder = $answer;

        return null;
    }

    return $holder instanceof Closure ? $holder() : ($holder ?? Http::response(['ok' => true, 'result' => ['id' => -1001111111111, 'type' => 'supergroup']]));
}

beforeEach(function () {
    telegramBotConfigured();
    webhookInfoAnswers([]);
    webhookChatAnswers(null);

    Http::fake(['api.telegram.org/*' => function ($request) {
        if (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/getWebhookInfo')) {
            return webhookInfoAnswers() ?? Http::response(['ok' => true, 'result' => ['url' => TG_EXPECTED_URL, 'pending_update_count' => 0]]);
        }

        if (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/getChat')) {
            return webhookChatAnswers();
        }

        return Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
    }]);
});

function enableBotSwitch(): void
{
    Setting::query()->updateOrCreate(['key' => BotSwitch::KEY], ['group' => 'telegram', 'value' => true]);
}

it('is scheduled every 15 minutes without overlapping, on one server, recorded', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'telegram:check-webhook'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/15 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue()
        ->and(MonitoredTasks::isRecorded($event))->toBeTrue();
});

it('alerts once, dedupes, and sends recovered without the URL or Telegram error text', function () {
    enableBotSwitch();
    $bad = Http::response(['ok' => true, 'result' => [
        'url' => 'https://attacker.example/hook',
        'pending_update_count' => 250,
        'last_error_date' => now()->subMinutes(2)->unix(),
        'last_error_message' => 'Wrong response from the webhook: 500 Internal secret-detail',
        'ip_address' => '203.0.113.9',
    ]]);
    webhookInfoAnswers([$bad, $bad]);

    $this->artisan('telegram:check-webhook')->assertSuccessful();
    $this->artisan('telegram:check-webhook')->assertSuccessful();

    $texts = webhookAlertTexts();

    expect($texts)->toHaveCount(1)
        ->and($texts[0])->toContain(e(__('telegram.monitoring.events.telegram_webhook', [], 'uz')))
        ->toContain(e(__('telegram.monitoring.webhook_issues.backlog', [], 'uz')))
        ->not->toContain('attacker')
        ->not->toContain('secret-detail')
        ->not->toContain('203.0.113.9')
        ->and(MonitoringState::query()->find(CheckTelegramWebhook::WEBHOOK_KEY)->problem_since)->not->toBeNull();

    // Healthy again (default answer): one recovered message.
    $this->artisan('telegram:check-webhook')->assertSuccessful();

    $texts = webhookAlertTexts();

    expect($texts)->toHaveCount(2)
        ->and($texts[1])->toContain(e(__('telegram.monitoring.recovered.telegram_webhook', [], 'uz')))
        ->and(MonitoringState::query()->find(CheckTelegramWebhook::WEBHOOK_KEY)->problem_since)->toBeNull();
});

it('flags each problem while the bot is enabled', function (array $info, string $issue) {
    if (($info['last_error_date'] ?? null) === 'recent') {
        $info['last_error_date'] = now()->subMinutes(3)->unix();
    }

    expect(app(CheckTelegramWebhook::class)->issues(array_merge(['url' => TG_EXPECTED_URL, 'pending_update_count' => 0], $info), true))->toBe([$issue]);
})->with([
    'url differs' => [['url' => 'https://other.example/api/telegram/webhook'], 'url_mismatch'],
    'url empty' => [['url' => ''], 'url_mismatch'],
    'recent error' => [['last_error_date' => 'recent'], 'delivery_errors'],
    'backlog' => [['pending_update_count' => 101], 'backlog'],
]);

it('ignores an old delivery error', function () {
    expect(app(CheckTelegramWebhook::class)->issues(['url' => TG_EXPECTED_URL, 'last_error_date' => now()->subMinutes(20)->unix()], true))->toBe([]);
});

it('alerts while disabled only when the URL is set and unexpected', function () {
    $check = app(CheckTelegramWebhook::class);

    expect($check->issues(['url' => '', 'pending_update_count' => 500], false))->toBe([])
        ->and($check->issues(['url' => TG_EXPECTED_URL, 'last_error_date' => now()->unix()], false))->toBe([])
        ->and($check->issues(['url' => 'https://attacker.example/hook'], false))->toBe(['url_unexpected']);

    webhookInfoAnswers([Http::response(['ok' => true, 'result' => ['url' => 'https://attacker.example/hook']])]);

    $this->artisan('telegram:check-webhook')->assertSuccessful();

    expect(webhookAlertTexts())->toHaveCount(1)
        ->and(webhookAlertTexts()[0])->toContain(e(__('telegram.monitoring.webhook_issues.url_unexpected', [], 'uz')))
        ->not->toContain('attacker');
});

it('alerts on a 401 (token refused) and recovers', function () {
    webhookInfoAnswers([Http::response(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'], 401)]);

    $this->artisan('telegram:check-webhook')->assertSuccessful();

    expect(webhookAlertTexts())->toHaveCount(1)
        ->and(webhookAlertTexts()[0])->toContain(e(__('telegram.monitoring.events.telegram_token', [], 'uz')))
        ->not->toContain(TG_BOT_TOKEN);

    $this->artisan('telegram:check-webhook')->assertSuccessful();

    expect(webhookAlertTexts())->toHaveCount(2)
        ->and(webhookAlertTexts()[1])->toContain(e(__('telegram.monitoring.recovered.telegram_token', [], 'uz')));
});

it('fails the run without alerting when Telegram is unreachable', function () {
    webhookInfoAnswers([Http::response('', 502)]);

    $this->artisan('telegram:check-webhook')->assertFailed();

    expect(webhookAlertTexts())->toBe([]);
});

it('does nothing without a bot token', function () {
    telegramBotConfigured(['services.telegram.bot_token' => null]);

    $this->artisan('telegram:check-webhook')->assertSuccessful();

    expect(telegramApiCalls())->toBe([]);
});

it('closes open problems when the token is removed', function () {
    foreach ([CheckTelegramWebhook::TOKEN_KEY, CheckTelegramWebhook::WEBHOOK_KEY] as $key) {
        MonitoringState::query()->create(['key' => $key, 'problem_since' => now()->subHour(), 'alerted_at' => now()->subHour()]);
    }

    telegramBotConfigured(['services.telegram.bot_token' => null]);

    $this->artisan('telegram:check-webhook')->assertSuccessful();

    expect(MonitoringState::query()->whereNotNull('problem_since')->count())->toBe(0)
        ->and(CheckTelegramWebhook::openProblems())->toBe([]);
});

it('shows an open Telegram problem on the Monitoring screen', function () {
    MonitoringState::query()->create(['key' => CheckTelegramWebhook::TOKEN_KEY, 'problem_since' => now()]);

    $this->actingAs(staffUser())->get(route('admin.monitoring'))
        ->assertOk()
        ->assertSee('data-telegram-problem="telegram_token"', false)
        ->assertSee(__('telegram.monitoring.events.telegram_token'));
});

describe('status snapshot (plan 21 E1, T21-6)', function () {
    it('writes an exact allow-list and no marker value, URL, IP or error text', function () {
        enableBotSwitch();
        webhookInfoAnswers([Http::response(['ok' => true, 'result' => [
            'url' => 'https://attacker-MARKER.example/hook',
            'pending_update_count' => 7,
            'last_error_date' => now()->subMinutes(2)->unix(),
            'last_error_message' => 'Wrong response MARKER-ERROR-TEXT',
            'ip_address' => '203.0.113.99',
        ]])]);

        $this->artisan('telegram:check-webhook')->assertSuccessful();

        $state = MonitoringState::query()->find('telegram:status');

        expect(array_keys($state->meta))->toBe(['checked_at', 'webhook_match', 'last_error_at', 'pending', 'get_me', 'chat', 'chat_key', 'code'])
            ->and($state->meta['webhook_match'])->toBeFalse()
            ->and($state->meta['pending'])->toBe(7)
            ->and($state->meta['get_me'])->toBe('ok')
            ->and($state->meta['chat'])->toBeIn(['pass', 'fail', 'unknown'])
            ->and($state->meta['chat_key'])->toBe('sales')
            ->and(json_encode($state->getAttributes()))->not->toContain('MARKER')->not->toContain('203.0.113')->not->toContain('attacker')->not->toContain(TG_BOT_TOKEN)->not->toContain('1111111111');
    });

    it('records a healthy match', function () {
        $this->artisan('telegram:check-webhook')->assertSuccessful();

        expect(MonitoringState::query()->find('telegram:status')->meta)
            ->webhook_match->toBeTrue()->get_me->toBe('ok')->code->toBeNull();
    });

    it('records a revoked token as get_me fail with the status code', function () {
        webhookInfoAnswers([Http::response(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized MARKER'], 401)]);

        $this->artisan('telegram:check-webhook')->assertSuccessful();

        $meta = MonitoringState::query()->find('telegram:status')->meta;

        expect($meta['get_me'])->toBe('fail')->and($meta['code'])->toBe(401)->and($meta['webhook_match'])->toBeNull()
            ->and(json_encode($meta))->not->toContain('MARKER');
    });

    it('verifies the inbox chat in the scheduled run and keeps only the verdict', function () {
        webhookChatAnswers(Http::response(['ok' => true, 'result' => ['id' => -1001111111111, 'type' => 'supergroup', 'title' => 'MARKER-TITLE']]));

        app(CheckTelegramWebhook::class)->handle();

        $meta = MonitoringState::query()->find('telegram:status')->meta;
        expect($meta['chat'])->toBe('pass')->and(json_encode($meta))->not->toContain('MARKER')->not->toContain('1111111111');

        // A later scheduled run (cached verdict, no new answer needed) keeps the real verdict.
        app(CheckTelegramWebhook::class)->handle();
        expect(MonitoringState::query()->find('telegram:status')->meta['chat'])->toBe('pass');
    });

    it('records a refused chat as fail without an alert of its own', function () {
        webhookChatAnswers(Http::response(['ok' => false, 'error_code' => 400, 'description' => 'chat not found'], 400));

        expect(app(CheckTelegramWebhook::class)->handle())->toBe(CheckTelegramWebhook::OK)
            ->and(MonitoringState::query()->find('telegram:status')->meta['chat'])->toBe('fail')
            ->and(webhookAlertTexts())->toBe([]);
    });

    it('opens the token problem on a 404 like on a 401 and returns PROBLEM', function () {
        webhookInfoAnswers([Http::response(['ok' => false, 'error_code' => 404, 'description' => 'Not Found'], 404)]);

        expect(app(CheckTelegramWebhook::class)->handle())->toBe(CheckTelegramWebhook::PROBLEM)
            ->and(MonitoringState::query()->find(CheckTelegramWebhook::TOKEN_KEY)->problem_since)->not->toBeNull();
    });

    it('skips the chat check after a 401 or 404 and does not cache a bad chat', function (int $status) {
        webhookInfoAnswers([Http::response(['ok' => false, 'error_code' => $status, 'description' => 'x'], $status)]);

        app(CheckTelegramWebhook::class)->handle();

        expect(collect(telegramApiCalls())->pluck('method')->all())->not->toContain('getChat')
            ->and(MonitoringState::query()->find('telegram:status')->meta)->chat->toBe('unknown')->get_me->toBe('fail');
    })->with([401, 404]);

    it('skips the chat check without a token or an inbox chat', function () {
        freshSecrets();
        app(CheckTelegramWebhook::class)->handle();
        telegramBotConfigured(['services.telegram.chats.sales' => null]);
        app(CheckTelegramWebhook::class)->handle();

        expect(collect(telegramApiCalls())->pluck('method')->all())->not->toContain('getChat')
            ->and(MonitoringState::query()->find('telegram:status')->meta['chat'])->toBe('unknown');
    });

    it('makes a transient getChat failure unknown and never changes the return value', function () {
        webhookChatAnswers(Http::response('', 503));

        expect(app(CheckTelegramWebhook::class)->handle())->toBe(CheckTelegramWebhook::OK)
            ->and(MonitoringState::query()->find('telegram:status')->meta['chat'])->toBe('unknown');
    });

    it('reports an unexpected error while verifying and still writes unknown', function () {
        Exceptions::fake();
        webhookChatAnswers(function () {
            throw new LogicException('boom');
        });

        expect(app(CheckTelegramWebhook::class)->handle())->toBe(CheckTelegramWebhook::OK)
            ->and(MonitoringState::query()->find('telegram:status')->meta['chat'])->toBe('unknown');
    });

    it('overwrites the verdicts of a run that was in flight while the token or chat was saved', function () {
        webhookChatAnswers(function () {
            InboxChat::forget();

            return Http::response(['ok' => true, 'result' => ['id' => -1001111111111, 'type' => 'supergroup']]);
        });

        app(CheckTelegramWebhook::class)->handle();

        expect(MonitoringState::query()->find('telegram:status')->meta)
            ->chat->toBe('unknown')->get_me->toBe('unknown')->webhook_match->toBeNull()->chat_key->toBeNull();
    });

    it('survives two overlapping writes of the snapshot', function () {
        app(CheckTelegramWebhook::class)->handle();
        app(CheckTelegramWebhook::class)->handle();

        expect(MonitoringState::query()->where('key', 'telegram:status')->count())->toBe(1);
    });

    it('records an unreachable API as unknown and no token as unknown', function () {
        webhookInfoAnswers([Http::response('', 502)]);
        app(CheckTelegramWebhook::class)->handle();
        expect(MonitoringState::query()->find('telegram:status')->meta)->get_me->toBe('unknown')->code->toBe(502);

        freshSecrets();
        app(CheckTelegramWebhook::class)->handle();
        expect(MonitoringState::query()->find('telegram:status')->meta)->get_me->toBe('unknown')->webhook_match->toBeNull()->chat_key->toBeNull();
    });
});
