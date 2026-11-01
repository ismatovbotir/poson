<?php

use App\Actions\Settings\TestIntegrationConnection;
use App\Models\IntegrationSecret;
use App\Models\Setting;
use App\Support\Integrations\ConnectionTestResult;
use App\Support\Secrets;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

const TEST_BOT_TOKEN = '123456789:AATestTokenTestTokenTestTokenTes9876';

function saveFakeSecret(string $key, string $value): void
{
    IntegrationSecret::factory()->withValue($key, $value)->create();
    Secrets::flush();
}

function runConnectionTest(string $target, ?string $chat = null): ConnectionTestResult
{
    return app(TestIntegrationConnection::class)->handle(test()->user, $target, $chat);
}

beforeEach(function () {
    $this->user = staffUser();
    freshSecrets();
    Http::preventStrayRequests();
    Exceptions::fake();

    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event): void {
        $this->logged[] = $event->message.' '.json_encode($event->context);
    });
});

afterEach(function () {
    // Nothing the tests do may leak the token into logs or reported exceptions.
    expect(implode("\n", $this->logged))->not->toContain('AATestToken')->not->toContain('fake-key');
    Exceptions::assertNothingReported();
});

it('reports not configured without calling out', function (string $target) {
    Http::fake();

    expect(runConnectionTest($target)->code)->toBe('not_configured');

    Http::assertNothingSent();
})->with(['telegram']);

describe('telegram', function () {
    it('calls getMe with the saved token and shows the bot', function () {
        saveFakeSecret('telegram.bot_token', TEST_BOT_TOKEN);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['username' => 'poson_bot']])]);

        $result = runConnectionTest('telegram');

        expect($result->ok)->toBeTrue()
            ->and($result->message())->toContain('@poson_bot');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/bot'.TEST_BOT_TOKEN.'/getMe');
    });

    it('maps a rejected token', function () {
        saveFakeSecret('telegram.bot_token', TEST_BOT_TOKEN);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

        $result = runConnectionTest('telegram');

        expect($result->ok)->toBeFalse()->and($result->code)->toBe('unauthorized')
            ->and($result->message())->not->toContain('AATestToken');
    });

    it('maps a timeout without leaking the URL', function () {
        saveFakeSecret('telegram.bot_token', TEST_BOT_TOKEN);
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out for https://api.telegram.org/bot'.TEST_BOT_TOKEN.'/getMe'));

        $result = runConnectionTest('telegram');

        expect($result->code)->toBe('timeout')
            ->and($result->message())->toContain((string) TestIntegrationConnection::TIMEOUT)
            ->and(json_encode($result->toArray()))->not->toContain('AATestToken');
    });

    it('maps any other connection failure', function () {
        saveFakeSecret('telegram.bot_token', TEST_BOT_TOKEN);
        Http::fake(fn () => throw new ConnectionException('Could not resolve host for https://api.telegram.org/bot'.TEST_BOT_TOKEN.'/getMe'));

        expect(runConnectionTest('telegram')->code)->toBe('connection_failed');
    });

    it('sends a test message to a configured chat', function () {
        saveFakeSecret('telegram.bot_token', TEST_BOT_TOKEN);
        Setting::factory()->create(['key' => 'telegram.chats.sales', 'group' => 'integrations', 'value' => '-1001234567890']);
        Secrets::flush();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        expect(runConnectionTest('telegram_chat', 'sales')->code)->toBe('telegram_message_sent');

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '-1001234567890'
            && str_contains($request['text'], 'Poson'));
    });

    it('writes the test message in the Telegram language, Uzbek by default', function (?string $locale, string $expected) {
        saveFakeSecret('telegram.bot_token', TEST_BOT_TOKEN);
        Setting::factory()->create(['key' => 'telegram.chats.sales', 'group' => 'integrations', 'value' => '-1001234567890']);

        if ($locale !== null) {
            Setting::factory()->create(['key' => 'telegram.locale', 'group' => 'integrations', 'value' => $locale]);
        }

        Secrets::flush();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        expect(runConnectionTest('telegram_chat', 'sales')->code)->toBe('telegram_message_sent');

        Http::assertSent(fn (Request $request): bool => $request['text'] === e($expected));
    })->with([
        'default uz' => [null, fn () => __('admin.settings.integrations.test.telegram_message', ['chat' => __('admin.settings.integrations.fields.telegram_chats_sales', [], 'uz')], 'uz')],
        'ru' => ['ru', fn () => __('admin.settings.integrations.test.telegram_message', ['chat' => __('admin.settings.integrations.fields.telegram_chats_sales', [], 'ru')], 'ru')],
        'en' => ['en', fn () => __('admin.settings.integrations.test.telegram_message', ['chat' => __('admin.settings.integrations.fields.telegram_chats_sales', [], 'en')], 'en')],
    ]);

    it('needs a chat ID before sending', function () {
        saveFakeSecret('telegram.bot_token', TEST_BOT_TOKEN);
        Http::fake();

        expect(runConnectionTest('telegram_chat', 'finance')->code)->toBe('chat_not_configured');

        Http::assertNothingSent();
    });

    it('maps chat errors', function (int $status, string $code) {
        saveFakeSecret('telegram.bot_token', TEST_BOT_TOKEN);
        Setting::factory()->create(['key' => 'telegram.chats.service', 'group' => 'integrations', 'value' => '-100555']);
        Secrets::flush();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], $status)]);

        expect(runConnectionTest('telegram_chat', 'service')->code)->toBe($code);
    })->with([
        'chat not found' => [400, 'chat_not_found'],
        'bot blocked' => [403, 'chat_forbidden'],
        'server error' => [502, 'server_error'],
    ]);

    it('rejects an unknown chat', function () {
        runConnectionTest('telegram_chat', 'everyone');
    })->throws(InvalidArgumentException::class);
});

it('rejects the removed AI and remove.bg targets', function (string $target) {
    Http::fake();

    expect(TestIntegrationConnection::TARGETS)->toBe(['telegram', 'telegram_chat', 'uzqr'])
        ->and(fn () => runConnectionTest($target))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with(['anthropic', 'openrouter', 'openai', 'removebg']);

it('tests the .env value when the field is locked', function () {
    saveFakeSecret('telegram.bot_token', '987654321:AATestTokenAdminValueAdminValueAdm11');
    freshSecrets(['services.telegram.bot_token' => TEST_BOT_TOKEN]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['username' => 'poson_bot']])]);

    runConnectionTest('telegram');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/bot'.TEST_BOT_TOKEN.'/getMe');
});

it('allows 5 tests per minute per user', function () {
    saveFakeSecret('telegram.bot_token', TEST_BOT_TOKEN);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['username' => 'poson_bot']])]);

    foreach (range(1, TestIntegrationConnection::MAX_ATTEMPTS) as $attempt) {
        expect(runConnectionTest('telegram')->code)->toBe('telegram_ok');
    }

    $result = runConnectionTest('telegram');

    expect($result->code)->toBe('throttled')
        ->and($result->details['seconds'])->toBeGreaterThan(0);

    Http::assertSentCount(TestIntegrationConnection::MAX_ATTEMPTS);

    // Another user has their own limit.
    expect(app(TestIntegrationConnection::class)->handle(staffUser(), 'telegram')->code)->toBe('telegram_ok');
});

it('uses a 5 second timeout', function () {
    expect(TestIntegrationConnection::TIMEOUT)->toBe(5);
});
