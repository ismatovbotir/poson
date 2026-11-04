<?php

use App\Actions\Telegram\ConfigureTelegramWebhook;
use App\Actions\Telegram\HandleTelegramUpdate;
use App\Actions\Telegram\SetBotEnabled;
use App\Actions\Telegram\TelegramUpdateFailed;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Livewire\Admin\Settings\Integrations;
use App\Livewire\Admin\Telegram\Bot;
use App\Models\Page;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\TelegramUpdate;
use App\Models\TelegramUser;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Telegram\Bot\BotSwitch;
use App\Support\Telegram\Bot\UpdateParser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Plan 14 v2 B2 abuse cases (security-reviewer): webhook secret, throttle,
 * host spoofing, dedupe, non-private chats, error leaks, bot page.
 * Happy paths live in tests/Feature/Telegram/*; here only the hostile angles.
 */
const ABUSE_EXPECTED_URL = 'https://poson.example.uz/api/telegram/webhook';

function abuseBotOn(bool $on = true): void
{
    Setting::query()->updateOrCreate(['key' => BotSwitch::KEY], ['group' => SettingsArea::TelegramBot->group(), 'value' => $on]);
}

/**
 * HandleTelegramUpdate whose customer processing counts calls (and may throw).
 */
function abuseCustomerSpy(?Throwable $throws = null): object
{
    $spy = new class extends HandleTelegramUpdate
    {
        public int $processed = 0;

        public ?Throwable $throws = null;

        protected function processCustomer(UpdateParser $update): void
        {
            $this->processed++;

            if ($this->throws !== null) {
                throw $this->throws;
            }
        }
    };
    $spy->throws = $throws;
    app()->instance(HandleTelegramUpdate::class, $spy);

    return $spy;
}

/**
 * getWebhookInfo answers with $url; every other Bot API call is ok.
 */
function abuseTelegramWebhookAt(string $url = ABUSE_EXPECTED_URL, array $extra = []): void
{
    Http::fake(['api.telegram.org/*' => fn ($request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/getWebhookInfo')
        ? Http::response(['ok' => true, 'result' => array_merge(['url' => $url, 'pending_update_count' => 0], $extra)])
        : Http::response(['ok' => true, 'result' => true])]);
}

function abuseSetWebhookUrls(): array
{
    return collect(telegramApiCalls())->where('method', 'setWebhook')->pluck('data.url')->all();
}

beforeEach(function () {
    telegramBotConfigured();
    RateLimiter::clear('telegram-webhook');
});

// 1. Secret compared byte for byte.
it('refuses the secret in another case or with extra whitespace', function (Closure $mutate) {
    postTelegramUpdate(telegramPrivateUpdate(1), $mutate(TG_WEBHOOK_SECRET))->assertUnauthorized();

    expect(TelegramUpdate::query()->count())->toBe(0);
})->with([
    'upper case' => [fn (string $secret) => strtoupper($secret)],
    'trailing space' => [fn (string $secret) => $secret.' '],
    'trailing newline' => [fn (string $secret) => $secret."\n"],
    'truncated' => [fn (string $secret) => substr($secret, 0, -1)],
    'longer' => [fn (string $secret) => $secret.'x'],
]);

// 2. Unauthenticated floods must not starve Telegram (throttle runs after the secret check).
it('does not spend the throttle budget on 700 unauthenticated requests', function () {
    foreach (range(1, 700) as $i) {
        postTelegramUpdate(telegramPrivateUpdate($i), 'wrong-secret')->assertUnauthorized();
    }

    postTelegramUpdate(telegramPrivateUpdate(9001))->assertOk();

    expect(TelegramUpdate::query()->pluck('update_id')->all())->toBe([9001]);
});

// 3. Host spoofing (action level: tests/Feature/Telegram/TelegramWebhookSetupTest.php); here through the screens.
it('registers only APP_URL plus the fixed path whatever Host headers the admin request carries', function () {
    abuseTelegramWebhookAt();
    confirmAdminPassword();
    $headers = ['Host' => 'evil.example.com', 'X-Forwarded-Host' => 'evil.example.com', 'X-Forwarded-Proto' => 'https'];
    $admin = staffUser();

    $this->withHeaders($headers)->withServerVariables(['HTTP_HOST' => 'evil.example.com']);
    Livewire::actingAs($admin)->test(Bot::class)->call('setWebhook');

    telegramBotConfigured(['services.telegram.webhook_secret' => null]);
    Livewire::actingAs($admin)->test(Integrations::class)->call('generateWebhookSecret');

    expect(abuseSetWebhookUrls())->toBe([ABUSE_EXPECTED_URL, ABUSE_EXPECTED_URL])
        ->and(collect(telegramApiCalls())->pluck('data')->toJson())->not->toContain('evil.example.com');
});

// 4. A webhook that points elsewhere is never taken over by a secret rotation (action level: TelegramWebhookSetupTest).
it('skips the re-registration from the Integrations screen when Telegram points elsewhere', function (string $current) {
    abuseTelegramWebhookAt($current);
    confirmAdminPassword();
    $admin = staffUser();
    telegramBotConfigured(['services.telegram.webhook_secret' => null]);

    Livewire::actingAs($admin)->test(Integrations::class)
        ->call('generateWebhookSecret')
        ->assertNotDispatched('toast', type: 'error');

    expect(collect(telegramApiCalls())->pluck('method')->all())->toBe(['getWebhookInfo'])
        ->and(SettingsAudit::query()->where('key', ConfigureTelegramWebhook::AUDIT_KEY)->count())->toBe(0);
})->with([
    'attacker host' => ['https://attacker.example/api/telegram/webhook'],
    'same host, http' => ['http://poson.example.uz/api/telegram/webhook'],
    'query string appended' => [ABUSE_EXPECTED_URL.'?x=1'],
    'upper-case host' => ['https://POSON.EXAMPLE.UZ/api/telegram/webhook'],
]);

// 5. Replay of one update_id.
it('processes one update id once when it is delivered twice in a row', function () {
    abuseBotOn();
    $spy = abuseCustomerSpy();

    postTelegramUpdate(telegramPrivateUpdate(77))->assertOk();
    postTelegramUpdate(telegramPrivateUpdate(77, ['text' => 'replayed with other text']))->assertOk();

    $handler = new HandleTelegramUpdate;

    expect($spy->processed)->toBe(1)
        ->and(TelegramUpdate::query()->count())->toBe(1)
        ->and($handler->handle(telegramPrivateUpdate(77)))->toBe(HandleTelegramUpdate::DUPLICATE);
});

// 6. Non-private / spoofed chats write nothing but the update id (basic group + bot sender: TelegramWebhookTest).
it('writes only the update id for supergroups, channels, bots and a chat id that is not the sender', function (array $update) {
    abuseBotOn();
    $spy = abuseCustomerSpy();

    postTelegramUpdate($update)->assertOk();

    expect($spy->processed)->toBe(0)
        ->and(TelegramUpdate::query()->pluck('update_id')->all())->toBe([$update['update_id']])
        ->and(TelegramUser::query()->count())->toBe(0);
})->with([
    'supergroup' => [telegramPrivateUpdate(601, ['chat' => ['id' => -1001, 'type' => 'supergroup']])],
    'channel' => [telegramPrivateUpdate(602, ['chat' => ['id' => -1002, 'type' => 'channel']])],
    'group' => [telegramPrivateUpdate(603, ['chat' => ['id' => -1003, 'type' => 'group']])],
    'bot sender' => [telegramPrivateUpdate(604, ['from' => ['id' => 5550001, 'is_bot' => true]])],
    'private chat id differs from sender' => [telegramPrivateUpdate(605, ['chat' => ['id' => 777, 'type' => 'private']])],
    'is_bot as string' => [telegramPrivateUpdate(606, ['from' => ['id' => 5550001, 'is_bot' => 'true']])],
    'no sender' => [['update_id' => 607, 'message' => ['message_id' => 1, 'chat' => ['id' => 5550001, 'type' => 'private'], 'text' => 'hi']]],
]);

// 7. my_chat_member cannot create rows or reach other chats.
it('ignores my_chat_member kicks for unknown users and for group chats', function () {
    $stranger = ['update_id' => 701, 'my_chat_member' => [
        'chat' => ['id' => 4440001, 'type' => 'private'], 'from' => ['id' => 4440001, 'is_bot' => false],
        'new_chat_member' => ['status' => 'kicked'],
    ]];
    $groupKick = ['update_id' => 702, 'my_chat_member' => [
        'chat' => ['id' => -1004, 'type' => 'supergroup'], 'from' => ['id' => 5550001, 'is_bot' => false],
        'new_chat_member' => ['status' => 'kicked'],
    ]];
    $victim = TelegramUser::factory()->create(['telegram_id' => 5550001, 'blocked_at' => null]);

    postTelegramUpdate($stranger)->assertOk();
    postTelegramUpdate($groupKick)->assertOk();

    expect(TelegramUser::query()->pluck('telegram_id')->all())->toBe([5550001])
        ->and($victim->fresh()->blocked_at)->toBeNull();
});

// 8. update_id must be a non-negative JSON integer.
it('answers 200 and stores nothing for a string, negative or float update id', function (mixed $updateId) {
    abuseBotOn();
    $body = json_encode(array_merge(telegramPrivateUpdate(1), ['update_id' => $updateId]), JSON_PRESERVE_ZERO_FRACTION);

    postTelegramUpdate($body)->assertOk();

    expect(TelegramUpdate::query()->count())->toBe(0);
})->with([
    'numeric string' => ['42'],
    'negative' => [-5],
    'float' => [42.5],
    'float with zero fraction' => [42.0],
    'null' => [null],
    'array' => [[42]],
]);

// 9. Reported exception and logs carry only the update id and the class.
it('reports and logs only the update id and exception class when handling throws', function () {
    $secretText = 'private customer text +998901234567';
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $exception = $event->context['exception'] ?? null;
        $logged[] = $event->message.json_encode(array_filter($event->context, fn ($value) => ! $value instanceof Throwable))
            .($exception instanceof Throwable ? $exception->getMessage().get_class($exception) : '');
    });
    abuseBotOn();
    abuseCustomerSpy(new RuntimeException("{$secretText} bot".TG_BOT_TOKEN.' secret '.TG_WEBHOOK_SECRET));

    postTelegramUpdate(telegramPrivateUpdate(91, ['text' => $secretText]))->assertOk();

    $log = implode("\n", $logged);

    expect($log)->toContain('Telegram update 91 failed: '.RuntimeException::class)
        ->not->toContain($secretText)
        ->not->toContain('AAFakeToken')
        ->not->toContain(TG_WEBHOOK_SECRET)
        ->not->toContain('998901234567');
});

it('keeps the token out of the reported TelegramUpdateFailed itself', function () {
    $failed = new TelegramUpdateFailed(5, RuntimeException::class);

    expect($failed->getMessage())->toBe('Telegram update 5 failed: RuntimeException')
        ->and($failed->getPrevious())->toBeNull();
});

// 10. Connection failures never expose the API URL (the token is part of it).
it('hides the API url and token from the exception of a failed call, for every kind of call', function (Closure $call) {
    $url = 'https://api.telegram.org/bot'.TG_BOT_TOKEN.'/anything';
    Http::fake(['api.telegram.org/*' => fn () => throw new ConnectionException("cURL error 6: Could not resolve host for {$url}")]);

    $caught = null;

    try {
        $call(app(TelegramClient::class));
    } catch (TelegramException $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->getPrevious())->toBeNull()
        ->and($caught->getMessage())->not->toContain('AAFakeToken')->not->toContain('api.telegram.org');
})->with([
    'getMe' => [fn (TelegramClient $client) => $client->getMe()],
    'sendMessage' => [fn (TelegramClient $client) => $client->sendMessage('1', 'hello')],
    'setWebhook' => [fn (TelegramClient $client) => $client->setWebhook(ABUSE_EXPECTED_URL, TG_WEBHOOK_SECRET, ['message'], 10)],
    'getUpdates' => [fn (TelegramClient $client) => $client->getUpdates(0, 0, ['message'])],
]);

// 11. Tampered Livewire state cannot enable the bot while a checklist item fails.
it('refuses to enable after the locked checklist and webhook state were tampered with', function () {
    abuseTelegramWebhookAt('https://attacker.example/hook');
    // Only the webhook item fails: the privacy page is ready.
    Page::factory()->create(['slug' => 'privacy', 'is_active' => true, 'needs_review' => false]);
    confirmAdminPassword();
    Setting::query()->create(['key' => BotSwitch::KEY, 'group' => 'telegram', 'value' => false]);
    $component = Livewire::actingAs(staffUser())->test(Bot::class);

    foreach (['checklist' => ['privacy' => true, 'webhook' => true], 'webhook' => ['set' => true, 'matches' => true, 'pending' => 0, 'last_error_at' => null], 'loaded' => true] as $property => $value) {
        expect(fn () => $component->set($property, $value))->toThrow(CannotUpdateLockedPropertyException::class);
    }

    $component->call('enable')->assertDispatched('toast', type: 'error');

    expect(BotSwitch::isEnabled())->toBeFalse()
        ->and(SettingsAudit::query()->count())->toBe(0)
        ->and(Setting::query()->where('key', BotSwitch::KEY)->sole()->value)->toBeFalse();
});

// 12. Every Bot page method is authorized per request (the page itself: TelegramBotPageTest).
it('forbids each Bot page method called directly by a role that has no telegram.bot', function (Role $role, string $method) {
    abuseTelegramWebhookAt();
    Page::factory()->create(['slug' => 'privacy', 'is_active' => true, 'needs_review' => false]);
    confirmAdminPassword();
    $component = Livewire::actingAs(staffUser())->test(Bot::class);

    $this->actingAs(staffUser($role));
    $component->call($method)->assertForbidden();

    expect(BotSwitch::isEnabled())->toBeFalse()
        ->and(SettingsAudit::query()->count())->toBe(0)
        ->and(collect(telegramApiCalls())->pluck('method')->intersect(['setWebhook', 'deleteWebhook'])->all())->toBe([]);
})->with([
    'content manager' => Role::ContentManager,
    'sales manager' => Role::SalesManager,
    'service engineer' => Role::ServiceEngineer,
])->with(['refresh', 'enable', 'disable', 'setWebhook', 'deleteWebhook']);

it('changes nothing for an admin whose password confirmation is missing, stale or from the future', function (?int $confirmedAt) {
    abuseTelegramWebhookAt();
    Page::factory()->create(['slug' => 'privacy', 'is_active' => true, 'needs_review' => false]);
    Setting::query()->create(['key' => BotSwitch::KEY, 'group' => 'telegram', 'value' => true]);
    $component = Livewire::actingAs(staffUser())->test(Bot::class);

    if ($confirmedAt !== null) {
        confirmAdminPassword(-$confirmedAt);
    }

    foreach (['enable', 'disable', 'setWebhook', 'deleteWebhook'] as $method) {
        $component->call($method);
    }

    expect(BotSwitch::isEnabled())->toBeTrue()
        ->and(SettingsAudit::query()->count())->toBe(0)
        ->and(collect(telegramApiCalls())->pluck('method')->intersect(['setWebhook', 'deleteWebhook'])->all())->toBe([]);
})->with([
    'never confirmed' => [null],
    'stale' => [-3600],
    '20 minutes ago' => [-1200],
    '10 minutes in the future' => [600],
]);

// 13. Production never polls.
it('refuses telegram:poll in production without any HTTP call, even in a loop', function () {
    Http::fake();
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('telegram:poll')->assertFailed();
    $this->artisan('telegram:poll --once --timeout=1')->assertFailed();

    Http::assertNothingSent();
    expect(TelegramUpdate::query()->count())->toBe(0);
});

// 14. Alerts never repeat Telegram-controlled text.
it('sends an alert without the webhook url or error text even when they hold HTML or a token', function () {
    abuseBotOn();
    $hostile = 'https://x.example/<script>alert(1)</script>?t=987654321:AAHostileLookingToken_0123456789abcdefghi';
    Http::fake(['api.telegram.org/*' => function ($request) use ($hostile) {
        return match (basename(parse_url($request->url(), PHP_URL_PATH))) {
            'getWebhookInfo' => Http::response(['ok' => true, 'result' => [
                'url' => $hostile,
                'pending_update_count' => 500,
                'last_error_date' => now()->unix(),
                'last_error_message' => '<b>Wrong</b> 987654321:AAHostileLookingToken_0123456789abcdefghi <a href="x">',
                'ip_address' => '203.0.113.9',
            ]]),
            default => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        };
    }]);

    $this->artisan('telegram:check-webhook')->assertSuccessful();

    $texts = collect(telegramApiCalls())
        ->filter(fn (array $call): bool => $call['method'] === 'sendMessage')
        ->pluck('data.text');

    expect($texts)->not->toBeEmpty();

    foreach ($texts as $text) {
        expect($text)->not->toContain('<script')
            ->not->toContain('AAHostile')
            ->not->toContain('987654321')
            ->not->toContain('x.example')
            ->not->toContain('<a ')
            ->not->toContain('203.0.113.9')
            ->not->toContain(TG_BOT_TOKEN);
    }
});

// 15. The kill switch is read fresh for every update.
it('reads the kill switch fresh: off, on, off within one process', function () {
    abuseTelegramWebhookAt();
    Page::factory()->create(['slug' => 'privacy', 'is_active' => true, 'needs_review' => false]);
    $spy = abuseCustomerSpy();
    $admin = staffUser();
    $switch = app(SetBotEnabled::class);

    postTelegramUpdate(telegramPrivateUpdate(1501))->assertOk();
    expect($spy->processed)->toBe(0);

    $switch->enable($admin, null);
    postTelegramUpdate(telegramPrivateUpdate(1502))->assertOk();
    expect($spy->processed)->toBe(1);

    $switch->disable($admin, null);
    postTelegramUpdate(telegramPrivateUpdate(1503))->assertOk();
    expect($spy->processed)->toBe(1);

    $switch->enable($admin, null);
    postTelegramUpdate(telegramPrivateUpdate(1504))->assertOk();
    $switch->disable(null, null);
    postTelegramUpdate(telegramPrivateUpdate(1505))->assertOk();

    expect($spy->processed)->toBe(2)
        ->and(TelegramUpdate::query()->count())->toBe(5);
});
