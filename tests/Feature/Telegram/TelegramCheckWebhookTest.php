<?php

use App\Actions\Telegram\CheckTelegramWebhook;
use App\Models\MonitoringState;
use App\Models\Setting;
use App\Support\Monitoring\MonitoredTasks;
use App\Support\Telegram\Bot\BotSwitch;
use Illuminate\Console\Scheduling\Schedule;
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

beforeEach(function () {
    telegramBotConfigured();
    webhookInfoAnswers([]);

    Http::fake(['api.telegram.org/*' => function ($request) {
        if (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/getWebhookInfo')) {
            return webhookInfoAnswers() ?? Http::response(['ok' => true, 'result' => ['url' => TG_EXPECTED_URL, 'pending_update_count' => 0]]);
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
