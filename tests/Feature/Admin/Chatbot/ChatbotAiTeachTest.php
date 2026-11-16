<?php

use App\Actions\Chat\SaveChatbotSettings;
use App\Enums\AiUsageChannel;
use App\Enums\ChatAiStatus;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Livewire\Admin\Chatbot\Rules\Form as RuleFormScreen;
use App\Livewire\Admin\Chatbot\Stats;
use App\Livewire\Admin\Chatbot\Unmatched\Index as UnmatchedIndex;
use App\Livewire\Admin\Telegram\Chats\Show;
use App\Models\ChatAiTurn;
use App\Models\ChatAiUsageDaily;
use App\Models\ChatRule;
use App\Models\ChatUnmatched;
use App\Models\Setting;
use App\Models\TelegramUser;
use App\Models\User;
use App\Support\Chat\ChatSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\FakeChatModel;

/**
 * Plan 30 §16 / §18 phase B: "Teach from AI answer" (AI-B11, AI-B16 –
 * AI-B18), "Translate with Claude" (AI-B14) and the inbox "Bot
 * conversation" panel (AI-B10). Fake keys and the fake model only (AI-25).
 */
const AI_TEACH_KEY = 'sk-ant-test-TEACHKEY-5556667778889990001';

/**
 * A phrase with $customers distinct customers who got an AI answer to it.
 */
function teachPhrase(int $customers = 1, string $answer = 'Zebra printerlari uchun Poson sozlash va kafolat xizmatini beradi.', string $locale = 'uz'): ChatUnmatched
{
    $unmatched = ChatUnmatched::factory()->create(['normalized' => 'zebra printer narxi qancha', 'sample' => 'Zebra printer narxi qancha?', 'locale' => $locale, 'count' => $customers]);

    foreach (range(1, $customers) as $i) {
        $question = ChatAiTurn::factory()->create(['text' => 'Zebra printer narxi qancha?']);
        ChatAiTurn::factory()->answering($question)->create(['text' => $answer, 'chat_unmatched_id' => $unmatched->id, 'locale' => $locale]);
    }

    return $unmatched;
}

function translateOn(bool $enabled = true): void
{
    config(['services.anthropic.key' => AI_TEACH_KEY]);
    Setting::query()->updateOrCreate(['key' => ChatSettings::key('ai_translate_enabled')], ['group' => SettingsArea::Chatbot->group(), 'value' => $enabled]);
    ChatSettings::forget();
}

/*
| Teach list (AI-B16)
*/

it('counts distinct customers answered by AI, sorts by it and offers "Teach from AI answer"', function () {
    $plain = ChatUnmatched::factory()->create(['count' => 50]);
    $taught = teachPhrase(2);
    $sameCustomer = ChatAiTurn::query()->where('chat_unmatched_id', $taught->id)->value('telegram_user_id');
    $again = ChatAiTurn::factory()->create(['telegram_user_id' => $sameCustomer]);
    ChatAiTurn::factory()->answering($again)->create(['chat_unmatched_id' => $taught->id]);
    $blocked = ChatAiTurn::factory()->create();
    ChatAiTurn::factory()->answering($blocked, ChatAiStatus::Blocked)->create(['chat_unmatched_id' => $taught->id]);

    $component = Livewire::actingAs(staffUser(Role::ContentManager))->test(UnmatchedIndex::class);
    $items = $component->viewData('items');

    expect($items->first()->id)->toBe($taught->id)
        ->and((int) $items->first()->ai_answered)->toBe(2)
        ->and((int) $items->firstWhere('id', $plain->id)->ai_answered)->toBe(0);

    $component->assertSee('data-ai-answered="2"', false)
        ->assertSee(route('admin.chatbot.rules.create', ['teach' => $taught->id]), false)
        ->assertDontSee('Zebra printerlari uchun Poson');
});

/*
| Teach form (AI-B11, AI-B17, AI-B18)
*/

it('pre-fills a draft from the server-picked AI answer with keywords from the phrase', function () {
    $unmatched = teachPhrase(1, 'Poson Zebra printerini tanlab, sozlab beradi.', 'ru');
    $turnId = ChatAiTurn::query()->where('chat_unmatched_id', $unmatched->id)->value('id');

    Livewire::actingAs(staffUser(Role::ContentManager))
        ->withQueryParams(['teach' => $unmatched->id])
        ->test(RuleFormScreen::class)
        ->assertSet('aiTurnId', $turnId)
        ->assertSet('teachLocale', 'ru')
        ->assertSet('form.answer.ru', 'Poson Zebra printerini tanlab, sozlab beradi.')
        ->assertSet('form.answer.uz', '')
        ->assertSet('form.is_active', false)
        ->assertSet('form.keywords', [
            ['phrase' => 'zebra', 'locale' => 'ru', 'match_type' => 'word', 'weight' => 10],
            ['phrase' => 'printer', 'locale' => 'ru', 'match_type' => 'word', 'weight' => 10],
        ])
        ->assertSee('data-teach-from-ai', false);

    expect(ChatRule::query()->where('name', 'like', 'Zebra%')->exists())->toBeFalse();
});

it('prefers an answer given without earlier conversation', function () {
    $unmatched = ChatUnmatched::factory()->create(['normalized' => 'zebra printer']);
    $clean = ChatAiTurn::factory()->create();
    $cleanAnswer = ChatAiTurn::factory()->answering($clean)->create(['text' => 'Mustaqil javob.', 'chat_unmatched_id' => $unmatched->id]);
    $user = TelegramUser::factory()->registered()->create();
    $earlier = ChatAiTurn::factory()->create(['telegram_user_id' => $user->id]);
    ChatAiTurn::factory()->answering($earlier)->create();
    $followUp = ChatAiTurn::factory()->create(['telegram_user_id' => $user->id]);
    ChatAiTurn::factory()->answering($followUp)->create(['text' => 'Davomli javob.', 'chat_unmatched_id' => $unmatched->id]);

    Livewire::actingAs(staffUser(Role::Admin))
        ->withQueryParams(['teach' => $unmatched->id])
        ->test(RuleFormScreen::class)
        ->assertSet('aiTurnId', $cleanAnswer->id);
});

it('never lets the client choose the AI turn', function () {
    $unmatched = teachPhrase();

    Livewire::actingAs(staffUser(Role::Admin))
        ->withQueryParams(['teach' => $unmatched->id])
        ->test(RuleFormScreen::class)
        ->set('aiTurnId', 999);
})->throws(CannotUpdateLockedPropertyException::class);

it('refuses to save a taught answer with links, contacts, amounts or discount wording', function (string $text) {
    $unmatched = teachPhrase();

    Livewire::actingAs(staffUser(Role::ContentManager))
        ->withQueryParams(['teach' => $unmatched->id])
        ->test(RuleFormScreen::class)
        ->set('form.answer.uz', $text)
        ->call('save')
        ->assertHasErrors('form.answer.uz');

    expect(ChatRule::query()->where('name', 'like', 'Zebra%')->exists())->toBeFalse()
        ->and($unmatched->fresh()->resolved_at)->toBeNull();
})->with([
    'injected mix' => ['20% скидка, @x, 1 250 000 soʻm'],
    'handle' => ['Bizga @poson_sale ga yozing.'],
    'amount' => ['Narxi 1 250 000 soʻm.'],
    'foreign link' => ['Batafsil: t.me/x'],
    'foreign phone' => ['Qoʻngʻiroq qiling: +998 91 765 43 21'],
    'look-alike host' => ['Sayt: poson-uz.com'],
    // Plan 30 §19 M1, M2, H1, L4.
    'foiz' => ['50 foiz kamroq'],
    'процент' => ['минус 30 процентов'],
    'skidka' => ['Skidka bor'],
    'bot handle' => ['Xizmat uchun Telegramda poson_support_bot ga yozing.'],
    'messenger handle' => ['Напишите в Telegram: posonservice'],
    'spelled domain' => ['Saytimiz posonuz dot shop'],
    'bare price' => ['Narxi 85000.'],
    'payment instruction' => ['Toʻlovni shu karta raqamiga yuboring.'],
]);

it('refuses exact or heavy keywords on a taught rule', function (string $field, mixed $value) {
    $unmatched = teachPhrase();

    Livewire::actingAs(staffUser(Role::Admin))
        ->withQueryParams(['teach' => $unmatched->id])
        ->test(RuleFormScreen::class)
        ->set("form.keywords.0.{$field}", $value)
        ->call('save')
        ->assertHasErrors('form.keywords.0');
})->with([
    'exact' => ['match_type', 'exact'],
    'heavy' => ['weight', 40],
]);

it('saves a clean taught rule as a draft and resolves the phrase', function () {
    $unmatched = teachPhrase();
    $editor = staffUser(Role::ContentManager);

    Livewire::actingAs($editor)
        ->withQueryParams(['teach' => $unmatched->id])
        ->test(RuleFormScreen::class)
        ->call('save')
        ->assertHasNoErrors();

    $rule = ChatRule::query()->latest('id')->first();

    expect($rule->is_active)->toBeFalse()
        ->and($rule->getTranslation('answer', 'uz'))->toBe('Zebra printerlari uchun Poson sozlash va kafolat xizmatini beradi.')
        ->and($rule->keywords->pluck('match_type')->map->value->unique()->all())->toBe(['word'])
        ->and($unmatched->fresh()->resolved_rule_id)->toBe($rule->id);
});

it('opens a blank form when the phrase has no delivered AI answer', function () {
    $unmatched = ChatUnmatched::factory()->create();
    $failed = ChatAiTurn::factory()->create();
    ChatAiTurn::factory()->answering($failed, ChatAiStatus::Failed)->create(['chat_unmatched_id' => $unmatched->id]);

    Livewire::actingAs(staffUser(Role::Admin))
        ->withQueryParams(['teach' => $unmatched->id])
        ->test(RuleFormScreen::class)
        ->assertSet('aiTurnId', 0)
        ->assertSet('form.answer.uz', '');
});

it('refuses teaching to a role without chatbot.rules', function () {
    $unmatched = teachPhrase();

    $this->actingAs(staffUser(Role::SalesManager))
        ->get(route('admin.chatbot.rules.create', ['teach' => $unmatched->id]))
        ->assertForbidden();
});

/*
| Translate with Claude (AI-B14)
*/

it('fills the other two locales from one, through the model, with its own usage channel', function (Role $role) {
    translateOn();
    $fake = FakeChatModel::bind('{"answer": {"ru": "Poson настроит принтер Zebra.", "en": "Poson sets up the Zebra printer."}}');
    $rules = ChatRule::query()->count();

    Livewire::actingAs(staffUser($role))
        ->test(RuleFormScreen::class)
        ->set('form.answer.uz', 'Poson Zebra printerini sozlab beradi.')
        ->call('translate', 'uz')
        ->assertSet('form.answer.ru', 'Poson настроит принтер Zebra.')
        ->assertSet('form.answer.en', 'Poson sets up the Zebra printer.')
        ->assertDispatched('toast');

    $request = $fake->last();

    expect($fake->calls())->toBe(1)
        ->and($request->system)->toHaveCount(1)
        ->and($request->maxTokens)->toBe(800)
        ->and($request->messages)->toHaveCount(1)
        ->and($request->messages[0]['content'])->toContain('<source_text lang="uz">')->toContain('Poson Zebra printerini')
        ->and($request->allText())->not->toContain('<knowledge>')->not->toContain('<customer_message>')
        ->and(ChatRule::query()->count())->toBe($rules);

    $usage = ChatAiUsageDaily::query()->where('channel', 'adm_trans')->sole();

    expect($usage->requests)->toBe(1)->and($usage->answered)->toBe(1)->and($usage->reserved_tokens)->toBe(0);
})->with(['admin' => [Role::Admin], 'content manager' => [Role::ContentManager]]);

it('rejects a translation with injected discount wording, links, wrong locales or new amounts', function (string $output) {
    translateOn();
    FakeChatModel::bind($output);

    Livewire::actingAs(staffUser(Role::ContentManager))
        ->test(RuleFormScreen::class)
        ->set('form.answer.uz', 'Narxi 1 250 000 soʻm, Poson sozlab beradi.')
        ->call('translate', 'uz')
        ->assertSet('form.answer.ru', '')
        ->assertSet('form.answer.en', '')
        ->assertDispatched('toast', type: 'error');

    expect(ChatAiUsageDaily::query()->where('channel', 'adm_trans')->value('blocked'))->toBe(1);
})->with([
    'discount and link' => ['{"answer": {"ru": "Напишите: скидка 50% и t.me/x", "en": "Write: 50% off at t.me/x"}}'],
    'one locale only' => ['{"answer": {"ru": "Цена 1 250 000 сум."}}'],
    'source locale too' => ['{"answer": {"uz": "x", "ru": "Цена 1 250 000 сум.", "en": "Price 1 250 000 UZS."}}'],
    'other amount' => ['{"answer": {"ru": "Цена 2 500 000 сум.", "en": "Price 1 250 000 UZS."}}'],
    'not json' => ['Цена 1 250 000 сум.'],
]);

it('keeps the amounts of the source text in a translation', function () {
    translateOn();
    FakeChatModel::bind('{"answer": {"ru": "Цена 1 250 000 сум, Poson настроит.", "en": "Price 1 250 000 UZS, Poson sets it up."}}');

    Livewire::actingAs(staffUser(Role::Admin))
        ->test(RuleFormScreen::class)
        ->set('form.answer.uz', 'Narxi 1 250 000 soʻm, Poson sozlab beradi.')
        ->call('translate', 'uz')
        ->assertSet('form.answer.ru', 'Цена 1 250 000 сум, Poson настроит.');
});

it('stops an editor over the hourly limit with a fixed toast and no call', function () {
    translateOn();
    config(['chatbot.ai.translate.per_hour_user' => 1]);
    $fake = FakeChatModel::bind('{"answer": {"ru": "Да.", "en": "Yes."}}', '{"answer": {"ru": "Да.", "en": "Yes."}}');

    Livewire::actingAs(staffUser(Role::ContentManager))
        ->test(RuleFormScreen::class)
        ->set('form.answer.uz', 'Ha.')
        ->call('translate', 'uz')
        ->call('translate', 'uz', true)
        ->assertDispatched('toast', body: __('chatbot_admin.rules.translate.limited'));

    expect($fake->calls())->toBe(1);
});

it('asks before overwriting filled locales and only translates on the second click (N1)', function () {
    translateOn();
    $fake = FakeChatModel::bind('{"answer": {"ru": "Да.", "en": "Yes."}}');

    $component = Livewire::actingAs(staffUser(Role::Admin))
        ->test(RuleFormScreen::class)
        ->set('form.answer.uz', 'Ha.')
        ->set('form.answer.ru', 'Старый текст.')
        ->call('translate', 'uz')
        ->assertSet('translateConfirm', 'uz')
        ->assertSet('form.answer.ru', 'Старый текст.')
        ->assertSee(__('chatbot_admin.rules.translate.overwrite'));

    expect($fake->calls())->toBe(0);

    $component->call('translate', 'uz', true)
        ->assertSet('translateConfirm', '')
        ->assertSet('form.answer.ru', 'Да.')
        ->assertSet('form.answer.en', 'Yes.');

    expect($fake->calls())->toBe(1);
});

it('checks availability and an empty source before hitting the limiter (N1)', function () {
    translateOn();
    config(['chatbot.ai.translate.per_hour_user' => 1]);
    $fake = FakeChatModel::bind('{"answer": {"ru": "Да.", "en": "Yes."}}');
    $user = staffUser(Role::Admin);

    Livewire::actingAs($user)
        ->test(RuleFormScreen::class)
        ->call('translate', 'uz')
        ->assertDispatched('toast', body: __('chatbot_admin.rules.translate.empty'))
        ->set('form.answer.uz', 'Ha.')
        ->call('translate', 'uz')
        ->assertSet('form.answer.ru', 'Да.');

    expect($fake->calls())->toBe(1)
        ->and(RateLimiter::attempts('chat-ai:trans:user:'.$user->id))->toBe(1);
});

it('makes no call and shows no button while translation is off or the key is missing', function (bool $switch, bool $key) {
    translateOn($switch);

    if (! $key) {
        config(['services.anthropic.key' => '']);
    }

    $fake = FakeChatModel::bind('{"answer": {"ru": "Да.", "en": "Yes."}}');

    Livewire::actingAs(staffUser(Role::Admin))
        ->test(RuleFormScreen::class)
        ->assertDontSee('data-translate=', false)
        ->set('form.answer.uz', 'Ha.')
        ->call('translate', 'uz')
        ->assertDispatched('toast', body: __('chatbot_admin.rules.translate.off'))
        ->assertSet('form.answer.ru', '');

    expect($fake->calls())->toBe(0);
})->with(['switch off' => [false, true], 'key missing' => [true, false]]);

it('stops at the daily translation cap', function () {
    translateOn();
    config(['chatbot.ai.caps.adm_trans' => ['requests' => 0, 'tokens' => 0]]);
    $fake = FakeChatModel::bind('{"answer": {"ru": "Да.", "en": "Yes."}}');

    Livewire::actingAs(staffUser(Role::Admin))
        ->test(RuleFormScreen::class)
        ->set('form.answer.uz', 'Ha.')
        ->call('translate', 'uz')
        ->assertDispatched('toast', body: __('chatbot_admin.rules.translate.cap'));

    expect($fake->calls())->toBe(0);
});

it('lets only an Admin switch translation on, and a Content manager off', function () {
    config(['services.anthropic.key' => AI_TEACH_KEY]);

    expect(fn () => app(SaveChatbotSettings::class)->handle(['ai_translate_enabled' => true], staffUser(Role::ContentManager)))
        ->toThrow(AuthorizationException::class);

    app(SaveChatbotSettings::class)->handle(['ai_translate_enabled' => true], staffUser(Role::Admin));
    ChatSettings::forget();
    expect(ChatSettings::all()['ai']['translate_enabled'])->toBeTrue();

    app(SaveChatbotSettings::class)->handle(['ai_translate_enabled' => false], staffUser(Role::ContentManager));
    ChatSettings::forget();
    expect(ChatSettings::all()['ai']['translate_enabled'])->toBeFalse();

    config(['services.anthropic.key' => '']);
    expect(fn () => app(SaveChatbotSettings::class)->handle(['ai_translate_enabled' => true], staffUser(Role::Admin)))
        ->toThrow(ValidationException::class);
});

/*
| Inbox "Bot conversation" (AI-B10)
*/

it('shows the customer\'s AI turns with an AI badge only when opened, and blocked answers by status only', function () {
    $admin = staffUser(Role::Admin);
    $chat = TelegramUser::factory()->registered()->create();
    $question = ChatAiTurn::factory()->create(['telegram_user_id' => $chat->id, 'text' => 'Zorbinbox savol <b>']);
    ChatAiTurn::factory()->answering($question)->create(['text' => 'Zorbinbox javob']);
    $blocked = ChatAiTurn::factory()->create(['telegram_user_id' => $chat->id, 'text' => 'Ikkinchi savol']);
    ChatAiTurn::factory()->answering($blocked, ChatAiStatus::Blocked)->create(['text' => 'Zorbhidden blocked text']);
    ChatAiTurn::factory()->create(['text' => 'Zorbother customer']);

    Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat->id])
        ->assertSee('data-ai-toggle', false)
        ->assertDontSee('Zorbinbox javob')
        ->set('aiOpen', true)
        ->assertSee('Zorbinbox javob')
        ->assertSee('Zorbinbox savol &lt;b&gt;', false)
        ->assertSee('data-ai-badge', false)
        ->assertSee('data-ai-status="blocked"', false)
        ->assertDontSee('Zorbhidden blocked text')
        ->assertDontSee('Zorbother customer');
});

it('keeps the panel behind the conversation policy', function () {
    $owner = User::factory()->withRole(Role::SalesManager)->create();
    $other = staffUser(Role::SalesManager);
    $chat = TelegramUser::factory()->registered()->assigned($owner)->create();
    ChatAiTurn::factory()->create(['telegram_user_id' => $chat->id]);

    $this->actingAs($other)->get(route('admin.telegram.chats.show', $chat))->assertForbidden();
    $this->actingAs(staffUser(Role::ContentManager))->get(route('admin.telegram.chats.show', $chat))->assertForbidden();
});

it('lists the translation usage in Stats with its own channel label', function () {
    ChatAiUsageDaily::factory()->create(['channel' => AiUsageChannel::AdminTranslate, 'requests' => 7]);
    ChatAiUsageDaily::factory()->create();

    Livewire::actingAs(staffUser(Role::ContentManager))->test(Stats::class)
        ->assertSee(__('enums.ai_usage_channel.adm_trans'))
        ->assertSee(__('enums.ai_usage_channel.telegram'));
});
