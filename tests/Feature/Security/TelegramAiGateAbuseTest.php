<?php

require_once __DIR__.'/../Chat/ChatBotHelpers.php';

use App\Actions\Chat\GuardAiRequest;
use App\Actions\Telegram\HandleTelegramUpdate;
use App\Actions\Telegram\ScrubBannedTelegramUsers;
use App\Enums\ChatAiRole;
use App\Enums\ChatAiStatus;
use App\Enums\ChatChannel;
use App\Enums\SettingsArea;
use App\Enums\TelegramUserState;
use App\Jobs\AnswerChatWithAi;
use App\Models\ChatAiTurn;
use App\Models\Setting;
use App\Models\TelegramUser;
use App\Support\Chat\AiSwitch;
use App\Support\Chat\ChatbotSwitch;
use App\Support\Telegram\Bot\BotLimits;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\FakeChatModel;

/**
 * Registration gate of the AI answers (decision 2026-11-16): abuse cases 1-7
 * of the security sign-off. Updates run through HandleTelegramUpdate; Telegram
 * and the Anthropic host are Http::fake, the model is the FakeChatModel, fake
 * key only.
 */
const AIG_KEY = 'sk-ant-test-GATEKEY-1122334455667788';

function aigSetup(): void
{
    cbSetup();
    Http::fake([
        'api.anthropic.com/*' => Http::response(['content' => []], 500),
        'api.telegram.org/*' => fn () => Http::response(['ok' => true, 'result' => ['message_id' => random_int(1000, 9999999)]]),
    ]);
}

function aigAiOn(): void
{
    config(['services.anthropic.key' => AIG_KEY]);
    Setting::query()->updateOrCreate(['key' => AiSwitch::keyFor(ChatChannel::Telegram)], ['group' => SettingsArea::Chatbot->group(), 'value' => true]);
    ChatbotSwitch::forget();
}

function aigUnregistered(array $attributes = []): TelegramUser
{
    return TelegramUser::factory()->unregistered()->create(array_merge(['telegram_id' => CB_USER, 'locale' => 'en'], $attributes));
}

/**
 * @param  array<string, mixed>  $extra  Merged into the message (forward_origin ...)
 */
function aigContact(int $contactUserId, array $extra = [], int $telegramId = CB_USER): string
{
    $message = array_merge(['contact' => ['phone_number' => '+998901234567', 'first_name' => 'Ali', 'user_id' => $contactUserId]], $extra);

    $update = telegramPrivateUpdate(cbNextId(), $message, $telegramId);
    unset($update['message']['text']);

    return app(HandleTelegramUpdate::class)->handle($update);
}

/**
 * @return list<string> Texts of every message the bot sent, in order
 */
function aigSentTexts(): array
{
    return collect(telegramApiCalls())->where('method', 'sendMessage')->map(fn (array $call) => (string) $call['data']['text'])->values()->all();
}

function aigLang(string $key, string $locale = 'en'): string
{
    return e(__("telegram_bot.{$key}", [], $locale));
}

function aigAnthropicCalls(): int
{
    return Http::recorded()->filter(fn (array $pair): bool => str_contains($pair[0]->url(), 'api.anthropic.com'))->count();
}

beforeEach(function () {
    aigSetup();
    RateLimiter::clear(BotLimits::NEW_USERS_KEY);
});

// 1. The intro is sent once, never AI.
it('case 1: sends one intro to 20 texts and stickers from a Contact user, then use_button, with no AI call', function () {
    aigAiOn();
    $fake = FakeChatModel::bind('Never.');
    $user = aigUnregistered();

    foreach (range(1, BotLimits::PER_MINUTE) as $i) {
        $i % 2 === 0
            ? app(HandleTelegramUpdate::class)->handle(telegramPrivateUpdate(cbNextId(), ['sticker' => ['file_id' => 'abc', 'emoji' => 'x']], CB_USER))
            : cbText("salom {$i}");
    }

    $texts = collect(aigSentTexts());

    expect($texts->filter(fn (string $text) => $text === aigLang('contact.intro')))->toHaveCount(1)
        ->and($texts->first())->toBe(aigLang('contact.intro'))
        ->and($texts->filter(fn (string $text) => $text === aigLang('contact.use_button')))->toHaveCount(BotLimits::PER_MINUTE - 1)
        ->and($user->fresh()->state)->toBe(TelegramUserState::Contact)
        ->and($user->fresh()->contact_intro_sent_at)->not->toBeNull()
        ->and($fake->calls())->toBe(0)
        ->and(aigAnthropicCalls())->toBe(0)
        ->and(ChatAiTurn::query()->count())->toBe(0);

    // Over the per-minute limit the bot's own limiter still applies: no more replies.
    $before = count(aigSentTexts());
    cbText('one more');
    cbText('and another');

    expect(count(aigSentTexts()) - $before)->toBeLessThanOrEqual(1);
});

// 2. The marker is set once.
it('case 2: sends exactly one intro for two back to back updates and keeps the first marker', function () {
    $user = aigUnregistered();

    cbText('birinchi');
    $marker = $user->fresh()->contact_intro_sent_at;
    cbText('ikkinchi');

    $texts = collect(aigSentTexts());

    expect($marker)->not->toBeNull()
        ->and($texts->filter(fn (string $text) => $text === aigLang('contact.intro')))->toHaveCount(1)
        ->and($texts->filter(fn (string $text) => $text === aigLang('contact.use_button')))->toHaveCount(1)
        ->and($user->fresh()->contact_intro_sent_at->equalTo($marker))->toBeTrue();
});

// 3. A rejected contact never burns the intro.
it('case 3: answers not_own to a forwarded or foreign contact, with no intro and no marker', function (Closure $send) {
    $user = aigUnregistered();

    $send();

    expect(aigSentTexts())->toContain(aigLang('contact.not_own'))
        ->and(aigSentTexts())->not->toContain(aigLang('contact.intro'))
        ->and($user->fresh()->contact_intro_sent_at)->toBeNull()
        ->and($user->fresh()->phone)->toBeNull()
        ->and($user->fresh()->state)->toBe(TelegramUserState::Contact);
})->with([
    'another person' => [fn () => aigContact(9990001)],
    'forwarded own contact' => [fn () => aigContact(CB_USER, ['forward_origin' => ['type' => 'user', 'date' => 1]])],
]);

// 4. No AI before registration is complete.
it('case 4: queues no AI job and stores no AI turn for Language, Contact and Name users', function (TelegramUserState $state) {
    aigAiOn();
    FakeChatModel::bind('Never.');
    Queue::fake([AnswerChatWithAi::class]);
    TelegramUser::factory()->unregistered()->create(['telegram_id' => CB_USER, 'locale' => 'en', 'state' => $state, 'name' => $state === TelegramUserState::Name ? 'Ali' : null]);

    cbText('zorbqiz tarmoqda ishlaydimi');

    Queue::assertNotPushed(AnswerChatWithAi::class);

    expect(ChatAiTurn::query()->count())->toBe(0)
        ->and(aigAnthropicCalls())->toBe(0);
})->with([TelegramUserState::Language, TelegramUserState::Contact, TelegramUserState::Name]);

// 5. A half-registered row is denied before any quota is spent.
it('case 5: denies a Registered user without phone, registered_at or a supported locale before any limiter hit', function (array $broken) {
    aigAiOn();
    $user = TelegramUser::factory()->registered()->create(array_merge(['telegram_id' => CB_USER, 'locale' => 'en'], $broken));

    $result = app(GuardAiRequest::class)->handle(ChatChannel::Telegram, $user);
    $day = now()->format('Ymd');

    expect($result['decision'])->toBe(GuardAiRequest::DENIED)
        ->and(RateLimiter::attempts("chat-ai:user:minute:{$user->id}"))->toBe(0)
        ->and(RateLimiter::attempts("chat-ai:user:day:{$day}:{$user->id}"))->toBe(0)
        ->and(RateLimiter::attempts('chat-ai:global:minute'))->toBe(0);

    Queue::fake([AnswerChatWithAi::class]);
    $sentBefore = count(aigSentTexts());

    cbText('zorbqiz tarmoqda ishlaydimi');

    Queue::assertNotPushed(AnswerChatWithAi::class);

    expect(RateLimiter::attempts("chat-ai:user:minute:{$user->id}"))->toBe(0)
        ->and(RateLimiter::attempts('chat-ai:global:minute'))->toBe(0)
        ->and(ChatAiTurn::query()->count())->toBe(0)
        ->and(count(aigSentTexts()))->toBeGreaterThan($sentBefore);
})->with([
    'no phone' => [['phone' => null]],
    'no registered_at' => [['registered_at' => null]],
    'locale de' => [['locale' => 'de']],
]);

// 6. A job already queued re-checks the gate.
it('case 6: supersedes a queued job when the phone was nulled, with no model call and nothing sent', function () {
    aigAiOn();
    $user = cbUser();
    Queue::fake([AnswerChatWithAi::class]);
    cbText('zorbqiz tarmoqda ishlaydimi');
    $job = Queue::pushed(AnswerChatWithAi::class)->last();
    $fake = FakeChatModel::bind('Never sent.');
    $sentBefore = count(aigSentTexts());

    $user->forceFill(['phone' => null])->save();
    app()->call([$job, 'handle']);

    $assistant = ChatAiTurn::query()->where('role', ChatAiRole::Assistant->value)->where('answers_turn_id', $job->turnId)->first();

    expect($job)->toBeInstanceOf(AnswerChatWithAi::class)
        ->and($fake->calls())->toBe(0)
        ->and(aigAnthropicCalls())->toBe(0)
        ->and($assistant?->status)->toBe(ChatAiStatus::Superseded)
        ->and(count(aigSentTexts()))->toBe($sentBefore);
});

// 7. The scrub resets the intro marker with the rest.
it('case 7: clears contact_intro_sent_at and returns a scrubbed banned user to Language', function () {
    $user = aigUnregistered(['contact_intro_sent_at' => now()->subYear(), 'banned_at' => now()->subYears(2), 'last_message_at' => now()->subYears(2)]);
    $user->forceFill(['created_at' => now()->subYears(2)])->save();

    $count = app(ScrubBannedTelegramUsers::class)->handle();

    expect($count)->toBe(1)
        ->and($user->fresh()->contact_intro_sent_at)->toBeNull()
        ->and($user->fresh()->state)->toBe(TelegramUserState::Language);
});

// mayUseAi() truth table.
it('allows the AI only when every registration field is present', function (array $override, bool $expected) {
    $user = TelegramUser::factory()->registered()->make(array_merge(['locale' => 'en'], $override));

    expect($user->mayUseAi())->toBe($expected);
})->with([
    'all present' => [[], true],
    'anonymous +888 number' => [['phone' => '+88800001234567'], true],
    'phone missing' => [['phone' => null], false],
    'registered_at missing' => [['registered_at' => null], false],
    'consent_at missing' => [['consent_at' => null], false],
    'state Contact' => [['state' => TelegramUserState::Contact], false],
    'state Name' => [['state' => TelegramUserState::Name], false],
    'unsupported locale' => [['locale' => 'de'], false],
    'locale missing' => [['locale' => null], false],
]);
