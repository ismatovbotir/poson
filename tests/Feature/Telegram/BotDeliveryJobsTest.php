<?php

use App\Actions\Telegram\HandleTelegramUpdate;
use App\Enums\SettingsArea;
use App\Enums\TelegramDirection;
use App\Enums\TelegramMessageKind;
use App\Enums\TelegramMessageStatus;
use App\Jobs\DeliverBotMessage;
use App\Jobs\SendBotPrompt;
use App\Models\Setting;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Telegram\Bot\BotKeyboards;
use App\Support\Telegram\Bot\BotSwitch;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Plan 14 v2 B3, condition 9: DeliverBotMessage (stored conversation
 * messages) and SendBotPrompt (unstored registration UI). Fake token only.
 */
function deliveryBotOn(bool $on = true): void
{
    Setting::query()->updateOrCreate(['key' => BotSwitch::KEY], ['group' => SettingsArea::TelegramBot->group(), 'value' => $on]);
}

function deliveryTelegramAnswers(int $status, array $body = []): void
{
    Http::fake(['api.telegram.org/*' => Http::response($body ?: ($status === 200 ? ['ok' => true, 'result' => ['message_id' => 4321]] : ['ok' => false, 'error_code' => $status]), $status)]);
}

function deliveryOutbound(array $attributes = []): TelegramMessage
{
    $user = TelegramUser::factory()->registered()->create(['telegram_id' => 5550001]);

    return TelegramMessage::query()->create(array_merge([
        'telegram_user_id' => $user->id,
        'direction' => TelegramDirection::Out,
        'kind' => TelegramMessageKind::Text,
        'text' => 'Hello <b>Ali</b>, your printer is ready',
        'status' => TelegramMessageStatus::Pending,
    ], $attributes));
}

/**
 * @return Closure(): list<string> JSON-encoded log records so far
 */
function deliveryCaptureLogs(): Closure
{
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = json_encode([$event->message, $event->context]);
    });

    return function () use (&$logged): array {
        return $logged;
    };
}

beforeEach(function () {
    telegramBotConfigured();
    deliveryBotOn();
});

it('is queued after commit on the default queue with ids only', function () {
    $message = deliveryOutbound();
    $job = new DeliverBotMessage($message->id);
    $prompt = new SendBotPrompt($message->telegram_user_id, ['welcome'], BotKeyboards::MENU);

    expect($job)->toBeInstanceOf(ShouldQueueAfterCommit::class)
        ->and($prompt)->toBeInstanceOf(ShouldQueueAfterCommit::class)
        ->and($job->queue)->toBe('default')
        ->and($prompt->queue)->toBe('default')
        ->and($job->backoff())->toBe([10, 60, 300, 900])
        ->and($prompt->backoff())->toBe([10, 60, 300, 900])
        ->and(serialize($job))->not->toContain('Ali')->not->toContain('5550001')->not->toContain(TG_BOT_TOKEN)
        ->and(serialize($prompt))->not->toContain('5550001')->not->toContain(TG_BOT_TOKEN);
});

it('queues registration replies with prompt keys only (no text, phone or chat id)', function () {
    Bus::fake();
    Http::fake();
    TelegramUser::factory()->unregistered()->create(['telegram_id' => 5550001, 'locale' => 'en', 'first_name' => 'Ali']);

    $update = telegramPrivateUpdate(5001, ['contact' => ['phone_number' => '998901234567', 'user_id' => 5550001]]);
    unset($update['message']['text']);

    app(HandleTelegramUpdate::class)->handle($update);

    Bus::assertDispatched(SendBotPrompt::class, function (SendBotPrompt $job): bool {
        $payload = serialize($job);

        return $job->prompts === ['name.confirm'] && ! str_contains($payload, '998901234567') && ! str_contains($payload, 'Ali') && ! str_contains($payload, '5550001');
    });
    Http::assertNothingSent();
});

it('sends a pending message as plain text and marks it sent', function () {
    deliveryTelegramAnswers(200);
    $message = deliveryOutbound();

    dispatch(new DeliverBotMessage($message->id));

    $call = collect(telegramApiCalls())->sole();
    $message->refresh();

    expect($call['method'])->toBe('sendMessage')
        ->and($call['data']['chat_id'])->toBe('5550001')
        ->and($call['data']['text'])->toBe('Hello <b>Ali</b>, your printer is ready')
        ->and($call['data'])->not->toHaveKey('parse_mode')->not->toHaveKey('reply_markup')
        ->and($message->status)->toBe(TelegramMessageStatus::Sent)
        ->and($message->telegram_message_id)->toBe(4321)
        ->and($message->sent_at)->not->toBeNull();
});

it('marks the user blocked and the message failed on 403', function () {
    deliveryTelegramAnswers(403);
    $message = deliveryOutbound();

    (new DeliverBotMessage($message->id))->withFakeQueueInteractions()->handle(app(TelegramClient::class));

    expect($message->fresh()->status)->toBe(TelegramMessageStatus::Failed)
        ->and($message->fresh()->error_code)->toBe(403)
        ->and($message->telegramUser->fresh()->blocked_at)->not->toBeNull();
});

it('fails at once on 400 and 404 without blocking the user', function (int $status) {
    deliveryTelegramAnswers($status);
    $message = deliveryOutbound();

    $job = (new DeliverBotMessage($message->id))->withFakeQueueInteractions();
    $job->handle(app(TelegramClient::class));

    $job->assertNotReleased();
    expect($message->fresh()->status)->toBe(TelegramMessageStatus::Failed)
        ->and($message->fresh()->error_code)->toBe($status)
        ->and($message->telegramUser->fresh()->blocked_at)->toBeNull();
})->with([400, 404]);

it('releases on 429 for retry_after and keeps the message pending', function () {
    deliveryTelegramAnswers(429, ['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 17]]);
    $message = deliveryOutbound();

    $job = (new DeliverBotMessage($message->id))->withFakeQueueInteractions();
    $job->handle(app(TelegramClient::class));

    $job->assertReleased(delay: 17);
    expect($message->fresh()->status)->toBe(TelegramMessageStatus::Pending);
});

it('rethrows 5xx and timeouts for the backoff and fails the message when the tries run out', function (Closure $fake) {
    $fake();
    $message = deliveryOutbound();
    $job = new DeliverBotMessage($message->id);
    $caught = null;

    try {
        $job->handle(app(TelegramClient::class));
    } catch (TelegramException $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull()
        ->and($message->fresh()->status)->toBe(TelegramMessageStatus::Pending)
        ->and($message->fresh()->error_code)->toBe($caught->status > 0 ? $caught->status : null);

    $job->failed($caught);

    expect($message->fresh()->status)->toBe(TelegramMessageStatus::Failed)
        ->and($message->fresh()->error_code)->toBe($caught->status > 0 ? $caught->status : null);
})->with([
    '502' => [fn () => deliveryTelegramAnswers(502)],
    'timeout' => [fn () => Http::fake(['api.telegram.org/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')])],
]);

it('claims the message before sending: a second run during the send and a rerun send nothing', function () {
    $message = deliveryOutbound();
    $client = app(TelegramClient::class);
    $nested = false;
    Http::fake(['api.telegram.org/*' => function () use ($message, $client, &$nested) {
        if (! $nested) {
            $nested = true;
            expect($message->fresh()->status)->toBe(TelegramMessageStatus::Sending);
            (new DeliverBotMessage($message->id))->handle($client);
        }

        return Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
    }]);

    (new DeliverBotMessage($message->id))->handle($client);
    (new DeliverBotMessage($message->id))->handle($client);

    expect(collect(telegramApiCalls())->where('method', 'sendMessage'))->toHaveCount(1)
        ->and($message->fresh()->status)->toBe(TelegramMessageStatus::Sent);
});

it('sends exactly once when retried after a timeout', function () {
    $message = deliveryOutbound();
    $client = app(TelegramClient::class);
    Http::fakeSequence('api.telegram.org/*')
        ->pushFailedConnection()
        ->push(['ok' => true, 'result' => ['message_id' => 2]]);

    expect(fn () => (new DeliverBotMessage($message->id))->handle($client))->toThrow(TelegramException::class);
    (new DeliverBotMessage($message->id))->handle($client);
    (new DeliverBotMessage($message->id))->handle($client);

    expect(collect(telegramApiCalls())->where('method', 'sendMessage'))->toHaveCount(2)
        ->and($message->fresh()->status)->toBe(TelegramMessageStatus::Sent)
        ->and($message->fresh()->telegram_message_id)->toBe(2);
});

it('does not use up its retries on 429: retried for an hour, real failures capped at five', function () {
    $message = deliveryOutbound();
    $client = app(TelegramClient::class);
    $job = new DeliverBotMessage($message->id);
    $prompt = new SendBotPrompt($message->telegram_user_id, ['help']);
    deliveryTelegramAnswers(429, ['ok' => false, 'parameters' => ['retry_after' => 3]]);

    foreach (range(1, 5) as $attempt) {
        $run = (new DeliverBotMessage($message->id))->withFakeQueueInteractions();
        $run->handle($client);
        $run->assertReleased(delay: 3);
        $run->assertNotFailed();
    }

    expect($message->fresh()->status)->toBe(TelegramMessageStatus::Pending)
        ->and($message->fresh()->error_code)->toBe(429)
        ->and(property_exists($job, 'tries'))->toBeFalse()
        ->and($job->maxExceptions)->toBe(5)
        ->and($prompt->maxExceptions)->toBe(5)
        ->and($job->retryUntil()->getTimestamp())->toBeGreaterThan(now()->addMinutes(59)->getTimestamp())
        ->and($prompt->retryUntil()->getTimestamp())->toBeGreaterThan(now()->addMinutes(59)->getTimestamp());

    // Still rate-limited when the hour runs out: failed with the last code.
    $job->failed(new RuntimeException('max attempts'));
    expect($message->fresh()->status)->toBe(TelegramMessageStatus::Failed)
        ->and($message->fresh()->error_code)->toBe(429);
});

it('never logs the token, the API url or the message text', function (int $status) {
    deliveryTelegramAnswers($status);
    $logs = deliveryCaptureLogs();
    $message = deliveryOutbound();

    try {
        (new DeliverBotMessage($message->id))->withFakeQueueInteractions()->handle(app(TelegramClient::class));
    } catch (TelegramException) {
        // 5xx is rethrown for the queue.
    }

    try {
        (new SendBotPrompt($message->telegram_user_id, ['help']))->withFakeQueueInteractions()->handle(app(TelegramClient::class));
    } catch (TelegramException) {
    }

    $all = implode("\n", $logs());

    expect($logs())->not->toBeEmpty()
        ->and($all)->not->toContain('AAFakeToken')->not->toContain('api.telegram.org')->not->toContain('printer')->not->toContain('5550001');
})->with([403, 400, 429, 500]);

it('sends nothing while the kill switch is off, for messages and prompts', function () {
    Http::fake();
    deliveryBotOn(false);
    $message = deliveryOutbound();

    dispatch(new DeliverBotMessage($message->id));
    dispatch(new SendBotPrompt($message->telegram_user_id, ['help'], callbackQueryId: 'cbq-1'));

    Http::assertNothingSent();
    expect($message->fresh()->status)->toBe(TelegramMessageStatus::Pending);
});

it('skips missing rows, inbound rows, already sent messages and banned users', function () {
    Http::fake();
    $sent = deliveryOutbound(['status' => TelegramMessageStatus::Sent]);
    $inbound = TelegramMessage::query()->create(['telegram_user_id' => $sent->telegram_user_id, 'direction' => TelegramDirection::In, 'kind' => TelegramMessageKind::Text, 'text' => 'hi', 'status' => TelegramMessageStatus::Pending]);
    $banned = TelegramUser::factory()->registered()->banned()->create();
    $toBanned = TelegramMessage::query()->create(['telegram_user_id' => $banned->id, 'direction' => TelegramDirection::Out, 'kind' => TelegramMessageKind::Text, 'text' => 'x', 'status' => TelegramMessageStatus::Pending]);

    foreach ([999_999, $sent->id, $inbound->id, $toBanned->id] as $id) {
        dispatch(new DeliverBotMessage($id));
    }
    dispatch(new SendBotPrompt(999_999, ['help']));
    dispatch(new SendBotPrompt($banned->id, ['help']));

    Http::assertNothingSent();
    expect($toBanned->fresh()->status)->toBe(TelegramMessageStatus::Pending);
});

it('handles prompt failures like messages: 403 blocks, 429 releases, 5xx rethrows', function () {
    $user = TelegramUser::factory()->registered()->create(['locale' => 'en']);
    $client = app(TelegramClient::class);
    Http::fakeSequence('api.telegram.org/*')
        ->push(['ok' => false, 'error_code' => 403], 403)
        ->push(['ok' => false, 'parameters' => ['retry_after' => 9]], 429)
        ->push(['ok' => false], 503);

    (new SendBotPrompt($user->id, ['help']))->withFakeQueueInteractions()->handle($client);
    expect($user->fresh()->blocked_at)->not->toBeNull();

    $job = (new SendBotPrompt($user->id, ['help']))->withFakeQueueInteractions();
    $job->handle($client);
    $job->assertReleased(delay: 9);

    expect(fn () => (new SendBotPrompt($user->id, ['help']))->handle($client))->toThrow(TelegramException::class);
});

it('still sends the prompt when answering the callback or clearing old buttons fails', function () {
    $user = TelegramUser::factory()->registered()->create(['locale' => 'en']);
    Http::fake(['api.telegram.org/*' => fn ($request) => str_ends_with($request->url(), '/sendMessage')
        ? Http::response(['ok' => true, 'result' => ['message_id' => 1]])
        : Http::response(['ok' => false], 400)]);

    dispatch(new SendBotPrompt($user->id, ['help'], BotKeyboards::MENU, 'cbq-old', 55));

    expect(collect(telegramApiCalls())->pluck('method')->all())->toBe(['answerCallbackQuery', 'editMessageReplyMarkup', 'sendMessage'])
        ->and(collect(telegramApiCalls())->last()['data'])->not->toHaveKey('parse_mode');
});

it('drops unknown prompt keys and sends nothing for an empty prompt', function () {
    Http::fake();
    $user = TelegramUser::factory()->registered()->create(['locale' => 'en']);

    dispatch(new SendBotPrompt($user->id, ['consent.privacy_link_label', '../../secrets']));

    Http::assertNothingSent();
});
