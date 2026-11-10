<?php

use App\Actions\Chat\AddKeywordFromUnmatched;
use App\Actions\Chat\DeleteChatRule;
use App\Actions\Chat\InstallChatbotDefaults;
use App\Actions\Chat\SaveChatbotSettings;
use App\Actions\Chat\SaveChatRule;
use App\Enums\ChatAction;
use App\Enums\ChatChannel;
use App\Enums\ChatMode;
use App\Enums\KeywordMatch;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Enums\TelegramDirection;
use App\Jobs\SendBotAnswer;
use App\Livewire\Admin\Chatbot\Console;
use App\Livewire\Admin\Chatbot\Rules\Form as RuleFormComponent;
use App\Livewire\Admin\Chatbot\Rules\Index as RulesIndex;
use App\Livewire\Admin\Chatbot\Settings as ChatbotSettingsScreen;
use App\Livewire\Admin\Chatbot\Stats;
use App\Livewire\Admin\Chatbot\Unmatched\Index as UnmatchedIndex;
use App\Models\ChatRule;
use App\Models\ChatRuleKeyword;
use App\Models\ChatStatDaily;
use App\Models\ChatUnmatched;
use App\Models\Page;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Models\User;
use App\Support\Chat\ChatbotSwitch;
use App\Support\Chat\ChatSettings;
use App\Support\Chat\RuleIndex;
use App\Support\Telegram\Bot\BotSwitch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * Plan 28 C2 sign-off: abuse cases of the Chat bot admin screens (rules, teach,
 * console, stats, settings). Case 13 note: the Stats screen shows counts and
 * rule names only (no unmatched text), so its escaping is checked on the rule
 * name; Console echoes the rule name, keywords and reply.
 */
function chatAdminAbuseRule(array $keywords = [], array $attributes = []): ChatRule
{
    $rule = ChatRule::factory()->create($attributes);

    foreach ($keywords as $phrase) {
        $rule->keywords()->create(['phrase' => $phrase, 'normalized' => $phrase, 'match_type' => KeywordMatch::Word, 'weight' => 10]);
    }

    RuleIndex::bump();

    return $rule;
}

function chatAdminAbusePrivacy(bool $ready = true): Page
{
    return Page::factory()->system('privacy')->create(['is_active' => $ready, 'needs_review' => ! $ready]);
}

/**
 * Make the next request of the same Livewire component come from another user.
 */
function chatAdminAbuseSwitchTo(User $user): void
{
    test()->actingAs($user);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/**
 * Fill the create form of a plain "answer" rule.
 *
 * @param  array<string, mixed>  $fields  form property => value
 */
function chatAdminAbuseCreateForm(User $user, array $fields = []): Testable
{
    $component = Livewire::actingAs($user)->test(RuleFormComponent::class)
        ->set('form.key', 'abuse_rule')
        ->set('form.name', 'Abuse rule')
        ->set('form.answer.uz', 'Javob');

    foreach ($fields as $property => $value) {
        $component->set("form.{$property}", $value);
    }

    return $component;
}

/**
 * @return array<string, int>
 */
function chatAdminAbuseCounts(): array
{
    return [
        'rules' => ChatRule::query()->count(),
        'keywords' => ChatRuleKeyword::query()->count(),
        'unmatched' => ChatUnmatched::query()->whereNotNull('resolved_at')->count(),
        'settings' => Setting::query()->count(),
        'audits' => SettingsAudit::query()->count(),
        'version' => RuleIndex::version(),
    ];
}

// 1. Roles without the permission

dataset('chat admin abuse calls', [
    'rules toggle' => [RulesIndex::class, 'toggle', ['rule']],
    'rules delete' => [RulesIndex::class, 'delete', ['rule']],
    'form save' => [RuleFormComponent::class, 'save', []],
    'form add keyword' => [RuleFormComponent::class, 'addKeyword', []],
    'form delete' => [RuleFormComponent::class, 'delete', []],
    'teach ignore' => [UnmatchedIndex::class, 'ignore', ['row']],
    'teach open' => [UnmatchedIndex::class, 'openTeach', ['row']],
    'teach add keyword' => [UnmatchedIndex::class, 'addKeyword', []],
    'console run' => [Console::class, 'run', []],
]);

/**
 * Mount a screen as `$mounter`, let `$change` swap the actor or revoke the
 * permission, then call one method and expect 403 with nothing changed.
 *
 * @param  list<string>  $args
 */
function chatAdminAbuseForbiddenCall(User $mounter, Closure $change, string $class, string $method, array $args): void
{
    $rule = chatAdminAbuseRule(['kafolat']);
    $row = ChatUnmatched::factory()->create();
    $component = Livewire::actingAs($mounter)->test($class, $class === RuleFormComponent::class ? ['rule' => $rule] : []);

    $change();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $before = chatAdminAbuseCounts();

    $component->call($method, ...array_map(fn (string $arg): int => $arg === 'rule' ? $rule->id : $row->id, $args))->assertForbidden();

    expect(chatAdminAbuseCounts())->toBe($before)
        ->and($rule->fresh()->is_active)->toBeTrue()
        ->and($row->fresh()->resolved_at)->toBeNull()
        ->and(ChatStatDaily::query()->count())->toBe(0);
}

it('refuses every chat bot admin method to a sales manager or service engineer and changes nothing', function (string $class, string $method, array $args, Role $role) {
    $intruder = staffUser($role);

    chatAdminAbuseForbiddenCall(staffUser(Role::Admin), fn () => test()->actingAs($intruder), $class, $method, $args);
})->with('chat admin abuse calls')->with([Role::SalesManager, Role::ServiceEngineer]);

// 2. Permission lost mid-session

it('refuses each method on the next call once the content manager lost chatbot.rules', function (string $class, string $method, array $args) {
    $user = staffUser(Role::ContentManager);

    chatAdminAbuseForbiddenCall($user, fn () => $user->syncRoles([Role::SalesManager->value]), $class, $method, $args);
})->with('chat admin abuse calls');

it('refuses the settings screen save once the content manager lost chatbot.settings', function () {
    $user = staffUser(Role::ContentManager);
    $component = Livewire::actingAs($user)->test(ChatbotSettingsScreen::class);

    $user->syncRoles([Role::SalesManager->value]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $component->call('save')->assertForbidden();

    expect(Setting::query()->where('group', 'chatbot')->count())->toBe(0);
});

// 3. Channel switches are Admin only

it('keeps a content manager from switching a channel, through the screen or the action, without any audit row', function () {
    chatAdminAbusePrivacy();
    config(['chatbot.web_ready' => true]);
    $cm = staffUser(Role::ContentManager);

    Livewire::actingAs($cm)->test(ChatbotSettingsScreen::class)
        ->set('form.web_enabled', true)
        ->set('form.telegram_enabled', true)
        ->call('save');

    expect(Setting::query()->whereIn('key', [ChatbotSwitch::WEB_KEY, ChatbotSwitch::TELEGRAM_KEY])->count())->toBe(0)
        ->and(SettingsAudit::query()->whereIn('key', [ChatbotSwitch::WEB_KEY, ChatbotSwitch::TELEGRAM_KEY])->count())->toBe(0);

    foreach (['web_enabled', 'telegram_enabled'] as $field) {
        expect(fn () => app(SaveChatbotSettings::class)->handle([$field => true], $cm))->toThrow(AuthorizationException::class);
    }

    expect(Setting::query()->whereIn('key', [ChatbotSwitch::WEB_KEY, ChatbotSwitch::TELEGRAM_KEY])->count())->toBe(0)
        ->and(SettingsAudit::query()->whereIn('key', [ChatbotSwitch::WEB_KEY, ChatbotSwitch::TELEGRAM_KEY])->count())->toBe(0)
        ->and(ChatbotSwitch::isEnabled(ChatChannel::Web))->toBeFalse()
        ->and(ChatbotSwitch::isEnabled(ChatChannel::Telegram))->toBeFalse();
});

// 4. Privacy gate on enable

it('refuses an Admin enabling a channel while the privacy page is missing, inactive or in review', function (string $state, string $field) {
    config(['chatbot.web_ready' => true]);

    match ($state) {
        'inactive' => Page::factory()->system('privacy')->inactive()->create(['needs_review' => false]),
        'needs_review' => Page::factory()->system('privacy')->needsReview()->create(),
        default => null,
    };

    $admin = staffUser(Role::Admin);

    Livewire::actingAs($admin)->test(ChatbotSettingsScreen::class)
        ->set("form.{$field}", true)
        ->call('save')
        ->assertHasErrors(["form.{$field}"]);

    expect(fn () => app(SaveChatbotSettings::class)->handle([$field => true], $admin))->toThrow(ValidationException::class)
        ->and(Setting::query()->where('group', 'chatbot')->count())->toBe(0)
        ->and(SettingsAudit::query()->count())->toBe(0);
})->with(['missing', 'inactive', 'needs_review'])->with(['web_enabled', 'telegram_enabled']);

// 5. Privacy page back to review after enabling

it('switches the bot off effectively when the privacy page goes back to review, and sends the next message to the inbox', function () {
    telegramBotConfigured();
    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    Setting::query()->updateOrCreate(['key' => BotSwitch::KEY], ['group' => SettingsArea::TelegramBot->group(), 'value' => true]);
    chatAdminAbusePrivacy();
    app(InstallChatbotDefaults::class)->handle();
    app(SaveChatbotSettings::class)->handle(['telegram_enabled' => true], staffUser(Role::Admin));
    TelegramUser::factory()->registered()->create(['telegram_id' => 5570002, 'locale' => 'uz', 'chat_mode' => ChatMode::Bot]);
    expect(ChatbotSwitch::isEnabled())->toBeTrue();

    Page::query()->where('slug', 'privacy')->update(['needs_review' => true]);
    ChatbotSwitch::forget();
    Bus::fake([SendBotAnswer::class]);
    $inbound = fn (): int => TelegramMessage::query()->where('direction', TelegramDirection::In)->count();
    $before = $inbound();

    expect(ChatbotSwitch::isEnabled())->toBeFalse()
        ->and(ChatbotSwitch::isStored(ChatChannel::Telegram))->toBeTrue();

    postTelegramUpdate(telegramPrivateUpdate(7700001, ['text' => 'dostavka'], 5570002))->assertOk();

    expect($inbound())->toBe($before + 1);
    Bus::assertNotDispatched(SendBotAnswer::class);
});

// 6. Web readiness

it('refuses an Admin enabling the web channel while the widget is not ready', function () {
    chatAdminAbusePrivacy();
    config(['chatbot.web_ready' => false]);
    $admin = staffUser(Role::Admin);

    Livewire::actingAs($admin)->test(ChatbotSettingsScreen::class)
        ->set('form.web_enabled', true)
        ->call('save')
        ->assertHasErrors(['form.web_enabled']);

    expect(fn () => app(SaveChatbotSettings::class)->handle(['web_enabled' => true], $admin))->toThrow(ValidationException::class)
        ->and(ChatbotSwitch::isStored(ChatChannel::Web))->toBeFalse()
        ->and(SettingsAudit::query()->count())->toBe(0);
});

// 7. Links on a new rule

dataset('chat admin abuse bad urls', [
    'other host' => 'https://evil.example',
    'javascript' => 'javascript:alert(1)',
    'protocol relative' => '//evil.example',
    'userinfo' => 'https://user@poson.uz',
    'http' => 'http://poson.uz',
    'backslash' => 'https://poson.uz\\@evil.example',
]);

it('refuses an unsafe link in a rule param, for a content manager and an Admin', function (string $url, Role $role) {
    config(['app.url' => 'https://poson.uz']);

    chatAdminAbuseCreateForm(staffUser($role), [
        'action' => 'link',
        'params.url' => $url,
        'params.link_label.uz' => 'Ochish',
    ])->call('save')->assertHasErrors();

    expect(ChatRule::query()->count())->toBe(0);
})->with('chat admin abuse bad urls')->with([Role::ContentManager, Role::Admin]);

it('refuses an unsafe link on a rule button, for a content manager and an Admin', function (string $url, Role $role) {
    config(['app.url' => 'https://poson.uz']);

    chatAdminAbuseCreateForm(staffUser($role))
        ->call('addButton')
        ->set('form.buttons.0.label.uz', 'Sayt')
        ->set('form.buttons.0.kind', 'url')
        ->set('form.buttons.0.url', $url)
        ->call('save')
        ->assertHasErrors();

    expect(ChatRule::query()->count())->toBe(0);
})->with('chat admin abuse bad urls')->with([Role::ContentManager, Role::Admin]);

// 8. Stored Admin URL

it('lets a content manager edit the answer of a rule with an Admin URL but never change that URL', function () {
    config(['app.url' => 'https://poson.uz']);
    $url = 'https://poson.uz/catalog';
    $button = 'https://poson.uz/service';
    $rule = ChatRule::factory()->create([
        'action' => ChatAction::Link,
        'params' => ['url' => $url, 'link_label' => ['uz' => 'Ochish']],
        'buttons' => [['label' => ['uz' => 'Servis'], 'url' => $button]],
    ]);
    $cm = staffUser(Role::ContentManager);

    Livewire::actingAs($cm)->test(RuleFormComponent::class, ['rule' => $rule])
        ->set('form.answer.uz', 'Yangi matn')
        ->call('save')
        ->assertHasNoErrors();

    $rule->refresh();

    expect($rule->getTranslation('answer', 'uz'))->toBe('Yangi matn')
        ->and($rule->params['url'])->toBe($url)
        ->and($rule->buttons[0]['url'])->toBe($button);

    Livewire::actingAs($cm)->test(RuleFormComponent::class, ['rule' => $rule])
        ->set('form.params.url', 'https://poson.uz/other')
        ->call('save')
        ->assertHasErrors();

    Livewire::actingAs($cm)->test(RuleFormComponent::class, ['rule' => $rule])
        ->set('form.buttons.0.url', 'https://evil.example')
        ->call('save')
        ->assertHasErrors();

    $rule->refresh();

    expect($rule->params['url'])->toBe($url)
        ->and($rule->buttons[0]['url'])->toBe($button);
});

// 9. System rules

it('keeps key, action and system flag of a system rule whatever is forged, and refuses to delete it', function (Role $role) {
    $rule = chatAdminAbuseRule(['salom'], ['key' => 'greeting_sys', 'is_system' => true, 'action' => ChatAction::Answer]);
    $actor = staffUser($role);

    app(SaveChatRule::class)->handle([
        'key' => 'hacked', 'name' => 'Renamed', 'action' => 'handoff', 'is_system' => false,
        'answer' => ['uz' => 'Salom'], 'keywords' => [['phrase' => 'salom', 'match_type' => 'word', 'weight' => 10]],
    ], $actor, $rule);

    $rule->refresh();

    expect($rule->key)->toBe('greeting_sys')
        ->and($rule->action)->toBe(ChatAction::Answer)
        ->and($rule->is_system)->toBeTrue()
        ->and($rule->name)->toBe('Renamed');

    Livewire::actingAs($actor)->test(RulesIndex::class)->call('delete', $rule->id);
    Livewire::actingAs($actor)->test(RuleFormComponent::class, ['rule' => $rule])->call('delete');

    expect(fn () => app(DeleteChatRule::class)->handle($rule, $actor))->toThrow($role === Role::Admin ? ValidationException::class : AuthorizationException::class)
        ->and(ChatRule::query()->whereKey($rule->id)->exists())->toBeTrue();
})->with([Role::Admin, Role::ContentManager]);

// 10. Tampered teach input

it('writes nothing for a tampered teach form', function (string $ruleId, string $matchType, int $weight) {
    $rule = chatAdminAbuseRule();
    $row = ChatUnmatched::factory()->create(['normalized' => 'garantiya muddati']);

    Livewire::actingAs(staffUser(Role::ContentManager))->test(UnmatchedIndex::class)
        ->call('openTeach', $row->id)
        ->set('ruleId', $ruleId === 'real' ? (string) $rule->id : $ruleId)
        ->set('matchType', $matchType)
        ->set('weight', $weight)
        ->call('addKeyword')
        ->assertSet('teachingId', $row->id);

    expect(ChatRuleKeyword::query()->count())->toBe(0)
        ->and($row->fresh()->resolved_at)->toBeNull();
})->with([
    'non digit id' => ['1 OR 1=1', 'phrase', 10],
    'negative id' => ['-1', 'phrase', 10],
    'missing id' => ['', 'phrase', 10],
    'unknown id' => ['99999', 'phrase', 10],
    'bad match type' => ['real', 'regex', 10],
    'weight too big' => ['real', 'phrase', 10000],
    'weight zero' => ['real', 'phrase', 0],
]);

// 11. Re-teaching

it('refuses to teach a resolved row again and keeps the earlier rule', function () {
    $first = chatAdminAbuseRule();
    $second = chatAdminAbuseRule();
    $row = ChatUnmatched::factory()->create(['normalized' => 'yetkazish narxi', 'resolved_at' => now(), 'resolved_rule_id' => $first->id]);

    Livewire::actingAs(staffUser(Role::ContentManager))->test(UnmatchedIndex::class)
        ->call('openTeach', $row->id)
        ->set('ruleId', (string) $second->id)
        ->call('addKeyword');

    expect($second->keywords()->count())->toBe(0)
        ->and($row->fresh()->resolved_rule_id)->toBe($first->id);
});

it('stores the database normalized text as the keyword, never the raw sample', function () {
    $rule = chatAdminAbuseRule();
    $row = ChatUnmatched::factory()->create(['normalized' => 'yetkazish narxi', 'sample' => 'Yetkazish NARXI??? <b>x</b>']);

    Livewire::actingAs(staffUser(Role::ContentManager))->test(UnmatchedIndex::class)
        ->call('openTeach', $row->id)
        ->set('ruleId', (string) $rule->id)
        ->call('addKeyword')
        ->assertHasNoErrors();

    $keyword = $rule->keywords()->sole();

    expect($keyword->phrase)->toBe('yetkazish narxi')
        ->and($keyword->normalized)->toBe('yetkazish narxi');
});

// 12. Markup, bidi and banned wording

it('refuses markup and banned wording in a rule answer or button label at save', function (string $field, string $text) {
    $component = chatAdminAbuseCreateForm(staffUser(Role::ContentManager));

    $field === 'answer'
        ? $component->set('form.answer.uz', $text)
        : $component->call('addButton')->set('form.buttons.0.label.uz', $text)->set('form.buttons.0.kind', 'site')->set('form.buttons.0.target', 'catalog');

    $component->call('save')->assertHasErrors();

    expect(ChatRule::query()->count())->toBe(0);
})->with([
    'script in answer' => ['answer', '<script>alert(1)</script>'],
    'img in answer' => ['answer', '<img src=x onerror=alert(1)>'],
    'chegirma in answer' => ['answer', 'Bugun chegirma bor'],
    'cheaper in answer' => ['answer', 'Теперь дешевле'],
    'script in label' => ['label', '<script>x</script>'],
    'img in label' => ['label', '<img onerror=x>'],
    'chegirma in label' => ['label', 'Chegirma'],
    'cheaper in label' => ['label', 'Дешевле'],
]);

// APP BUG (fails until fixed): SaveChatRule::plain() lets Cf characters through; SaveChatbotSettings strips them (CB-19).
it('never stores a bidi override or zero-width character in a rule answer', function () {
    chatAdminAbuseCreateForm(staffUser(Role::ContentManager), ['answer.uz' => "Matn \u{202E}gnirts\u{200B}"])->call('save');

    $stored = ChatRule::query()->first()?->getTranslation('answer', 'uz', false) ?? '';

    expect(preg_match('/\p{Cf}/u', $stored))->toBe(0);
});

it('strips format characters from a setting text and refuses markup and banned wording there', function () {
    $cm = staffUser(Role::ContentManager);

    app(SaveChatbotSettings::class)->handle(['texts' => ['greeting' => ['uz' => "Salom \u{202E}dunyo\u{200B}"]]], $cm);

    expect(ChatSettings::all()['texts']['greeting']['uz'])->toBe('Salom dunyo');

    foreach (['<script>x</script>', '<img src=x onerror=1>', 'Chegirma bor', 'Самые дешевле цены'] as $bad) {
        expect(fn () => app(SaveChatbotSettings::class)->handle(['texts' => ['fallback' => ['uz' => $bad]]], $cm))->toThrow(ValidationException::class);
    }

    expect(ChatSettings::all()['texts']['fallback']['uz'])->toBe('');
});

// 13. Escaping of stored text

it('renders stored phrases and names escaped on Teach, Console and Stats', function () {
    $xss = '<img src=x onerror=alert(1)>';
    ChatUnmatched::factory()->create(['sample' => $xss, 'normalized' => $xss]);
    $rule = chatAdminAbuseRule(['kafolat'], ['name' => $xss, 'answer' => ['uz' => $xss]]);
    ChatStatDaily::factory()->create(['chat_rule_id' => $rule->id]);
    $admin = staffUser(Role::Admin);

    $teach = Livewire::actingAs($admin)->test(UnmatchedIndex::class)->html();
    $console = Livewire::actingAs($admin)->test(Console::class)->set('phrase', $xss.' kafolat')->call('run')->html();
    $stats = Livewire::actingAs($admin)->test(Stats::class)->html();

    foreach ([$teach, $console, $stats] as $html) {
        expect($html)->not->toContain('<img src=x')->toContain('&lt;img src=x onerror=alert(1)&gt;');
    }
});

// 14. Console dry run reads nothing sensitive

it('does not read orders, repairs, customers or leads and writes nothing when the console hits a lookup, hand-off or lead rule', function (ChatAction $action, array $params) {
    Queue::fake();
    $rule = chatAdminAbuseRule(['aloqa'], ['action' => $action, 'params' => $params]);
    $writes = fn (): int => ChatStatDaily::query()->count() + ChatUnmatched::query()->count() + TelegramMessage::query()->count() + DB::table('jobs')->count();
    $before = $writes();
    $sql = [];
    DB::listen(function ($query) use (&$sql): void {
        $sql[] = strtolower($query->sql);
    });

    Livewire::actingAs(staffUser(Role::ContentManager))->test(Console::class)
        ->set('phrase', 'aloqa ORD-100')
        ->call('run')
        ->assertSet('result.rule.id', $rule->id)
        ->assertSet('result.reply', null);

    $touched = collect($sql)->filter(fn (string $query): bool => (bool) preg_match('/\b(from|into|update|join)\s+[`"]?(orders|order_items|repairs|customers|leads|quotes|telegram_users|telegram_messages|chat_stats_daily|chat_unmatched|jobs)\b/', $query));

    expect($touched->all())->toBe([])
        ->and($writes())->toBe($before);
    Queue::assertNothingPushed();
})->with([
    'order status' => [ChatAction::OrderStatus, []],
    'handoff' => [ChatAction::Handoff, []],
    'lead dialog' => [ChatAction::LeadDialog, ['flow' => 'callback']],
]);

// 15. Hostile length and regex-like input

it('answers fast and cuts hostile console input to 500 characters', function (string $phrase) {
    chatAdminAbuseRule(['a']);

    $start = microtime(true);
    $component = Livewire::actingAs(staffUser(Role::Admin))->test(Console::class)->set('phrase', $phrase)->call('run');
    $elapsed = microtime(true) - $start;

    expect(mb_strlen($component->get('phrase')))->toBeLessThanOrEqual(Console::MAX_CHARS)
        ->and($elapsed)->toBeLessThan(3.0);
})->with([
    'nested quantifier' => ['(a+)+$'],
    'nested quantifier long' => [str_repeat('a', 40).'!(a+)+$'],
    '10 KB' => [str_repeat('a', 10240).'!'],
]);

it('treats a regex-looking keyword as plain text', function () {
    chatAdminAbuseCreateForm(staffUser(Role::ContentManager))
        ->call('addKeyword')
        ->set('form.keywords.0.phrase', '(a+)+$')
        ->call('save')
        ->assertHasNoErrors();

    $start = microtime(true);
    Livewire::actingAs(staffUser(Role::Admin))->test(Console::class)->set('phrase', str_repeat('a', 5000).'!')->call('run');

    expect(ChatRuleKeyword::query()->sole()->normalized)->toBe('a')
        ->and(microtime(true) - $start)->toBeLessThan(3.0);
});

// 16. Locked properties and bad query values

it('rejects a tampered console result, teaching id or unmatched id', function () {
    $admin = staffUser(Role::Admin);

    expect(fn () => Livewire::actingAs($admin)->test(Console::class)->set('result', ['matched' => true, 'reply' => ['text' => 'forged', 'buttons' => []]]))
        ->toThrow(Exception::class, 'Cannot update locked property')
        ->and(fn () => Livewire::actingAs($admin)->test(UnmatchedIndex::class)->set('teachingId', 5))
        ->toThrow(Exception::class, 'Cannot update locked property')
        ->and(fn () => Livewire::actingAs($admin)->test(RuleFormComponent::class)->set('unmatchedId', 5))
        ->toThrow(Exception::class, 'Cannot update locked property');
});

it('falls back to 30 days for a bad days value on the stats screen', function (string $days) {
    $this->actingAs(staffUser(Role::ContentManager))->get(route('admin.chatbot.stats', ['days' => $days, 'channel' => 'nope']))->assertOk();
})->with(['abc', '-5', '999999999999999999999', '1;DROP TABLE x', '']);

// 17. Audit contents

it('audits chat bot settings with key and action only, never the text or the switch value', function () {
    chatAdminAbusePrivacy();
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });
    $admin = staffUser(Role::Admin);

    Livewire::actingAs($admin)->test(ChatbotSettingsScreen::class)
        ->set('form.texts.greeting.uz', 'Maxfiy salomlashuv matni')
        ->set('form.telegram_enabled', true)
        ->call('save')
        ->assertHasNoErrors();

    $audits = SettingsAudit::query()->where('area', 'chatbot')->get();

    expect($audits->pluck('key')->all())->toContain('chatbot.text.greeting', ChatbotSwitch::TELEGRAM_KEY);

    foreach ($audits as $audit) {
        expect(array_keys($audit->getAttributes()))->toEqualCanonicalizing(['id', 'user_id', 'area', 'key', 'action', 'ip', 'created_at'])
            ->and(json_encode($audit->getAttributes()))->not->toContain('Maxfiy');
    }

    expect(implode(' ', $logged))->not->toContain('Maxfiy');
});

// 18. Rollback does not bump the version

it('does not bump the rule cache version when a rule save is rolled back, and does when it commits', function () {
    $rule = chatAdminAbuseRule();
    $admin = staffUser(Role::Admin);
    $payload = ['key' => 'rolled_back', 'name' => 'Rolled', 'action' => 'answer', 'answer' => ['uz' => 'Javob'], 'keywords' => [['phrase' => 'sinov', 'match_type' => 'word', 'weight' => 10]]];
    $version = RuleIndex::version();

    ChatRuleKeyword::created(fn () => throw new RuntimeException('boom'));

    expect(fn () => app(SaveChatRule::class)->handle($payload, $admin))->toThrow(RuntimeException::class);

    expect(RuleIndex::version())->toBe($version)
        ->and(ChatRule::query()->where('key', 'rolled_back')->exists())->toBeFalse();

    ChatRuleKeyword::flushEventListeners();
    app(SaveChatRule::class)->handle($payload, $admin);

    expect(RuleIndex::version())->toBeGreaterThan($version);
});

it('does not bump the rule cache version when add-as-keyword is rolled back', function () {
    $rule = chatAdminAbuseRule();
    $row = ChatUnmatched::factory()->create(['normalized' => 'yetkazish narxi']);
    $version = RuleIndex::version();

    ChatRuleKeyword::created(fn () => throw new RuntimeException('boom'));

    expect(fn () => app(AddKeywordFromUnmatched::class)->handle($row, $rule, staffUser(Role::Admin)))->toThrow(RuntimeException::class);

    ChatRuleKeyword::flushEventListeners();

    expect(RuleIndex::version())->toBe($version)
        ->and($row->fresh()->resolved_at)->toBeNull()
        ->and($rule->keywords()->count())->toBe(0);
});
