<?php

require_once __DIR__.'/ChatBotHelpers.php';

use App\Actions\Chat\GuardAiRequest;
use App\Actions\Chat\RecordAiUsage;
use App\Enums\ChatAction;
use App\Enums\ChatAiRole;
use App\Enums\ChatAiStatus;
use App\Enums\ChatChannel;
use App\Enums\ChatMetric;
use App\Enums\ChatMode;
use App\Enums\KeywordMatch;
use App\Enums\SettingsArea;
use App\Jobs\AnswerChatWithAi;
use App\Models\ChatAiTurn;
use App\Models\ChatAiUsageDaily;
use App\Models\ChatRule;
use App\Models\ChatRuleKeyword;
use App\Models\ChatStatDaily;
use App\Models\ChatUnmatched;
use App\Models\Setting;
use App\Models\TelegramUser;
use App\Services\Ai\AiFailed;
use App\Services\Ai\AiResult;
use App\Support\Chat\AiSwitch;
use App\Support\Chat\ChatbotSwitch;
use App\Support\Chat\ChatContext;
use App\Support\Chat\ChatReply;
use App\Support\Chat\ChatSettings;
use App\Support\Chat\Engine;
use App\Support\Chat\LeadDialog;
use App\Support\Chat\RuleIndex;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeChatModel;

/**
 * Plan 30 phase A: AI answers in the Telegram chat bot. Engine direct-vs-AI
 * rules, the webhook (guard, redacted turn, job after commit, no API call),
 * the job (happy path, re-checks, replay on failure), limits and caps.
 * Fake keys and the fake model only (AI-25).
 */
const AI_FLOW_KEY = 'sk-ant-test-FLOWKEY-000111222333444555';

const AI_PHRASE = 'zorbqiz tarmoqda ishlaydimi';

function aiOn(bool $stored = true): void
{
    config(['services.anthropic.key' => AI_FLOW_KEY]);
    Setting::query()->updateOrCreate(['key' => AiSwitch::keyFor(ChatChannel::Telegram)], ['group' => SettingsArea::Chatbot->group(), 'value' => $stored]);
    ChatbotSwitch::forget();
}

/**
 * @param  list<array{0: string, 1?: KeywordMatch, 2?: int}>  $keywords
 * @param  array<string, mixed>  $attributes
 */
function aiRule(string $key, array $keywords, array $attributes = []): ChatRule
{
    $rule = ChatRule::factory()->create(array_merge([
        'key' => $key,
        'name' => ucfirst($key),
        'action' => ChatAction::Answer,
        'answer' => ['uz' => "{$key} uz", 'ru' => "{$key} ru", 'en' => "{$key} en"],
    ], $attributes));

    foreach ($keywords as $keyword) {
        [$phrase, $type, $weight] = array_pad($keyword, 3, null);
        ChatRuleKeyword::factory()->phrase($phrase, $type ?? KeywordMatch::Word, $weight ?? 20)->create(['chat_rule_id' => $rule->id]);
    }

    RuleIndex::bump();

    return $rule;
}

function aiAsk(string $text, bool $ai = true, string $locale = 'en'): ChatReply
{
    return app(Engine::class)->respond(new ChatContext(ChatChannel::Telegram, $locale, $text), $ai);
}

beforeEach(function () {
    cbSetup();
});

/*
| Engine (§2.2, P5, AI-20)
*/

it('sends a weak answer hit, model-like text and unknown text to AI with the complete original reply', function () {
    $rule = aiRule('zorbwarranty', [['zorbkafolat']]);

    $weak = aiAsk('zorbkafolat qancha muddat');
    $model = aiAsk('ZQ9X41');
    $unknown = aiAsk(AI_PHRASE);

    expect($weak->outcome)->toBe(ChatReply::AI)
        ->and($weak->ruleId)->toBe($rule->id)
        ->and($weak->original->outcome)->toBe(ChatReply::HIT)
        ->and($weak->original->text)->toBe('zorbwarranty en')
        ->and($model->original->outcome)->toBe(ChatReply::SEARCH)
        ->and($unknown->original->outcome)->toBe(ChatReply::UNMATCHED)
        ->and($unknown->ruleId)->toBeNull();
});

it('answers directly for an exact keyword, a strong score and every deterministic action', function () {
    aiRule('zorbexact', [['zorbsalom', KeywordMatch::Exact]]);
    aiRule('zorbstrong', [['zorbalfa'], ['zorbbeta']]);

    expect(aiAsk('zorbsalom')->outcome)->toBe(ChatReply::HIT)
        ->and(aiAsk('zorbalfa zorbbeta')->outcome)->toBe(ChatReply::HIT);
});

it('never sends a side-effect or deterministic rule to AI', function (ChatAction $action, array $params) {
    aiRule('zorbaction', [['zorbaction']], ['action' => $action, 'params' => $params]);

    expect(aiAsk('zorbaction iltimos')->outcome)->not->toBe(ChatReply::AI);
})->with([
    'handoff' => [ChatAction::Handoff, []],
    'order status' => [ChatAction::OrderStatus, []],
    'repair status' => [ChatAction::RepairStatus, []],
    'lead dialog' => [ChatAction::LeadDialog, ['flow' => 'quote']],
    'menu' => [ChatAction::Menu, []],
    'product search' => [ChatAction::ProductSearch, []],
    'company info' => [ChatAction::CompanyInfo, ['topic' => 'contacts']],
    'link' => [ChatAction::Link, ['target' => 'catalog']],
]);

it('keeps lookups, empty text and an unavailable index away from AI', function () {
    expect(aiAsk('O-2026-0001')->outcome)->toBe(ChatReply::ORDER_STATUS)
        ->and(aiAsk('🙂👍')->outcome)->toBe(ChatReply::FALLBACK);
});

it('answers exactly as before when AI is off', function (string $text) {
    aiRule('zorbwarranty', [['zorbkafolat']]);

    expect(aiAsk($text, ai: false))->toEqual(app(Engine::class)->respond(new ChatContext(ChatChannel::Telegram, 'en', $text)))
        ->and(aiAsk($text, ai: false)->outcome)->not->toBe(ChatReply::AI);
})->with(['zorbkafolat bormi', 'ZQ9X41', AI_PHRASE, '🙂', 'O-2026-0001']);

it('decides on AI without a single database query on a warm cache', function () {
    aiRule('zorbwarranty', [['zorbkafolat']]);
    app(RuleIndex::class)->load();
    ChatSettings::all();

    expect(countQueries(function () {
        aiAsk(AI_PHRASE);
        aiAsk('ZQ9X41');
        app(Engine::class)->aiReason(new ChatContext(ChatChannel::Telegram, 'uz', AI_PHRASE));
    }))->toBe(0);
});

/*
| Webhook (P3, AI-2, AI-6)
*/

it('stores the redacted turn and queues the job after commit, with no API call in the webhook', function () {
    Queue::fake();
    aiOn();
    $fake = FakeChatModel::bind('unused');
    $user = cbUser();

    cbText(AI_PHRASE.' +998 90 765 43 21 ali@mail.uz');

    $turn = ChatAiTurn::query()->sole();

    expect($turn->role)->toBe(ChatAiRole::User)
        ->and($turn->text)->toContain('zorbqiz')
        ->and($turn->text)->not->toContain('765')
        ->and($turn->text)->not->toContain('ali@mail.uz')
        ->and($user->fresh()->bot_dialog['ai'])->toBe($turn->id)
        ->and($fake->calls())->toBe(0)
        ->and(cbSentCount())->toBe(0)
        ->and(new AnswerChatWithAi(1, 1))->toBeInstanceOf(ShouldQueueAfterCommit::class);

    Queue::assertPushed(AnswerChatWithAi::class, function (AnswerChatWithAi $job) use ($turn, $user): bool {
        $payload = serialize($job);

        return $job->turnId === $turn->id && $job->telegramUserId === $user->id
            && ! str_contains($payload, AI_FLOW_KEY) && ! str_contains($payload, 'zorbqiz');
    });
});

it('stores no turn and answers by the rules while AI is off or the key is missing', function (bool $key, bool $stored) {
    Queue::fake();
    aiOn($stored);

    if (! $key) {
        config(['services.anthropic.key' => '']);
    }

    cbUser();
    cbText(AI_PHRASE);

    expect(ChatAiTurn::query()->count())->toBe(0)
        ->and(ChatUnmatched::query()->count())->toBe(1);

    Queue::assertNotPushed(AnswerChatWithAi::class);
})->with([
    'switch off' => [true, false],
    'key missing' => [false, true],
]);

/*
| Job (AI-15, AI-16, P4, P5)
*/

it('answers through the job with the AI marker and the hand-off button, and counts usage', function () {
    aiOn();
    $fake = FakeChatModel::bind('Poson zorbqiz uchun mos qurilmani tanlab, sozlab beradi.');
    $user = cbUser(['name' => 'Aziz Karimov', 'username' => 'aziz_k', 'phone' => '+998901112233']);

    cbText(AI_PHRASE.' buyurtma O-2026-0042 va taʼmir R-2026-12345');

    $answer = ChatAiTurn::query()->where('role', ChatAiRole::Assistant->value)->sole();
    $sent = array_values(array_filter(telegramApiCalls(), fn (array $call) => $call['method'] === 'sendMessage'));
    $request = $fake->last();

    expect($fake->calls())->toBe(1)
        ->and($answer->status)->toBe(ChatAiStatus::Answered)
        ->and($answer->answers_turn_id)->toBe(ChatAiTurn::query()->where('role', 'user')->value('id'))
        ->and($answer->prompt_hash)->toHaveLength(64)
        ->and(cbLast())->toContain(e(__('chatbot.ai.marker', [], 'en')))
        ->and(cbLast())->toContain('zorbqiz uchun mos')
        ->and(json_encode(end($sent)['data']['reply_markup'] ?? []))->toContain('chat:h')
        ->and(collect(telegramApiCalls())->pluck('method'))->toContain('sendChatAction')
        ->and($user->fresh()->bot_dialog['ai'] ?? null)->toBeNull()
        ->and(ChatStatDaily::query()->where('metric', ChatMetric::Ai->value)->sum('count'))->toEqual(1);

    $usage = ChatAiUsageDaily::query()->sole();

    expect($usage->requests)->toBe(1)
        ->and($usage->answered)->toBe(1)
        ->and($usage->reserved_tokens)->toBe(0)
        ->and($usage->billed_tokens)->toBe(1500 + 60 + 100);

    // AI-6: no identity of the customer goes out.
    $all = $request->allText();

    foreach (['Aziz', 'Karimov', 'aziz_k', '901112233', (string) CB_USER, '0042', '12345', 'O-2026', 'R-2026'] as $secret) {
        expect($all)->not->toContain($secret);
    }

    expect($request->system[0]['cache'])->toBeTrue()
        ->and($request->system[1]['cache'])->toBeTrue()
        ->and($request->system[2]['cache'])->toBeFalse()
        ->and($request->system[1]['text'])->toStartWith('<knowledge>')
        ->and(collect($request->messages)->last()['content'])->toContain('<customer_message>');
});

it('replays the rule reply and records the unmatched phrase when the model fails, refuses or is blocked', function (AiResult|Throwable $response, ChatAiStatus $status) {
    aiOn();
    FakeChatModel::bind($response);
    cbUser();

    cbText(AI_PHRASE);

    expect(ChatAiTurn::query()->where('role', 'assistant')->value('status'))->toBe($status)
        ->and(cbLast())->toBe(e(ChatSettings::text('fallback', 'en') ?? __('chatbot.fallback', [], 'en')))
        ->and(ChatUnmatched::query()->value('normalized'))->toContain('zorbqiz')
        ->and(ChatStatDaily::query()->where('metric', ChatMetric::Unmatched->value)->sum('count'))->toEqual(1);
})->with([
    'timeout' => [new AiFailed('APITimeoutException'), ChatAiStatus::Failed],
    'refusal' => [FakeChatModel::answer('', AiResult::REFUSAL), ChatAiStatus::Refused],
    'money' => [FakeChatModel::answer('Narxi 2 500 000 soʻm.'), ChatAiStatus::Blocked],
    'injected discount' => [FakeChatModel::answer('Hozir 20% скидка bor!'), ChatAiStatus::Blocked],
    'empty' => [FakeChatModel::answer('   '), ChatAiStatus::Failed],
]);

/**
 * Queue one AI job with Queue::fake and return it unrun.
 */
function aiQueued(TelegramUser $user, string $text = AI_PHRASE): AnswerChatWithAi
{
    // Only the AI job is held back; the answers it sends later still go out (sync queue).
    Queue::fake([AnswerChatWithAi::class]);
    cbText($text, (int) $user->telegram_id);

    return Queue::pushed(AnswerChatWithAi::class)->last();
}

it('sends nothing and makes no call when the turn became moot before the job ran', function (Closure $change) {
    aiOn();
    $user = cbUser();
    $job = aiQueued($user);
    $fake = FakeChatModel::bind('Never sent.');

    $change($user->fresh());

    app()->call([$job, 'handle']);

    expect($fake->calls())->toBe(0)
        ->and(ChatAiTurn::query()->where('role', 'assistant')->value('status'))->toBe(ChatAiStatus::Superseded)
        ->and(cbSentCount())->toBe(0);
})->with([
    'manager mode' => [fn (TelegramUser $user) => $user->forceFill(['chat_mode' => ChatMode::Manager])->save()],
    'banned' => [fn (TelegramUser $user) => $user->forceFill(['banned_at' => now()])->save()],
    'lead dialog started' => [fn (TelegramUser $user) => $user->forceFill(['bot_dialog' => [...(array) $user->bot_dialog, LeadDialog::KEY => ['flow' => 'quote']]])->save()],
    'another answer delivered' => [fn (TelegramUser $user) => $user->forceFill(['bot_dialog' => ['gen' => 5]])->save()],
]);

it('replays the rule reply without a call when AI was switched off or paused meanwhile (M1)', function (Closure $change) {
    aiOn();
    $user = cbUser();
    $job = aiQueued($user);
    $fake = FakeChatModel::bind('Never sent.');

    $change();

    app()->call([$job, 'handle']);

    expect($fake->calls())->toBe(0)
        ->and(ChatAiTurn::query()->where('role', 'assistant')->value('status'))->toBe(ChatAiStatus::Disabled)
        ->and(cbLast())->toBe(e(ChatSettings::text('fallback', 'en') ?? __('chatbot.fallback', [], 'en')))
        ->and(ChatUnmatched::query()->count())->toBe(1);
})->with([
    'switch off' => [function () {
        Setting::query()->where('key', AiSwitch::keyFor(ChatChannel::Telegram))->update(['value' => json_encode(false)]);
        ChatbotSwitch::forget();
    }],
    'key removed' => [fn () => config(['services.anthropic.key' => ''])],
    'paused after 401' => [fn () => AiSwitch::markAuthFailed()],
]);

it('replays the rule reply and settles the reservation when anything throws after reserving (M2)', function () {
    aiOn();
    FakeChatModel::bind(new TypeError('broken response'));
    cbUser();

    cbText(AI_PHRASE);

    $usage = ChatAiUsageDaily::query()->sole();

    expect(cbLast())->toBe(e(ChatSettings::text('fallback', 'en') ?? __('chatbot.fallback', [], 'en')))
        ->and(ChatAiTurn::query()->where('role', 'assistant')->value('status'))->toBe(ChatAiStatus::Failed)
        ->and($usage->reserved_tokens)->toBe(0)
        ->and($usage->failed)->toBe(1);
});

it('settles and replays from failed() when the job dies (M2)', function () {
    aiOn();
    $user = cbUser();
    $job = aiQueued($user);
    Cache::put('chatbot:ai:reservation:turn-'.$job->turnId, ['day' => RecordAiUsage::day(), 'tokens' => 900], 3600);
    ChatAiUsageDaily::factory()->create(['day' => RecordAiUsage::day(), 'requests' => 1, 'answered' => 0, 'failed' => 0, 'reserved_tokens' => 900]);

    $job->failed(new RuntimeException('worker killed'));

    expect(ChatAiUsageDaily::query()->value('reserved_tokens'))->toBe(0)
        ->and(ChatAiUsageDaily::query()->value('failed'))->toBe(1)
        ->and(ChatAiTurn::query()->where('role', 'assistant')->value('status'))->toBe(ChatAiStatus::Failed)
        ->and(cbSentCount())->toBe(1);
});

it('pauses AI after a 401: the next message stores no turn and queues no job (M3)', function () {
    aiOn();
    FakeChatModel::bind(new AiFailed('AuthenticationException', 401));
    cbUser();

    cbText(AI_PHRASE);

    expect(AiSwitch::authFailed())->toBeTrue();

    Queue::fake();
    cbText(AI_PHRASE.' yana');

    Queue::assertNotPushed(AnswerChatWithAi::class);
    expect(ChatAiTurn::query()->where('role', 'user')->count())->toBe(1);
});

it('answers a rule directly from the job when the rules changed meanwhile (code review 5)', function () {
    aiOn();
    $user = cbUser();
    $job = aiQueued($user);
    aiRule('zorbnow', [['zorbqiz', KeywordMatch::Word, 40]]);
    $fake = FakeChatModel::bind('Never sent.');

    app()->call([$job, 'handle']);

    expect($fake->calls())->toBe(0)
        ->and(cbLast())->toBe('zorbnow en')
        ->and(ChatAiTurn::query()->where('role', 'assistant')->value('stop_reason'))->toBe('direct');
});

it('supersedes an older turn when a newer message is queued', function () {
    aiOn();
    $user = cbUser();
    $first = aiQueued($user);
    cbText('zorbqiz yana bir savolim bor');
    $fake = FakeChatModel::bind('Never sent.');

    app()->call([$first, 'handle']);

    expect($fake->calls())->toBe(0)
        ->and(ChatAiTurn::query()->where('answers_turn_id', $first->turnId)->value('status'))->toBe(ChatAiStatus::Superseded);
});

it('answers a turn once even when the job runs twice', function () {
    aiOn();
    $user = cbUser();
    $job = aiQueued($user);
    $fake = FakeChatModel::bind('Bir marta javob.', 'Ikkinchi marta.');

    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);

    expect($fake->calls())->toBe(1)
        ->and(ChatAiTurn::query()->where('role', 'assistant')->count())->toBe(1);
});

it('keeps the job below the queue retry_after and runs it once', function () {
    $job = new AnswerChatWithAi(1, 1);

    expect($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'))
        ->and($job->tries)->toBe(1);
});

/*
| Limits and caps (P6, AI-17 – AI-19)
*/

it('answers an over-limit customer once with the hand-off button, never an automatic hand-off', function () {
    Queue::fake();
    aiOn();
    config(['chatbot.ai.limits.per_minute_user' => 2]);
    $user = cbUser();

    cbText(AI_PHRASE.' bir');
    cbText(AI_PHRASE.' ikki');
    Queue::fake();
    cbText(AI_PHRASE.' uch');
    cbText(AI_PHRASE.' tort');

    Queue::assertNotPushed(AnswerChatWithAi::class);

    expect(ChatAiTurn::query()->count())->toBe(2)
        ->and($user->fresh()->chat_mode)->toBe(ChatMode::Bot)
        ->and(ChatUnmatched::query()->count())->toBe(1)
        ->and(ChatAiUsageDaily::query()->value('capped'))->toBe(2);
});

it('falls back to the rules when the daily request cap is reached', function () {
    Queue::fake();
    aiOn();
    ChatAiUsageDaily::factory()->create(['day' => RecordAiUsage::day(), 'requests' => 1500]);
    cbUser();

    cbText(AI_PHRASE);

    Queue::assertNotPushed(AnswerChatWithAi::class);
    expect(ChatAiTurn::query()->count())->toBe(0);
});

it('reserves the daily cap atomically: two reservations at cap - 1 give one call', function () {
    aiOn();
    ChatAiUsageDaily::factory()->create(['day' => RecordAiUsage::day(), 'requests' => 1499, 'billed_tokens' => 0]);
    $usage = app(RecordAiUsage::class);

    expect($usage->reserve(ChatChannel::Telegram, 1000))->not->toBeNull()
        ->and($usage->reserve(ChatChannel::Telegram, 1000))->toBeNull()
        ->and(ChatAiUsageDaily::query()->value('requests'))->toBe(1500);
});

it('caps pending AI jobs by counting unanswered turns in the database', function () {
    Queue::fake();
    aiOn();
    config(['chatbot.ai.limits.pending_jobs' => 1]);
    $first = cbUser();
    $second = cbUser([], CB_USER + 1);
    $guard = app(GuardAiRequest::class);

    expect($guard->handle(ChatChannel::Telegram, $second)['decision'])->toBe(GuardAiRequest::ALLOWED);

    $waiting = ChatAiTurn::factory()->create(['telegram_user_id' => $first->id]);

    expect($guard->handle(ChatChannel::Telegram, $second)['decision'])->toBe(GuardAiRequest::DENIED);

    ChatAiTurn::factory()->answering($waiting)->create();

    expect($guard->handle(ChatChannel::Telegram, $second)['decision'])->toBe(GuardAiRequest::ALLOWED);
});

/*
| Context and retention (§7, AI-21)
*/

it('starts a new AI context on the menu button and deletes AI turns with the conversation', function () {
    aiOn();
    $user = cbUser();
    ChatAiTurn::factory()->create(['telegram_user_id' => $user->id]);

    cbPress('chat:menu');

    expect($user->fresh()->ai_context_at)->not->toBeNull();

    $user->delete();

    expect(ChatAiTurn::query()->count())->toBe(0);
});
