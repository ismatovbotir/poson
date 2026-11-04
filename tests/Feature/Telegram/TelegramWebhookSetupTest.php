<?php

use App\Actions\Telegram\ConfigureTelegramWebhook;
use App\Actions\Telegram\TelegramBotException;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Livewire\Admin\Telegram\Settings as TelegramSettings;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Telegram\Bot\BotSwitch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Plan 14 v2 B2: ConfigureTelegramWebhook (H1 / condition 1) and the client.
 */
/**
 * Every Bot API call answers ok (the first Http::fake() wins, so tests with other answers don't call this).
 */
function telegramApiOk(): void
{
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
}

beforeEach(function () {
    telegramBotConfigured();
});

it('sets the webhook to APP_URL plus the fixed path with secret and options, audited', function () {
    telegramApiOk();
    $admin = staffUser();

    app(ConfigureTelegramWebhook::class)->set($admin, '10.0.0.1');

    $call = collect(telegramApiCalls())->firstWhere('method', 'setWebhook');

    expect(route('api.telegram.webhook', absolute: false))->toBe(ConfigureTelegramWebhook::PATH)
        ->and($call['data'])->toBe([
            'url' => 'https://poson.example.uz/api/telegram/webhook',
            'secret_token' => TG_WEBHOOK_SECRET,
            'allowed_updates' => ['message', 'callback_query', 'my_chat_member'],
            'max_connections' => 10,
            'drop_pending_updates' => true,
        ])
        ->and(SettingsAudit::query()->sole())
        ->area->toBe(SettingsArea::TelegramBot)
        ->action->toBe(AuditAction::WebhookSet)
        ->user_id->toBe($admin->id);
});

it('never uses the request host for the webhook URL', function () {
    telegramApiOk();
    $this->withServerVariables(['HTTP_HOST' => 'evil.example.com', 'HTTPS' => 'on']);
    request()->headers->set('Host', 'evil.example.com');

    app(ConfigureTelegramWebhook::class)->set(null, null);

    expect(collect(telegramApiCalls())->firstWhere('method', 'setWebhook')['data']['url'])->toBe('https://poson.example.uz/api/telegram/webhook');
});

it('refuses http APP_URL, a missing secret and a missing token without calling Telegram', function (array $config, string $reason) {
    telegramApiOk();
    telegramBotConfigured($config);

    if (isset($config['app.url'])) {
        config(['app.url' => $config['app.url']]);
    }

    expect(fn () => app(ConfigureTelegramWebhook::class)->set(null, null))
        ->toThrow(fn (TelegramBotException $exception) => expect($exception->reason)->toBe($reason));

    expect(telegramApiCalls())->toBe([])
        ->and(SettingsAudit::query()->count())->toBe(0);
})->with([
    'http' => [['app.url' => 'http://poson.example.uz'], 'not_https'],
    'no secret' => [['services.telegram.webhook_secret' => null], 'no_secret'],
    'no token' => [['services.telegram.bot_token' => null], 'no_token'],
]);

/**
 * getWebhookInfo answers with $url; every other call is ok.
 */
function telegramWebhookAt(string $url): void
{
    Http::fake(['api.telegram.org/*' => fn ($request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/getWebhookInfo')
        ? Http::response(['ok' => true, 'result' => ['url' => $url, 'pending_update_count' => 0]])
        : Http::response(['ok' => true, 'result' => true])]);
}

it('re-registers the webhook after a new secret is generated, keeping pending updates', function () {
    telegramWebhookAt('https://poson.example.uz/api/telegram/webhook');
    confirmAdminPassword();
    $admin = staffUser();
    telegramBotConfigured(['services.telegram.webhook_secret' => null]);

    Livewire::actingAs($admin)->test(TelegramSettings::class)
        ->call('generateWebhookSecret')
        ->assertDispatched('toast', type: 'success')
        ->assertNotDispatched('toast', type: 'error');

    $call = collect(telegramApiCalls())->firstWhere('method', 'setWebhook');

    expect($call['data']['secret_token'])->toHaveLength(48)
        ->and($call['data']['drop_pending_updates'])->toBeFalse()
        ->and(SettingsAudit::query()->where('action', AuditAction::WebhookSet->value)->count())->toBe(1);
});

it('does not take over a webhook that points elsewhere or is not set after a new secret (M1)', function (string $current) {
    telegramWebhookAt($current);

    expect(app(ConfigureTelegramWebhook::class)->afterSecretGenerated(staffUser(), null))->toBe(ConfigureTelegramWebhook::SKIPPED)
        ->and(collect(telegramApiCalls())->pluck('method')->all())->toBe(['getWebhookInfo'])
        ->and(SettingsAudit::query()->count())->toBe(0);
})->with([
    'other host' => ['https://attacker.example/api/telegram/webhook'],
    'other path' => ['https://poson.example.uz/api/telegram/webhook2'],
    'not set' => [''],
]);

it('refuses webhook and command writes to a user without telegram.bot', function (Closure $write) {
    telegramApiOk();
    $user = staffUser(Role::SalesManager);

    expect(fn () => $write($user))->toThrow(AuthorizationException::class)
        ->and(telegramApiCalls())->toBe([])
        ->and(SettingsAudit::query()->count())->toBe(0);
})->with([
    'set webhook' => [fn ($user) => app(ConfigureTelegramWebhook::class)->set($user, null)],
    'delete webhook' => [fn ($user) => app(ConfigureTelegramWebhook::class)->delete($user, null)],
]);

it('hints at deleting the webhook when polling gets 409', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 409], 409)]);

    $this->artisan('telegram:poll --once')
        ->expectsOutputToContain('telegram:webhook delete')
        ->assertFailed();
});

it('skips polled updates without an integer update id', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => [['update_id' => 'x'], telegramPrivateUpdate(601)]])]);

    $this->artisan('telegram:poll --once')->assertSuccessful();

    expect(TelegramUpdate::query()->pluck('update_id')->all())->toBe([601]);
});

it('shows an error toast, not an exception, when re-registering after a new secret fails', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 500)]);
    confirmAdminPassword();
    telegramBotConfigured(['services.telegram.webhook_secret' => null]);

    Livewire::actingAs(staffUser())->test(TelegramSettings::class)
        ->call('generateWebhookSecret')
        ->assertDispatched('toast', type: 'success')
        ->assertDispatched('toast', type: 'error');
});

it('skips the re-registration quietly without https', function () {
    telegramApiOk();
    config(['app.url' => 'http://localhost']);

    expect(app(ConfigureTelegramWebhook::class)->afterSecretGenerated(null, null))->toBe(ConfigureTelegramWebhook::SKIPPED)
        ->and(telegramApiCalls())->toBe([]);
});

it('sets, deletes and inspects the webhook from the console', function () {
    Http::fake(['api.telegram.org/*' => Http::sequence()
        ->push(['ok' => true, 'result' => true])
        ->push(['ok' => true, 'result' => true])
        ->push(['ok' => true, 'result' => ['url' => 'https://attacker.example/x', 'pending_update_count' => 3]])]);

    $this->artisan('telegram:webhook set')->assertSuccessful();
    $this->artisan('telegram:webhook delete')->assertSuccessful();

    expect(SettingsAudit::query()->orderBy('id')->pluck('action')->all())->toBe([AuditAction::WebhookSet, AuditAction::WebhookDeleted]);

    $this->artisan('telegram:webhook info')
        ->expectsOutputToContain('DIFFERENT from APP_URL')
        ->doesntExpectOutputToContain('attacker')
        ->assertSuccessful();
});

it('refuses the webhook console command over http', function () {
    telegramApiOk();
    config(['app.url' => 'http://poson.example.uz']);

    $this->artisan('telegram:webhook set')->assertFailed();
});

it('refuses telegram:poll in production', function () {
    telegramApiOk();
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('telegram:poll --once')->assertFailed();

    expect(telegramApiCalls())->toBe([]);
});

it('feeds polled updates to the same handler in development', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => [telegramPrivateUpdate(501), telegramPrivateUpdate(501)]])]);

    $this->artisan('telegram:poll --once --timeout=0')->assertSuccessful();

    expect(TelegramUpdate::query()->pluck('update_id')->all())->toBe([501]);
});

it('disables the bot from the console without a checklist, audited without an actor', function () {
    telegramApiOk();
    Setting::query()->create(['key' => BotSwitch::KEY, 'group' => 'telegram', 'value' => true]);

    $this->artisan('telegram:bot disable')->assertSuccessful();

    expect(BotSwitch::isEnabled())->toBeFalse()
        ->and(SettingsAudit::query()->sole())
        ->action->toBe(AuditAction::Disabled)
        ->user_id->toBeNull();

    $this->artisan('telegram:bot enable')->assertFailed();
    expect(BotSwitch::isEnabled())->toBeFalse();
});

it('never puts the token into exceptions or logs', function (Closure $fake) {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });
    Exceptions::fake();
    $fake();

    $caught = null;

    try {
        app(TelegramClient::class)->getWebhookInfo();
    } catch (TelegramException $exception) {
        $caught = $exception;
    }

    $this->artisan('telegram:check-webhook');
    $this->artisan('telegram:webhook set');

    expect($caught)->not->toBeNull()
        ->and($caught->getMessage())->not->toContain('AAFakeToken')
        ->and($caught->getPrevious())->toBeNull()
        ->and(implode("\n", $logged))->not->toContain('AAFakeToken');

    Exceptions::assertNotReported(fn (Throwable $exception): bool => str_contains($exception->getMessage(), 'AAFakeToken'));
})->with([
    'server error' => [fn () => Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'boom'], 500)])],
    'connection failure' => [fn () => Http::fake(['api.telegram.org/*' => fn () => throw new ConnectionException('cURL error 7 for https://api.telegram.org/bot'.TG_BOT_TOKEN.'/getWebhookInfo')])],
]);
