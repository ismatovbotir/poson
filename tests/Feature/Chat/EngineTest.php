<?php

use App\Actions\Chat\SaveChatRule;
use App\Enums\ChatAction;
use App\Enums\ChatChannel;
use App\Enums\KeywordMatch;
use App\Models\ChatRule;
use App\Models\ChatRuleKeyword;
use App\Support\Chat\ChatContext;
use App\Support\Chat\ChatReply;
use App\Support\Chat\Engine;
use App\Support\Chat\RuleIndex;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Plan 28 C1: the rule engine (scoring, tie-break, locale keywords, min
 * score, fallback, model-like text) and the rule cache (CB-1, CB-2).
 *
 * @param  array<string, mixed>  $attributes
 * @param  list<array{0: string, 1?: KeywordMatch, 2?: int, 3?: ?string}>  $keywords  phrase, type, weight, locale
 */
function chatRule(string $key, array $keywords, array $attributes = []): ChatRule
{
    $rule = ChatRule::factory()->create(array_merge([
        'key' => $key,
        'name' => ucfirst($key),
        'answer' => ['uz' => "{$key} uz", 'ru' => "{$key} ru", 'en' => "{$key} en"],
    ], $attributes));

    foreach ($keywords as [$phrase, $type, $weight, $locale]) {
        ChatRuleKeyword::factory()->phrase($phrase, $type ?? KeywordMatch::Word, $weight ?? 10, $locale ?? null)->create(['chat_rule_id' => $rule->id]);
    }

    RuleIndex::bump();

    return $rule;
}

/**
 * @param  list<string|array{0: string, 1?: KeywordMatch, 2?: int, 3?: ?string}>  $keywords
 * @return list<array{0: string, 1: KeywordMatch, 2: int, 3: ?string}>
 */
function kw(array $keywords): array
{
    return array_map(fn ($keyword) => is_string($keyword) ? [$keyword, KeywordMatch::Word, 10, null] : array_pad($keyword, 4, null), $keywords);
}

function chatAsk(string $text, string $locale = 'uz', ChatChannel $channel = ChatChannel::Telegram): ChatReply
{
    return app(Engine::class)->respond(new ChatContext($channel, $locale, $text));
}

it('answers in the visitor language and falls back to uzbek', function () {
    chatRule('delivery', kw(['dostavka']), ['answer' => ['uz' => 'Yetkazib berish', 'ru' => 'Доставка по городу']]);

    expect(chatAsk('dostavka', 'ru')->text)->toBe('Доставка по городу')
        ->and(chatAsk('dostavka', 'uz')->text)->toBe('Yetkazib berish')
        ->and(chatAsk('dostavka', 'en')->text)->toBe('Yetkazib berish');
});

it('matches the same rule for latin, cyrillic and apostrophe variants', function () {
    $rule = chatRule('install', kw(["o'rnatish"]), ['answer' => ['uz' => 'OK install']]);

    foreach (["o'rnatish", 'oʻrnatish', 'o‘rnatish', 'ўрнатиш', 'O’RNATISH?'] as $text) {
        expect(chatAsk($text)->ruleId)->toBe($rule->id);
    }
});

it('matches word, phrase, prefix and exact keywords', function () {
    chatRule('word', kw(['kafolat']));
    chatRule('phrase', [['qayta qongiroq', KeywordMatch::Phrase, 10, null]]);
    chatRule('prefix', [['dostav', KeywordMatch::Prefix, 10, null]]);
    chatRule('exact', [['menu', KeywordMatch::Exact, 10, null]]);

    expect(chatAsk('bu kafolat haqida')->outcome)->toBe(ChatReply::HIT)
        ->and(chatAsk('kafolatlar')->outcome)->toBe(ChatReply::UNMATCHED)
        ->and(chatAsk('menga qayta qongiroq kerak')->outcome)->toBe(ChatReply::HIT)
        ->and(chatAsk('qayta salom qongiroq')->outcome)->toBe(ChatReply::UNMATCHED)
        ->and(chatAsk('dostavka narxi')->outcome)->toBe(ChatReply::HIT)
        ->and(chatAsk('yetdostavka')->outcome)->toBe(ChatReply::UNMATCHED)
        ->and(chatAsk('menu')->outcome)->toBe(ChatReply::HIT)
        ->and(chatAsk('menu qaerda')->outcome)->toBe(ChatReply::UNMATCHED);
});

it('adds up the weights of one rule and needs min_score', function () {
    $low = chatRule('low', kw([['bir', KeywordMatch::Word, 6, null]]));
    $both = chatRule('both', kw([['ikki', KeywordMatch::Word, 6, null], ['uch', KeywordMatch::Word, 6, null]]));

    expect(chatAsk('bir')->outcome)->toBe(ChatReply::UNMATCHED)
        ->and(chatAsk('ikki')->outcome)->toBe(ChatReply::UNMATCHED)
        ->and(chatAsk('ikki uch')->ruleId)->toBe($both->id)
        ->and($low->id)->not->toBe($both->id);

    config(['chatbot.min_score' => 5]);

    expect(chatAsk('bir')->ruleId)->toBe($low->id);
});

it('adds the rule priority to the score', function () {
    $rule = chatRule('boosted', kw([['salom', KeywordMatch::Word, 5, null]]), ['priority' => 5]);

    expect(chatAsk('salom')->ruleId)->toBe($rule->id);
});

it('breaks ties by priority, then by the lower id', function () {
    $first = chatRule('first', kw(['narx']));
    $second = chatRule('second', kw(['narx']));
    $third = chatRule('third', kw([['narx', KeywordMatch::Word, 5, null]]), ['priority' => 5]);

    expect(chatAsk('narx')->ruleId)->toBe($third->id)
        ->and($first->id)->toBeLessThan($second->id);

    $third->update(['priority' => 0]);
    RuleIndex::bump();

    expect(chatAsk('narx')->ruleId)->toBe($first->id);
});

it('lets a higher score win over a lower one', function () {
    chatRule('weak', kw(['printer']));
    $strong = chatRule('strong', kw([['printer', KeywordMatch::Word, 30, null]]));

    expect(chatAsk('printer')->ruleId)->toBe($strong->id);
});

it('uses a locale keyword only for that locale', function () {
    $rule = chatRule('ru_only', kw([['доставка', KeywordMatch::Word, 10, 'ru']]));

    expect(chatAsk('доставка', 'ru')->ruleId)->toBe($rule->id)
        ->and(chatAsk('доставка', 'uz')->outcome)->toBe(ChatReply::UNMATCHED)
        ->and(chatAsk('доставка', 'en')->outcome)->toBe(ChatReply::UNMATCHED);
});

it('keeps inactive rules and rules of the other channel out', function () {
    chatRule('off', kw(['yopiq']), ['is_active' => false]);
    $web = chatRule('webrule', kw(['sayt']), ['on_telegram' => false]);

    expect(chatAsk('yopiq')->outcome)->toBe(ChatReply::UNMATCHED)
        ->and(chatAsk('sayt')->outcome)->toBe(ChatReply::UNMATCHED)
        ->and(chatAsk('sayt', 'uz', ChatChannel::Web)->ruleId)->toBe($web->id);
});

it('answers unknown text with the fallback, the menu and a manager button', function () {
    chatRule('faq', kw(['faq']), ['in_menu' => true, 'menu_sort' => 1, 'params' => ['label' => ['uz' => 'Savol-javob']]]);

    $reply = chatAsk('qandaydir noaniq gap');

    expect($reply->outcome)->toBe(ChatReply::UNMATCHED)
        ->and($reply->text)->toBe(__('chatbot.fallback', [], 'uz'))
        ->and(collect($reply->buttons)->pluck('type')->all())->toBe(['rule', 'handoff'])
        ->and($reply->buttons[0]['label'])->toBe('Savol-javob');
});

it('sends model-like text to product search instead of the unmatched list', function () {
    $reply = chatAsk('Zebra ZD421');

    expect($reply->outcome)->toBe(ChatReply::SEARCH)
        ->and($reply->isUnmatched())->toBeFalse();
});

it('answers later-phase actions with an ask-a-manager reply', function (ChatAction $action) {
    chatRule('later', kw(['keyingi']), ['action' => $action, 'answer' => null]);

    $reply = chatAsk('keyingi');

    expect($reply->text)->toBe(__('chatbot.coming_soon', [], 'uz'))
        ->and(collect($reply->buttons)->pluck('type')->all())->toBe(['handoff']);
})->with([ChatAction::ProductSearch, ChatAction::OrderStatus, ChatAction::RepairStatus, ChatAction::LeadDialog]);

it('flags a hand-off rule', function () {
    chatRule('manager', kw(['menejer']), ['action' => ChatAction::Handoff]);

    expect(chatAsk('menejer')->isHandoff())->toBeTrue();
});

it('matches without a single database query on a warm cache', function () {
    chatRule('delivery', kw(['dostavka']));
    chatRule('menu', kw(['menu']), ['action' => ChatAction::Menu, 'in_menu' => true]);
    app(RuleIndex::class)->load();

    DB::flushQueryLog();
    DB::enableQueryLog();

    chatAsk('dostavka');
    chatAsk('menu');
    chatAsk('hech narsa topilmaydi');
    chatAsk('ZD421');

    expect(DB::getQueryLog())->toBe([]);
});

it('writes nothing while answering', function () {
    chatRule('delivery', kw(['dostavka']));
    app(RuleIndex::class)->load();

    DB::flushQueryLog();
    DB::enableQueryLog();

    chatAsk('dostavka');
    chatAsk('noaniq gap');

    $writes = collect(DB::getQueryLog())->filter(fn (array $query) => preg_match('/^\s*(insert|update|delete|replace)/i', $query['query']) === 1);

    expect($writes)->toBeEmpty();
});

it('rebuilds the rules when the version is bumped', function () {
    chatRule('delivery', kw(['dostavka']));
    expect(chatAsk('dostavka')->outcome)->toBe(ChatReply::HIT);

    ChatRule::query()->update(['is_active' => false]);

    expect(chatAsk('dostavka')->outcome)->toBe(ChatReply::HIT);

    RuleIndex::bump();

    expect(chatAsk('dostavka')->outcome)->toBe(ChatReply::UNMATCHED);
});

it('does not bump the cache version when a save is rolled back', function () {
    $before = RuleIndex::version();

    try {
        DB::transaction(function (): void {
            app(SaveChatRule::class)->handle(['key' => 'rolled_back', 'name' => 'Rolled back', 'action' => 'answer', 'answer' => ['uz' => 'Matn']], staffUser());

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(RuleIndex::version())->toBe($before)
        ->and(ChatRule::query()->where('key', 'rolled_back')->exists())->toBeFalse();

    app(SaveChatRule::class)->handle(['key' => 'committed', 'name' => 'Committed', 'action' => 'answer', 'answer' => ['uz' => 'Matn']], staffUser());

    expect(RuleIndex::version())->toBe($before + 1);
});

it('answers with the fallback instead of failing when the cache is down', function () {
    chatRule('delivery', kw(['dostavka']));
    Cache::shouldReceive('get')->andThrow(new RuntimeException('cache down'));

    $reply = chatAsk('dostavka');

    expect($reply->outcome)->toBe(ChatReply::FALLBACK)
        ->and($reply->text)->toBe(__('chatbot.fallback_error', [], 'uz'))
        ->and(collect($reply->buttons)->pluck('type')->all())->toBe(['handoff']);
});

it('rebuilds a missing cache entry under a lock and serves it afterwards', function () {
    chatRule('delivery', kw(['dostavka']));

    $lock = Cache::lock(RuleIndex::LOCK_KEY, 10);
    expect($lock->get())->toBeTrue();
    config(['chatbot.cache_lock_wait_seconds' => 0]);

    // The lock is held elsewhere and nothing is cached yet: a plain fallback, not a 500.
    expect(chatAsk('dostavka')->outcome)->toBe(ChatReply::FALLBACK);

    $lock->release();

    expect(chatAsk('dostavka')->outcome)->toBe(ChatReply::HIT);
});

it('caps the compiled rules at 500 and the keywords per rule at 50', function () {
    config(['chatbot.max_rules' => 3, 'chatbot.max_keywords_per_rule' => 2]);

    $rule = ChatRule::factory()->create();
    ChatRuleKeyword::factory()->count(4)->create(['chat_rule_id' => $rule->id]);
    ChatRule::factory()->count(4)->create();

    $compiled = app(RuleIndex::class)->build();

    expect($compiled['rules'])->toHaveCount(3)
        ->and(collect($compiled['keywords'])->where('r', $rule->id))->toHaveCount(2);
});

it('survives hostile input and a catastrophic-looking keyword quickly', function () {
    chatRule('evil', kw(['(a+)+$', 'b']));

    $inputs = [str_repeat('a', 10000).'!', "\xC3\x28 invalid \xFF", str_repeat('(a+)+ ', 2000)];

    $started = microtime(true);

    foreach ($inputs as $input) {
        expect(chatAsk($input))->toBeInstanceOf(ChatReply::class);
    }

    expect(microtime(true) - $started)->toBeLessThan(1.0);
});

it('keeps the compiled rules per instance while the version is unchanged', function () {
    chatRule('delivery', kw(['dostavka']));
    $index = app(RuleIndex::class);
    $index->load();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $index->load();
    $index->load();

    expect(DB::getQueryLog())->toBe([]);

    ChatRule::query()->update(['is_active' => false]);
    RuleIndex::bump();

    expect(collect($index->load()['rules']))->toBeEmpty();
});
