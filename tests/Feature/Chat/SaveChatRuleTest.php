<?php

use App\Actions\Chat\DeleteChatRule;
use App\Actions\Chat\InstallChatbotDefaults;
use App\Actions\Chat\SaveChatRule;
use App\Enums\ChatAction;
use App\Enums\ChatChannel;
use App\Enums\ChatMetric;
use App\Enums\KeywordMatch;
use App\Enums\Role;
use App\Models\ChatRule;
use App\Models\ChatStatDaily;
use App\Models\Page;
use App\Models\User;
use App\Support\Chat\ChatContext;
use App\Support\Chat\Engine;
use App\Support\Chat\LinkTargets;
use App\Support\Chat\RuleIndex;
use Database\Seeders\ChatbotDefaultsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

/**
 * Plan 28 C1: SaveChatRule / DeleteChatRule (CB-1, 5, 6, 12) and the defaults.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ruleInput(array $overrides = []): array
{
    return array_merge([
        'key' => 'delivery',
        'name' => 'Delivery',
        'action' => 'answer',
        'answer' => ['uz' => 'Yetkazib berish bor.', 'ru' => 'Доставка есть.'],
        'keywords' => [['phrase' => "Yetkazib o'rnatish", 'match_type' => 'phrase', 'weight' => 12]],
    ], $overrides);
}

function saveRule(array $input, ?ChatRule $rule = null, ?User $actor = null): ChatRule
{
    return app(SaveChatRule::class)->handle($input, $actor ?? staffUser(Role::Admin), $rule);
}

it('stores a rule with normalized keywords and bumps the cache', function () {
    $rule = saveRule(ruleInput());

    expect($rule->keywords)->toHaveCount(1)
        ->and($rule->keywords[0]->normalized)->toBe('yetkazib ornatish')
        ->and($rule->keywords[0]->match_type)->toBe(KeywordMatch::Phrase)
        ->and($rule->getTranslation('answer', 'ru'))->toBe('Доставка есть.')
        ->and($rule->is_system)->toBeFalse()
        ->and(app(Engine::class)->respond(new ChatContext(ChatChannel::Telegram, 'uz', 'yetkazib ўрнатиш'))->ruleId)->toBe($rule->id);
});

it('collapses keywords with the same normal form and replaces them on update', function () {
    $rule = saveRule(ruleInput(['keywords' => [
        ['phrase' => "O'rnatish", 'match_type' => 'word'],
        ['phrase' => 'ўрнатиш', 'match_type' => 'word'],
    ]]));

    expect($rule->keywords)->toHaveCount(1);

    $rule = saveRule(ruleInput(['keywords' => [['phrase' => 'kafolat', 'match_type' => 'word']]]), $rule);

    expect($rule->keywords->pluck('normalized')->all())->toBe(['kafolat']);
});

it('refuses markup, control characters, long texts and banned wording', function (array $override, string $field) {
    expect(fn () => saveRule(ruleInput($override)))->toThrow(function (ValidationException $e) use ($field) {
        expect(array_keys($e->errors()))->toContain($field);
    });
})->with([
    'script in answer' => [['answer' => ['uz' => 'Salom <script>alert(1)</script>']], 'answer.uz'],
    'link tag in answer' => [['answer' => ['uz' => 'Salom', 'ru' => '<a href="https://evil.example">x</a>']], 'answer.ru'],
    'control character' => [['answer' => ['uz' => "Salom\x07"]], 'answer.uz'],
    'answer too long' => [['answer' => ['uz' => str_repeat('a', 1001)]], 'answer.uz'],
    'uz answer missing' => [['answer' => ['ru' => 'Только русский']], 'answer.uz'],
    'banned wording ru' => [['answer' => ['uz' => 'Salom', 'ru' => 'Большие скидки только у нас']], 'answer.ru'],
    'banned wording uz' => [['answer' => ['uz' => 'Eng arzon narxlar']], 'answer.uz'],
    'banned wording en' => [['answer' => ['uz' => 'Salom', 'en' => 'The cheapest printers']], 'answer.en'],
    'key with capitals' => [['key' => 'Delivery'], 'key'],
    'key with space' => [['key' => 'de livery'], 'key'],
    'unknown action' => [['action' => 'run_shell'], 'action'],
    'empty keyword' => [['keywords' => [['phrase' => '?!', 'match_type' => 'word']]], 'keywords.0'],
    'keyword too long' => [['keywords' => [['phrase' => str_repeat('a', 121), 'match_type' => 'word']]], 'keywords.0'],
    'keyword bad type' => [['keywords' => [['phrase' => 'salom', 'match_type' => 'regex']]], 'keywords.0'],
    'keyword bad weight' => [['keywords' => [['phrase' => 'salom', 'match_type' => 'word', 'weight' => 101]]], 'keywords.0'],
    'keyword bad locale' => [['keywords' => [['phrase' => 'salom', 'match_type' => 'word', 'locale' => 'de']]], 'keywords.0'],
]);

it('refuses more than 50 keywords, 8 buttons and 500 rules', function () {
    $keywords = array_map(fn (int $i) => ['phrase' => "kalit{$i}", 'match_type' => 'word'], range(1, 51));

    expect(fn () => saveRule(ruleInput(['keywords' => $keywords])))->toThrow(ValidationException::class);

    $buttons = array_map(fn (int $i) => ['label' => ['uz' => "Tugma {$i}"], 'target' => 'catalog'], range(1, 9));

    expect(fn () => saveRule(ruleInput(['buttons' => $buttons])))->toThrow(ValidationException::class);

    config(['chatbot.max_rules' => 2]);
    ChatRule::factory()->count(2)->create();

    expect(fn () => saveRule(ruleInput(['key' => 'one_too_many'])))->toThrow(ValidationException::class);
});

it('requires a unique key', function () {
    saveRule(ruleInput());

    expect(fn () => saveRule(ruleInput()))->toThrow(ValidationException::class);
});

it('validates params per action', function (array $input, bool $valid) {
    $call = fn () => saveRule(ruleInput(['key' => 'param_rule', 'answer' => []] + $input));

    $valid ? expect($call()->exists)->toBeTrue() : expect($call)->toThrow(ValidationException::class);
})->with([
    'company topic ok' => [['action' => 'company_info', 'params' => ['topic' => 'address']], true],
    'company topic bad' => [['action' => 'company_info', 'params' => ['topic' => 'secrets']], false],
    'company topic missing' => [['action' => 'company_info', 'params' => []], false],
    'faq without id ok' => [['action' => 'faq', 'params' => []], true],
    'faq unknown item' => [['action' => 'faq', 'params' => ['faq_id' => 9999]], false],
    'lead flow ok' => [['action' => 'lead_dialog', 'params' => ['flow' => 'callback']], true],
    'lead flow bad' => [['action' => 'lead_dialog', 'params' => ['flow' => 'drop_tables']], false],
    'link site target ok' => [['action' => 'link', 'params' => ['target' => 'catalog', 'link_label' => ['uz' => 'Katalog']]], true],
    'link unknown target' => [['action' => 'link', 'params' => ['target' => 'admin', 'link_label' => ['uz' => 'Admin']]], false],
    'link without label' => [['action' => 'link', 'params' => ['target' => 'catalog']], false],
    'handoff ignores params' => [['action' => 'handoff', 'params' => ['anything' => 'x']], true],
    'menu rule needs a label' => [['action' => 'menu', 'in_menu' => true, 'params' => []], false],
    'menu rule with label' => [['action' => 'menu', 'in_menu' => true, 'params' => ['label' => ['uz' => 'Menyu']]], true],
]);

it('drops params the action does not know', function () {
    $rule = saveRule(ruleInput(['action' => 'handoff', 'params' => ['evil' => '<x>', 'topic' => 'address']]));

    expect($rule->params)->toBeNull();
});

it('lets an active page be a link target and refuses an unknown one', function () {
    Page::factory()->create(['slug' => 'delivery-terms', 'is_active' => true]);
    $params = ['target' => 'page', 'slug' => 'delivery-terms', 'link_label' => ['uz' => 'Shartlar']];

    expect(saveRule(ruleInput(['key' => 'page_link', 'action' => 'link', 'params' => $params]))->params['slug'])->toBe('delivery-terms')
        ->and(fn () => saveRule(ruleInput(['key' => 'page_link2', 'action' => 'link', 'params' => [...$params, 'slug' => 'no-such-page']])))->toThrow(ValidationException::class);
});

it('accepts external links only from an Admin and only on allowed https hosts', function (string $url, bool $allowed) {
    config(['app.url' => 'https://poson.example.uz', 'chatbot.link_hosts' => ['docs.partner.example']]);
    $input = ruleInput(['key' => 'ext', 'action' => 'link', 'params' => ['url' => $url, 'link_label' => ['uz' => 'Havola']]]);
    $admin = staffUser(Role::Admin);

    expect(LinkTargets::isSafeExternalUrl($url))->toBe($allowed);

    $call = fn () => saveRule($input, null, $admin);
    $allowed ? expect($call()->params['url'])->toBe($url) : expect($call)->toThrow(ValidationException::class);
})->with([
    ['https://poson.example.uz/uz/page/x', true],
    ['https://www.poson.example.uz/x', true],
    ['https://docs.partner.example/guide.pdf', true],
    ['http://poson.example.uz/x', false],
    ['https://evil.example/x', false],
    ['https://poson.example.uz.evil.example/x', false],
    ['https://poson.example.uz@evil.example/x', false],
    ['https://user:pw@poson.example.uz/x', false],
    ['https://poson.example.uz:8443/x', false],
    ['javascript:alert(1)', false],
    ['data:text/html;base64,PHNjcmlwdD4=', false],
    ['tg://resolve?domain=evil', false],
    ['//evil.example/x', false],
    ['https:\\\\poson.example.uz\\x', false],
    ["https://poson.example.uz/x\n", false],
    ["https://poson.example.uz/x\x00y", false],
    ['https://poson.example.uz/ x', false],
    ['', false],
]);

it('refuses an external link from a Content manager', function () {
    config(['app.url' => 'https://poson.example.uz']);
    $input = ruleInput(['key' => 'ext', 'action' => 'link', 'params' => ['url' => 'https://poson.example.uz/uz', 'link_label' => ['uz' => 'Havola']]]);

    $manager = staffUser(Role::ContentManager);
    $manager->givePermissionTo(Permission::findOrCreate('chatbot.rules'));

    expect(fn () => saveRule($input, null, $manager))->toThrow(ValidationException::class)
        ->and(fn () => saveRule($input, null, staffUser(Role::SalesManager)))->toThrow(AuthorizationException::class);
});

it('re-validates a link when the reply is built', function () {
    config(['app.url' => 'https://poson.example.uz', 'chatbot.link_hosts' => []]);
    $rule = ChatRule::factory()->create([
        'key' => 'stale', 'action' => ChatAction::Link,
        'params' => ['url' => 'https://evil.example/x', 'link_label' => ['uz' => 'Havola']],
        'answer' => ['uz' => 'Mana'],
    ]);
    $rule->keywords()->create(['phrase' => 'havola', 'normalized' => 'havola', 'match_type' => KeywordMatch::Word, 'weight' => 10]);

    $reply = app(Engine::class)->respond(new ChatContext(ChatChannel::Telegram, 'uz', 'havola'));

    expect(collect($reply->buttons)->pluck('type')->all())->toBe(['handoff'])
        ->and(json_encode($reply->buttons))->not->toContain('evil');
});

it('resolves a site target to an absolute localized url', function () {
    config(['app.url' => 'https://poson.example.uz']);

    expect(LinkTargets::resolve(['target' => 'catalog'], 'ru'))->toBe('https://poson.example.uz/ru/catalog')
        ->and(LinkTargets::resolve(['target' => 'catalog'], 'xx'))->toBeNull()
        ->and(LinkTargets::resolve(['target' => 'nope'], 'uz'))->toBeNull();
});

it('keeps key and action of a system rule and refuses to delete it', function () {
    $rule = app(SaveChatRule::class)->installSystem(ruleInput(['key' => 'manager', 'action' => 'handoff', 'answer' => ['uz' => 'Yozing']]));

    $updated = saveRule(ruleInput(['key' => 'renamed', 'action' => 'answer', 'name' => 'New name', 'answer' => ['uz' => 'Yangi matn']]), $rule);

    expect($updated->key)->toBe('manager')
        ->and($updated->action)->toBe(ChatAction::Handoff)
        ->and($updated->name)->toBe('New name')
        ->and($updated->is_system)->toBeTrue()
        ->and(fn () => app(DeleteChatRule::class)->handle($updated))->toThrow(ValidationException::class)
        ->and(fn () => app(DeleteChatRule::class)->handle($updated, staffUser(Role::Admin)))->toThrow(ValidationException::class)
        ->and(ChatRule::query()->whereKey($updated->id)->exists())->toBeTrue();
});

it('cannot make a rule a system rule through input', function () {
    $rule = saveRule(ruleInput() + ['is_system' => true]);

    expect($rule->is_system)->toBeFalse();
});

it('deletes a custom rule with its keywords and stats', function () {
    $rule = saveRule(ruleInput());
    ChatStatDaily::factory()->create(['chat_rule_id' => $rule->id]);
    ChatStatDaily::factory()->create(['chat_rule_id' => 0, 'metric' => ChatMetric::Unmatched]);

    app(DeleteChatRule::class)->handle($rule, staffUser(Role::Admin));

    expect(ChatRule::query()->count())->toBe(0)
        ->and($rule->keywords()->count())->toBe(0)
        ->and(ChatStatDaily::query()->count())->toBe(1);
});

it('authorizes by permission: content manager and admin yes, sales manager no', function () {
    config(['app.url' => 'https://poson.example.uz']);
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(fn () => saveRule(ruleInput(), null, staffUser(Role::SalesManager)))->toThrow(AuthorizationException::class)
        ->and(saveRule(ruleInput(['key' => 'by_admin']), null, staffUser(Role::Admin))->exists)->toBeTrue();
});

it('installs the defaults once, create-only', function () {
    $first = app(InstallChatbotDefaults::class)->handle();

    expect($first['created'])->toBeGreaterThanOrEqual(12)
        ->and(ChatRule::query()->where('is_system', false)->count())->toBe(0);

    ChatRule::query()->where('key', 'delivery')->update(['name' => 'Edited by owner']);
    $count = ChatRule::query()->count();
    $second = app(InstallChatbotDefaults::class)->handle();

    expect($second)->toBe(['created' => 0, 'skipped' => $first['created']])
        ->and(ChatRule::query()->count())->toBe($count)
        ->and(ChatRule::query()->where('key', 'delivery')->value('name'))->toBe('Edited by owner');
});

it('ships every default in uz, ru and en with brand-safe, plain, short texts', function () {
    $this->seed(ChatbotDefaultsSeeder::class);

    foreach (ChatRule::query()->get() as $rule) {
        foreach (['answer' => $rule->getTranslations('answer'), 'label' => $rule->params['label'] ?? []] as $kind => $values) {
            $builtFromData = in_array($rule->action, [ChatAction::Faq, ChatAction::CompanyInfo], true);

            if (($kind === 'answer' && $builtFromData) || ($kind === 'label' && ! $rule->in_menu)) {
                continue;
            }

            foreach (config('app.supported_locales') as $locale) {
                expect($values[$locale] ?? '')->not->toBe('', "{$rule->key} {$kind} {$locale}");
                expectNoBannedWording($values[$locale]);
                expect(mb_strlen($values[$locale]))->toBeLessThanOrEqual($kind === 'label' ? 40 : 1000)
                    ->and($values[$locale])->not->toContain('<');
            }
        }
    }
});

it('keeps the default menu within the button limit and every default answerable', function () {
    $this->seed(ChatbotDefaultsSeeder::class);

    expect(ChatRule::query()->where('in_menu', true)->count())->toBeLessThanOrEqual(12)
        ->and(app(Engine::class)->respond(new ChatContext(ChatChannel::Telegram, 'ru', 'Привет'))->outcome)->toBe('menu')
        ->and(app(Engine::class)->respond(new ChatContext(ChatChannel::Telegram, 'uz', 'dostavka qancha turadi'))->ruleId)->toBe(ChatRule::query()->where('key', 'delivery')->value('id'))
        ->and(app(Engine::class)->respond(new ChatContext(ChatChannel::Telegram, 'uz', 'buyurtma holati qanday'))->ruleId)->toBe(ChatRule::query()->where('key', 'order_status')->value('id'))
        ->and(app(Engine::class)->respond(new ChatContext(ChatChannel::Telegram, 'ru', 'нужен менеджер'))->isHandoff())->toBeTrue();
});

it('gives a validation error, not a server error, for a keyword without a match type', function () {
    expect(fn () => saveRule(ruleInput(['keywords' => [['phrase' => 'salom']]])))->toThrow(ValidationException::class);
});

it('installs a system rule only through the trusted path used by the defaults installer', function () {
    $rule = app(SaveChatRule::class)->installSystem(ruleInput(['key' => 'trusted']));

    expect($rule->is_system)->toBeTrue()
        ->and(Cache::get(RuleIndex::VERSION_KEY))->toBeNull();

    $callers = collect(array_merge(
        glob(base_path('app/*/*.php')),
        glob(base_path('app/*/*/*.php')),
        glob(base_path('app/*/*/*/*.php')),
        glob(base_path('database/seeders/*.php')),
    ))->filter(fn (string $file) => str_contains((string) file_get_contents($file), 'installSystem('))
        ->map(fn (string $file) => str_replace(base_path().'/', '', $file))
        ->values()->all();

    expect($callers)->toBe(['app/Actions/Chat/InstallChatbotDefaults.php', 'app/Actions/Chat/SaveChatRule.php']);
});

it('bumps the rule cache once for the whole defaults install', function () {
    $before = RuleIndex::version();

    app(InstallChatbotDefaults::class)->handle();

    expect(RuleIndex::version())->toBe($before + 1);

    app(InstallChatbotDefaults::class)->handle();

    expect(RuleIndex::version())->toBe($before + 1);
});

it('reads a string cache version (Redis) as a number', function () {
    Cache::forever(RuleIndex::VERSION_KEY, '2');

    expect(RuleIndex::version())->toBe(2);

    app(SaveChatRule::class)->installSystem(ruleInput(['key' => 'v2']));
    RuleIndex::bump();

    expect(RuleIndex::version())->toBe(3);
});

it('prefers a topic rule over the greeting when both match with the real defaults', function () {
    app(InstallChatbotDefaults::class)->handle();
    $delivery = ChatRule::query()->where('key', 'delivery')->value('id');
    $greeting = ChatRule::query()->where('key', 'greeting')->value('id');
    $ask = fn (string $text, string $locale = 'uz') => app(Engine::class)->respond(new ChatContext(ChatChannel::Telegram, $locale, $text))->ruleId;

    expect($ask('Salom, yetkazib berish qancha?'))->toBe($delivery)
        ->and($ask('Привет, а доставка есть?', 'ru'))->toBe($delivery)
        ->and($ask('Salom'))->toBe($greeting);
});

it('does not mention Payme or Click in the payment default', function () {
    app(InstallChatbotDefaults::class)->handle();
    $payment = ChatRule::query()->where('key', 'payment')->firstOrFail();

    foreach (config('app.supported_locales') as $locale) {
        expect(mb_strtolower($payment->getTranslation('answer', $locale)))->not->toMatch('/\bpayme\b|\bclick\b/u');
    }

    expect($payment->keywords->pluck('normalized')->all())->not->toContain('payme')->not->toContain('click');
});
