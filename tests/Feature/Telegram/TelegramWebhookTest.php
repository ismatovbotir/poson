<?php

use App\Actions\Telegram\HandleTelegramUpdate;
use App\Actions\Telegram\TelegramUpdateFailed;
use App\Enums\SettingsArea;
use App\Enums\TelegramMessageKind;
use App\Http\Middleware\LimitJsonBody;
use App\Http\Middleware\VerifyTelegramSecret;
use App\Models\Setting;
use App\Models\TelegramUpdate;
use App\Models\TelegramUser;
use App\Support\Telegram\Bot\BotSwitch;
use App\Support\Telegram\Bot\UpdateParser;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Plan 14 v2 B2: webhook route, secret, body limit, dedupe and the update skeleton.
 */
function botSwitchOn(): void
{
    Setting::query()->create(['key' => BotSwitch::KEY, 'group' => SettingsArea::TelegramBot->group(), 'value' => true]);
}

/**
 * HandleTelegramUpdate spy: records whether processCustomer() ran.
 */
function customerProcessingSpy(): object
{
    $spy = new class extends HandleTelegramUpdate
    {
        public int $processed = 0;

        protected function processCustomer(UpdateParser $update): void
        {
            $this->processed++;
        }
    };

    app()->instance(HandleTelegramUpdate::class, $spy);

    return $spy;
}

beforeEach(function () {
    telegramBotConfigured();
    RateLimiter::clear('telegram-webhook');
});

it('refuses a missing, wrong or unconfigured secret with 401 and stores nothing', function (?string $configured, ?string $sent) {
    telegramBotConfigured(['services.telegram.webhook_secret' => $configured]);

    postTelegramUpdate(telegramPrivateUpdate(1), $sent)->assertUnauthorized();

    expect(TelegramUpdate::query()->count())->toBe(0);
})->with([
    'missing header' => [TG_WEBHOOK_SECRET, null],
    'wrong header' => [TG_WEBHOOK_SECRET, 'wrong-secret-0123456789-0123456789-abcd'],
    'no secret configured, empty header' => [null, ''],
    'no secret configured, any header' => [null, 'anything-0123456789-0123456789-abcdefgh'],
]);

it('accepts the correct secret with 200 and records the update id', function () {
    postTelegramUpdate(telegramPrivateUpdate(42))->assertOk()->assertJson(['ok' => true]);

    expect(TelegramUpdate::query()->pluck('update_id')->all())->toBe([42]);
});

it('refuses an oversized body with 413 before the secret check', function () {
    $body = json_encode(telegramPrivateUpdate(1, ['text' => str_repeat('a', LimitJsonBody::MAX_BYTES)]));

    postTelegramUpdate($body, null)->assertStatus(413);
    postTelegramUpdate($body)->assertStatus(413);

    expect(TelegramUpdate::query()->count())->toBe(0);
});

it('runs body limit, secret and throttle in that order on the route', function () {
    $route = app('router')->getRoutes()->getByName('api.telegram.webhook');

    expect($route->uri())->toBe('api/telegram/webhook')
        ->and(array_slice(app('router')->gatherRouteMiddleware($route), 0, 3))->toBe([
            LimitJsonBody::class,
            VerifyTelegramSecret::class,
            'Illuminate\Routing\Middleware\ThrottleRequests:telegram-webhook',
        ]);
});

it('handles a duplicate update id once and still answers 200', function () {
    botSwitchOn();
    $spy = customerProcessingSpy();

    postTelegramUpdate(telegramPrivateUpdate(7))->assertOk();
    postTelegramUpdate(telegramPrivateUpdate(7))->assertOk();

    expect($spy->processed)->toBe(1)
        ->and(TelegramUpdate::query()->count())->toBe(1);
});

it('drops group chats and bot senders after storing only the update id', function (array $update) {
    botSwitchOn();
    $spy = customerProcessingSpy();
    TelegramUser::factory()->create(['telegram_id' => 5550001]);
    $before = TelegramUser::query()->first()->toArray();

    postTelegramUpdate($update)->assertOk();

    expect($spy->processed)->toBe(0)
        ->and(TelegramUpdate::query()->count())->toBe(1)
        ->and(TelegramUser::query()->count())->toBe(1)
        ->and(TelegramUser::query()->first()->toArray())->toBe($before);
})->with([
    'group' => [telegramPrivateUpdate(3, ['chat' => ['id' => -100123, 'type' => 'supergroup', 'title' => 'Sales']])],
    'channel' => [telegramPrivateUpdate(4, ['chat' => ['id' => -100124, 'type' => 'channel']])],
    'bot sender' => [telegramPrivateUpdate(5, ['from' => ['id' => 5550001, 'is_bot' => true, 'first_name' => 'Bot']])],
    'group kick of the bot' => [['update_id' => 6, 'my_chat_member' => [
        'chat' => ['id' => -100125, 'type' => 'group'], 'from' => ['id' => 5550001, 'is_bot' => false],
        'new_chat_member' => ['status' => 'kicked'],
    ]]],
]);

it('does no customer processing while the kill switch is off (absent = off)', function () {
    $spy = customerProcessingSpy();

    postTelegramUpdate(telegramPrivateUpdate(11))->assertOk();
    Setting::query()->create(['key' => BotSwitch::KEY, 'group' => SettingsArea::TelegramBot->group(), 'value' => false]);
    postTelegramUpdate(telegramPrivateUpdate(12))->assertOk();

    expect($spy->processed)->toBe(0)
        ->and(app(HandleTelegramUpdate::class)->handle(telegramPrivateUpdate(13)))->toBe(HandleTelegramUpdate::DISABLED);

    Setting::query()->where('key', BotSwitch::KEY)->first()->update(['value' => true]);

    // Read fresh per update: the next one is processed without any cache flush.
    postTelegramUpdate(telegramPrivateUpdate(14))->assertOk();
    expect($spy->processed)->toBe(1);
});

it('marks a conversation blocked when the user kicks the bot and clears it on restart', function () {
    $user = TelegramUser::factory()->create(['telegram_id' => 5550001, 'blocked_at' => null]);
    $member = fn (int $id, string $status): array => ['update_id' => $id, 'my_chat_member' => [
        'chat' => ['id' => 5550001, 'type' => 'private'], 'from' => ['id' => 5550001, 'is_bot' => false],
        'old_chat_member' => ['status' => 'member'], 'new_chat_member' => ['status' => $status],
    ]];

    postTelegramUpdate($member(21, 'kicked'))->assertOk();
    expect($user->fresh()->blocked_at)->not->toBeNull();

    postTelegramUpdate($member(22, 'member'))->assertOk();
    expect($user->fresh()->blocked_at)->toBeNull();
});

it('answers 200 and reports only the update id and exception class when handling fails', function () {
    Exceptions::fake();
    botSwitchOn();

    app()->instance(HandleTelegramUpdate::class, new class extends HandleTelegramUpdate
    {
        protected function processCustomer(UpdateParser $update): void
        {
            throw new RuntimeException('secret customer text +998901234567');
        }
    });

    postTelegramUpdate(telegramPrivateUpdate(31, ['text' => 'secret customer text']))->assertOk();

    Exceptions::assertReported(fn (TelegramUpdateFailed $exception): bool => $exception->updateId === 31
        && $exception->exceptionClass === RuntimeException::class
        && ! str_contains($exception->getMessage(), 'secret')
        && $exception->getPrevious() === null);
});

it('answers 200 to a body that is not an update', function () {
    postTelegramUpdate('not json')->assertOk();
    postTelegramUpdate(['foo' => 'bar'])->assertOk();

    expect(TelegramUpdate::query()->count())->toBe(0);
});

it('parses private messages without file ids, vcards or bot-made content', function () {
    $photo = UpdateParser::fromArray(telegramPrivateUpdate(1, ['text' => null, 'caption' => 'Rekvizitlar', 'photo' => [
        ['file_id' => 'small-id', 'file_unique_id' => 'u1', 'width' => 90, 'height' => 90, 'file_size' => 1000],
        ['file_id' => 'big-id', 'file_unique_id' => 'u2', 'width' => 1280, 'height' => 960, 'file_size' => 90000],
    ]]));
    $contact = UpdateParser::fromArray(telegramPrivateUpdate(2, ['text' => null, 'contact' => [
        'phone_number' => '998901234567', 'user_id' => 5550001, 'vcard' => 'BEGIN:VCARD', 'first_name' => 'Ali',
    ]]));
    $viaBot = UpdateParser::fromArray(telegramPrivateUpdate(3, ['via_bot' => ['id' => 1, 'is_bot' => true]]));

    expect($photo->isPrivate())->toBeTrue()
        ->and($photo->kind)->toBe(TelegramMessageKind::Photo)
        ->and($photo->text)->toBe('Rekvizitlar')
        ->and($photo->media)->toBe(['file_size' => 90000, 'width' => 1280, 'height' => 960])
        ->and(serialize($photo))->not->toContain('big-id')->not->toContain('u2')
        ->and($contact->kind)->toBe(TelegramMessageKind::Contact)
        ->and($contact->contactPhone)->toBe('998901234567')
        ->and($contact->contactUserId)->toBe(5550001)
        ->and(serialize($contact))->not->toContain('VCARD')
        ->and($viaBot->kind)->toBe(TelegramMessageKind::Other)
        ->and(UpdateParser::fromArray(['update_id' => 'x']))->toBeNull();
});
