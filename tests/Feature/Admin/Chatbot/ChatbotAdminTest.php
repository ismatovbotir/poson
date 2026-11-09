<?php

use App\Actions\Chat\AddKeywordFromUnmatched;
use App\Actions\Chat\SaveChatbotSettings;
use App\Actions\Chat\TestChatPhrase;
use App\Enums\AuditAction;
use App\Enums\ChatAction;
use App\Enums\ChatChannel;
use App\Enums\KeywordMatch;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Livewire\Admin\Chatbot\Console;
use App\Livewire\Admin\Chatbot\Help;
use App\Livewire\Admin\Chatbot\Rules\Form as RuleFormComponent;
use App\Livewire\Admin\Chatbot\Rules\Index as RulesIndex;
use App\Livewire\Admin\Chatbot\Settings as ChatbotSettingsScreen;
use App\Livewire\Admin\Chatbot\Stats;
use App\Livewire\Admin\Chatbot\Unmatched\Index as UnmatchedIndex;
use App\Models\ChatRule;
use App\Models\ChatStatDaily;
use App\Models\ChatUnmatched;
use App\Models\Page;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\TelegramMessage;
use App\Support\Chat\ChatbotSwitch;
use App\Support\Chat\ChatContext;
use App\Support\Chat\ChatSettings;
use App\Support\Chat\Engine;
use App\Support\Chat\RuleIndex;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * Plan 28 C2: the Chat bot admin screens (rules, teach, console, stats,
 * settings, instructions), their permissions and the CB-10..13 conditions.
 */
function cb2Rule(array $keywords = [], array $attributes = []): ChatRule
{
    $rule = ChatRule::factory()->create($attributes);

    foreach ($keywords as $phrase) {
        $rule->keywords()->create(['phrase' => $phrase, 'normalized' => $phrase, 'match_type' => KeywordMatch::Word, 'weight' => 10]);
    }

    RuleIndex::bump();

    return $rule;
}

function cb2Privacy(bool $ready = true): Page
{
    return Page::factory()->system('privacy')->create(['is_active' => $ready, 'needs_review' => ! $ready]);
}

/**
 * @return array<string, mixed>
 */
function cb2SettingsInput(array $override = []): array
{
    return array_merge(ChatSettings::all(), $override);
}

dataset('rules screens', [
    'rules list' => ['admin.chatbot.rules.index'],
    'rule create' => ['admin.chatbot.rules.create'],
    'teach' => ['admin.chatbot.unmatched'],
    'console' => ['admin.chatbot.console'],
    'stats' => ['admin.chatbot.stats'],
    'settings' => ['admin.chatbot.settings'],
]);

it('lets Admin and Content manager open every chat bot screen', function (string $route, Role $role) {
    $this->actingAs(staffUser($role))->get(route($route))->assertOk();
})->with('rules screens')->with([Role::Admin, Role::ContentManager]);

it('keeps sales manager and service engineer out of every chat bot screen', function (string $route, Role $role) {
    $this->actingAs(staffUser($role))->get(route($route))->assertForbidden();
})->with('rules screens')->with([Role::SalesManager, Role::ServiceEngineer]);

it('opens the rule editor for a rule and refuses it to roles without chatbot.rules', function () {
    $rule = cb2Rule();

    $this->actingAs(staffUser(Role::ContentManager))->get(route('admin.chatbot.rules.edit', $rule))->assertOk();
    $this->actingAs(staffUser(Role::SalesManager))->get(route('admin.chatbot.rules.edit', $rule))->assertForbidden();
});

it('sends guests to the login', function (string $route) {
    $this->get(route($route))->assertRedirect();
})->with('rules screens');

it('lets only Admin and Content manager hold the chat bot permissions', function () {
    foreach ([Role::Admin, Role::ContentManager] as $role) {
        expect(staffUser($role)->can('chatbot.rules'))->toBeTrue()
            ->and(staffUser($role)->can('chatbot.settings'))->toBeTrue();
    }

    foreach ([Role::SalesManager, Role::ServiceEngineer] as $role) {
        expect(staffUser($role)->can('chatbot.rules'))->toBeFalse()
            ->and(staffUser($role)->can('chatbot.settings'))->toBeFalse();
    }
});

it('adds the permissions with an add-only migration that keeps manual role edits', function () {
    $migration = require database_path('migrations/2026_11_09_025102_add_chatbot_permissions.php');
    $sales = staffUser(Role::SalesManager);
    $sales->givePermissionTo('chatbot.rules');

    $migration->up();
    $migration->up();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($sales->fresh()->can('chatbot.rules'))->toBeTrue()
        ->and(staffUser(Role::ContentManager)->can('chatbot.settings'))->toBeTrue();
});

// Instructions page

it('opens the instructions page for each chat bot permission and refuses it without', function (string $permission) {
    $user = staffUser(Role::ServiceEngineer);
    $user->givePermissionTo($permission);

    $this->actingAs($user)->get(route('admin.chatbot.help'))->assertOk()->assertSee('data-chatbot-help', false);
})->with(['chatbot.rules', 'chatbot.settings', 'telegram.inbox']);

it('refuses the instructions page to a user with no chat bot permission', function () {
    $this->actingAs(staffUser(Role::ServiceEngineer))->get(route('admin.chatbot.help'))->assertForbidden();
});

it('shows only the instruction sections the user can use', function () {
    $sales = $this->actingAs(staffUser(Role::SalesManager))->get(route('admin.chatbot.help'))->assertOk()->getContent();

    expect(Help::visibleSections(staffUser(Role::SalesManager)))->toBe(['how', 'inbox'])
        ->and($sales)->toContain('data-help-section="how"')
        ->toContain('data-help-section="inbox"')
        ->not->toContain('data-help-section="teaching"')
        ->not->toContain('data-help-section="settings"');

    $content = $this->actingAs(staffUser(Role::ContentManager))->get(route('admin.chatbot.help'))->assertOk()->getContent();

    expect($content)->toContain('data-help-section="teaching"')
        ->toContain('data-help-section="settings"')
        ->toContain('data-help-section="stats"')
        ->not->toContain('data-help-section="inbox"');

    $admin = $this->actingAs(staffUser(Role::Admin))->get(route('admin.chatbot.help'))->assertOk()->getContent();

    foreach (array_keys(Help::SECTIONS) as $section) {
        expect($admin)->toContain('data-help-section="'.$section.'"');
    }
});

it('has instruction copy for every section in uz, ru and en without price wording', function (string $locale) {
    app()->setLocale($locale);

    foreach (array_keys(Help::SECTIONS) as $section) {
        $steps = __("chatbot_help.sections.{$section}.steps");

        expect($steps)->toBeArray()->not->toBeEmpty()
            ->and(__("chatbot_help.sections.{$section}.title"))->not->toBe("chatbot_help.sections.{$section}.title");
    }

    expectNoBannedWording(json_encode(__('chatbot_help'), JSON_UNESCAPED_UNICODE));
})->with(['uz', 'ru', 'en']);

// Rules list and form

it('lists, filters, switches and deletes rules', function () {
    $user = staffUser(Role::ContentManager);
    $rule = cb2Rule(['kafolat'], ['name' => 'Warranty topic', 'key' => 'warranty_topic']);
    cb2Rule([], ['name' => 'Other topic', 'key' => 'other_topic']);

    $component = Livewire::actingAs($user)->test(RulesIndex::class)
        ->assertSee('Warranty topic')
        ->set('search', 'warranty')
        ->assertSee('Warranty topic')
        ->assertDontSee('Other topic')
        ->call('toggle', $rule->id);

    expect($rule->fresh()->is_active)->toBeFalse();

    $component->call('delete', $rule->id);

    expect(ChatRule::query()->whereKey($rule->id)->exists())->toBeFalse();
});

it('does not delete a system rule from the list, for any role', function () {
    $rule = cb2Rule([], ['is_system' => true]);

    Livewire::actingAs(staffUser(Role::ContentManager))->test(RulesIndex::class)->call('delete', $rule->id)->assertForbidden();

    // Admins pass the policy through Gate::before: the action itself refuses.
    Livewire::actingAs(staffUser(Role::Admin))->test(RulesIndex::class)->call('delete', $rule->id);

    expect($rule->fresh())->not->toBeNull();
});

it('does not offer delete for a system rule', function () {
    $rule = cb2Rule([], ['is_system' => true]);

    Livewire::actingAs(staffUser(Role::Admin))->test(RulesIndex::class)->assertDontSee('data-delete-rule', false);
    Livewire::actingAs(staffUser(Role::Admin))->test(RuleFormComponent::class, ['rule' => $rule])->assertDontSee('data-delete-rule', false);
});

it('creates a rule from the form with normalized keywords and bumps the cache', function () {
    $version = RuleIndex::version();

    Livewire::actingAs(staffUser(Role::ContentManager))->test(RuleFormComponent::class)
        ->set('form.key', 'delivery_info')
        ->set('form.name', 'Delivery')
        ->set('form.answer.uz', 'Yetkazib berish bor.')
        ->call('addKeyword')
        ->set('form.keywords.0.phrase', "Yetkazib o'rnatish")
        ->set('form.keywords.0.match_type', 'phrase')
        ->call('save')
        ->assertHasNoErrors();

    $rule = ChatRule::query()->where('key', 'delivery_info')->firstOrFail();

    expect($rule->keywords->pluck('normalized')->all())->toBe(['yetkazib ornatish'])
        ->and($rule->created_by_id)->not->toBeNull()
        ->and(RuleIndex::version())->toBeGreaterThan($version);
});

it('shows save errors of the action on the form fields', function () {
    Livewire::actingAs(staffUser(Role::ContentManager))->test(RuleFormComponent::class)
        ->set('form.key', 'Bad Key')
        ->set('form.name', 'Delivery')
        ->set('form.answer.uz', 'Salom')
        ->call('save')
        ->assertHasErrors(['form.key'])
        ->set('form.key', 'good_key')
        ->set('form.answer.uz', 'Salom <script>alert(1)</script>')
        ->call('save')
        ->assertHasErrors(['form.answer.uz']);

    expect(ChatRule::query()->count())->toBe(0);
});

it('refuses a banned price wording in an answer', function () {
    Livewire::actingAs(staffUser(Role::ContentManager))->test(RuleFormComponent::class)
        ->set('form.key', 'cheap_topic')
        ->set('form.name', 'Topic')
        ->set('form.answer.uz', 'Eng arzon narxlar bizda')
        ->call('save')
        ->assertHasErrors(['form.answer.uz']);
});

it('keeps an external link Admin only', function () {
    $host = parse_url((string) config('app.url'), PHP_URL_HOST);
    $url = 'https://'.$host.'/ru/page';

    $fill = fn ($component) => $component
        ->set('form.key', 'link_topic')
        ->set('form.name', 'Link')
        ->set('form.action', 'link')
        ->set('form.params.url', $url)
        ->set('form.params.link_label.uz', 'Ochish');

    $fill(Livewire::actingAs(staffUser(Role::ContentManager))->test(RuleFormComponent::class))
        ->call('save')
        ->assertHasErrors(['form.params.url']);

    expect(ChatRule::query()->count())->toBe(0);

    $fill(Livewire::actingAs(staffUser(Role::Admin))->test(RuleFormComponent::class))
        ->call('save')
        ->assertHasNoErrors();

    expect(ChatRule::query()->where('key', 'link_topic')->firstOrFail()->params['url'])->toBe($url);
});

it('keeps key and action of a system rule whatever the form sends', function () {
    $rule = cb2Rule(['salom'], ['key' => 'greeting_sys', 'is_system' => true, 'action' => ChatAction::Answer]);

    Livewire::actingAs(staffUser(Role::Admin))->test(RuleFormComponent::class, ['rule' => $rule])
        ->set('form.key', 'hacked')
        ->set('form.action', 'handoff')
        ->set('form.name', 'Renamed')
        ->call('save')
        ->assertHasNoErrors()
        ->call('delete');

    $rule->refresh();

    expect($rule->key)->toBe('greeting_sys')
        ->and($rule->action)->toBe(ChatAction::Answer)
        ->and($rule->name)->toBe('Renamed');
});

it('blocks the form actions for a user who lost the permission after the page loaded', function () {
    $user = staffUser(Role::ContentManager);
    $rule = cb2Rule();
    $component = Livewire::actingAs($user)->test(RuleFormComponent::class, ['rule' => $rule]);

    $user->syncRoles([Role::SalesManager->value]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $component->set('form.name', 'Changed')->assertForbidden();

    expect($rule->fresh()->name)->not->toBe('Changed');
});

// Teach from unmatched

it('adds an unmatched phrase as a keyword, resolves the row and bumps the cache', function () {
    $rule = cb2Rule(['kafolat'], ['answer' => ['uz' => 'Kafolat 12 oy']]);
    $row = ChatUnmatched::factory()->create(['normalized' => 'garantiya muddati', 'sample' => 'garantiya muddati', 'locale' => 'ru', 'count' => 4]);
    $version = RuleIndex::version();

    expect(app(Engine::class)->respond(new ChatContext(ChatChannel::Telegram, 'ru', 'garantiya muddati'))->isUnmatched())->toBeTrue();

    Livewire::actingAs(staffUser(Role::ContentManager))->test(UnmatchedIndex::class)
        ->assertSee('garantiya muddati')
        ->call('openTeach', $row->id)
        ->set('ruleId', (string) $rule->id)
        ->set('matchType', 'phrase')
        ->set('weight', 12)
        ->call('addKeyword')
        ->assertHasNoErrors();

    $keyword = $rule->keywords()->where('normalized', 'garantiya muddati')->firstOrFail();

    expect($keyword->locale)->toBe('ru')
        ->and($keyword->match_type)->toBe(KeywordMatch::Phrase)
        ->and($keyword->weight)->toBe(12)
        ->and($row->fresh()->resolved_at)->not->toBeNull()
        ->and($row->fresh()->resolved_rule_id)->toBe($rule->id)
        ->and(RuleIndex::version())->toBeGreaterThan($version)
        ->and(app(Engine::class)->respond(new ChatContext(ChatChannel::Telegram, 'ru', 'garantiya muddati'))->ruleId)->toBe($rule->id);
});

it('refuses to teach a resolved, too short or unauthorized phrase and does not duplicate a keyword', function () {
    $rule = cb2Rule();
    $admin = staffUser(Role::Admin);
    $action = app(AddKeywordFromUnmatched::class);

    $resolved = ChatUnmatched::factory()->create(['resolved_at' => now()]);
    expect(fn () => $action->handle($resolved, $rule, $admin))->toThrow(ValidationException::class);

    $short = ChatUnmatched::factory()->create(['normalized' => 'a', 'sample' => '#']);
    expect(fn () => $action->handle($short, $rule, $admin))->toThrow(ValidationException::class);

    $open = ChatUnmatched::factory()->create(['normalized' => 'narxlar', 'sample' => 'narxlar']);
    expect(fn () => $action->handle($open, $rule, staffUser(Role::SalesManager)))->toThrow(AuthorizationException::class);

    $action->handle($open, $rule, $admin);
    $again = ChatUnmatched::factory()->create(['normalized' => 'narxlar', 'sample' => 'narxlar', 'channel' => ChatChannel::Web]);
    $action->handle($again, $rule, $admin);

    expect($rule->keywords()->where('normalized', 'narxlar')->count())->toBe(1)
        ->and($again->fresh()->resolved_at)->not->toBeNull();
});

it('ignores a phrase without touching the rule cache and keeps resolved rows apart', function () {
    $row = ChatUnmatched::factory()->create();
    $version = RuleIndex::version();

    Livewire::actingAs(staffUser(Role::ContentManager))->test(UnmatchedIndex::class)
        ->call('ignore', $row->id)
        ->set('show', 'resolved')
        ->assertSee($row->sample);

    expect($row->fresh()->resolved_at)->not->toBeNull()
        ->and($row->fresh()->resolved_rule_id)->toBeNull()
        ->and(RuleIndex::version())->toBe($version);
});

it('opens the rule form with the phrase and resolves the row once the rule is saved', function () {
    $row = ChatUnmatched::factory()->create(['normalized' => 'zebra zd421', 'sample' => 'zebra zd421', 'locale' => 'ru']);

    Livewire::withQueryParams(['keyword' => $row->normalized, 'locale' => 'ru', 'unmatched' => $row->id])
        ->actingAs(staffUser(Role::ContentManager))
        ->test(RuleFormComponent::class)
        ->assertSet('form.keywords.0.phrase', 'zebra zd421')
        ->assertSet('form.keywords.0.locale', 'ru')
        ->set('form.key', 'zebra_topic')
        ->set('form.name', 'Zebra')
        ->set('form.answer.uz', 'Zebra haqida')
        ->call('save')
        ->assertHasNoErrors();

    $rule = ChatRule::query()->where('key', 'zebra_topic')->firstOrFail();

    expect($row->fresh()->resolved_rule_id)->toBe($rule->id);
});

it('escapes a visitor phrase on the teach list', function () {
    ChatUnmatched::factory()->create(['sample' => '<img src=x onerror=alert(1)>', 'normalized' => 'img src x onerror alert 1']);

    $html = $this->actingAs(staffUser(Role::Admin))->get(route('admin.chatbot.unmatched'))->assertOk()->getContent();

    expect($html)->not->toContain('<img src=x')->toContain('&lt;img src=x onerror=alert(1)&gt;');
});

// Test console

it('writes nothing, sends nothing and reads no order or repair table in the console', function () {
    Queue::fake();
    Http::preventStrayRequests();

    $lookup = cb2Rule(['buyurtma'], ['key' => 'order_lookup', 'action' => ChatAction::OrderStatus]);
    $handoff = cb2Rule(['menejer'], ['key' => 'manager_topic', 'action' => ChatAction::Handoff]);
    $lead = cb2Rule(['qongiroq'], ['key' => 'callback_topic', 'action' => ChatAction::LeadDialog, 'params' => ['flow' => 'callback']]);
    cb2Rule(['kafolat'], ['answer' => ['uz' => 'Kafolat 12 oy']]);

    $counts = fn (): array => [
        ChatStatDaily::query()->count(),
        ChatUnmatched::query()->count(),
        TelegramMessage::query()->count(),
        DB::table('leads')->count(),
        DB::table('jobs')->count(),
    ];
    $before = $counts();

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $user = staffUser(Role::ContentManager);
    $console = Livewire::actingAs($user)->test(Console::class);

    foreach ([['buyurtma qani', $lookup], ['menejer kerak', $handoff], ['qongiroq qiling', $lead]] as [$phrase, $rule]) {
        $console->set('phrase', $phrase)->call('run')
            ->assertSet('result.rule.id', $rule->id)
            ->assertSet('result.reply', null)
            ->assertSee('data-console-would-run', false);
    }

    $console->set('phrase', 'kafolat')->call('run')->assertSee('Kafolat 12 oy');
    $console->set('phrase', 'qanaqadir tushunarsiz gap')->call('run')->assertSet('result.fallback', true);
    $console->set('phrase', 'ZD421')->call('run')->assertSet('result.would_run', __('chatbot_admin.console.would_search'));

    $touched = collect($queries)->filter(fn (string $sql): bool => (bool) preg_match('/\b(from|into|update)\s+[`"]?(orders|order_items|repairs|customers|leads|telegram_messages|chat_stats_daily|chat_unmatched)\b/', $sql));

    expect($counts())->toBe($before)
        ->and($touched->all())->toBe([]);

    Queue::assertNothingPushed();
});

it('shows the score breakdown of the winning rule', function () {
    $rule = ChatRule::factory()->create(['priority' => 3]);
    $rule->keywords()->create(['phrase' => 'kafolat', 'normalized' => 'kafolat', 'match_type' => KeywordMatch::Word, 'weight' => 12]);
    RuleIndex::bump();

    $result = app(TestChatPhrase::class)->handle(staffUser(Role::Admin), 'Kafolat!', 'uz', ChatChannel::Telegram);

    expect($result['matched'])->toBeTrue()
        ->and($result['score'])->toBe(15)
        ->and($result['keywords'])->toBe([['keyword' => 'kafolat', 'type' => 'word', 'weight' => 12]])
        ->and($result['normalized'])->toBe('kafolat');
});

it('refuses the dry run to a user without chatbot.rules', function () {
    expect(fn () => app(TestChatPhrase::class)->handle(staffUser(Role::SalesManager), 'salom', 'uz', ChatChannel::Telegram))
        ->toThrow(AuthorizationException::class);
});

it('cuts a very long console phrase', function () {
    Livewire::actingAs(staffUser(Role::Admin))->test(Console::class)
        ->set('phrase', str_repeat('a', 5000))
        ->call('run')
        ->assertHasNoErrors()
        ->assertSet('phrase', str_repeat('a', Console::MAX_CHARS));
});

// Stats

it('shows the counters of the chosen period', function () {
    $rule = cb2Rule([], ['name' => 'Delivery topic']);
    ChatStatDaily::query()->insert([
        ['day' => today()->toDateString(), 'channel' => 'telegram', 'metric' => 'hit', 'chat_rule_id' => $rule->id, 'count' => 7],
        ['day' => today()->toDateString(), 'channel' => 'telegram', 'metric' => 'unmatched', 'chat_rule_id' => 0, 'count' => 3],
        ['day' => today()->subDays(40)->toDateString(), 'channel' => 'telegram', 'metric' => 'hit', 'chat_rule_id' => $rule->id, 'count' => 100],
    ]);
    ChatUnmatched::factory()->count(2)->create();

    Livewire::actingAs(staffUser(Role::ContentManager))->test(Stats::class)
        ->assertSee('Delivery topic')
        ->assertSeeInOrder(['data-metric="hit"', '7'])
        ->assertDontSee('107')
        ->set('days', 90)
        ->assertSee('107');
});

// Settings

it('saves texts, hours and options for a content manager and audits the keys, never the text', function () {
    $user = staffUser(Role::ContentManager);

    Livewire::actingAs($user)->test(ChatbotSettingsScreen::class)
        ->set('form.texts.greeting.uz', 'Salom! Qanday yordam bera olaman?')
        ->set('form.texts.greeting.ru', 'Здравствуйте!')
        ->set('form.use_company_hours', false)
        ->set('form.hours.sa.open', true)
        ->set('form.handoff_target', 'any')
        ->set('form.handoff_ttl_hours', 6)
        ->set('form.products_shown', 4)
        ->call('save')
        ->assertHasNoErrors();

    $settings = ChatSettings::all();

    expect($settings['texts']['greeting']['uz'])->toBe('Salom! Qanday yordam bera olaman?')
        ->and($settings['use_company_hours'])->toBeFalse()
        ->and($settings['hours']['sa']['open'])->toBeTrue()
        ->and($settings['handoff_target'])->toBe('any')
        ->and($settings['handoff_ttl_hours'])->toBe(6)
        ->and($settings['products_shown'])->toBe(4)
        ->and(ChatSettings::text('greeting', 'en'))->toBe('Salom! Qanday yordam bera olaman?')
        ->and(ChatSettings::text('greeting', 'ru'))->toBe('Здравствуйте!');

    $audits = SettingsAudit::query()->where('area', SettingsArea::Chatbot->value)->get();

    expect($audits->pluck('key')->all())->toContain('chatbot.text.greeting', 'chatbot.handoff_target', 'chatbot.hours')
        ->and($audits->every(fn (SettingsAudit $audit): bool => $audit->user_id === $user->id))->toBeTrue()
        ->and(json_encode($audits->toArray()))->not->toContain('Salom');
});

it('refuses a second save with nothing changed as unchanged', function () {
    $component = Livewire::actingAs(staffUser(Role::Admin))->test(ChatbotSettingsScreen::class)->call('save');
    $rows = SettingsAudit::query()->count();

    $component->call('save');

    expect(SettingsAudit::query()->count())->toBe($rows);
});

it('refuses markup, banned wording and bad hours in the settings', function (array $override, string $field) {
    $actor = staffUser(Role::ContentManager);

    expect(fn () => app(SaveChatbotSettings::class)->handle(cb2SettingsInput($override), $actor))
        ->toThrow(function (ValidationException $e) use ($field) {
            expect(array_keys($e->errors()))->toContain($field);
        });

    expect(Setting::query()->where('group', 'chatbot')->count())->toBe(0);
})->with([
    'script in greeting' => [['texts' => ['greeting' => ['uz' => '<script>x</script>']]], 'texts.greeting.uz'],
    'price wording' => [['texts' => ['fallback' => ['ru' => 'Самые дешевые цены']]], 'texts.fallback.ru'],
    'long text' => [['texts' => ['consent' => ['en' => str_repeat('a', 501)]]], 'texts.consent.en'],
    'bad time' => [['hours' => ['mo' => ['open' => true, 'from' => '25:00', 'to' => '18:00']]], 'hours.mo'],
    'end before start' => [['hours' => ['mo' => ['open' => true, 'from' => '18:00', 'to' => '09:00']]], 'hours.mo'],
    'bad target' => [['handoff_target' => 'everyone'], 'handoff_target'],
    'ttl out of range' => [['handoff_ttl_hours' => 500], 'handoff_ttl_hours'],
]);

it('keeps the channel switches read-only for a content manager', function () {
    cb2Privacy();

    $screen = $this->actingAs(staffUser(Role::ContentManager))->get(route('admin.chatbot.settings'))->assertOk();
    $screen->assertSee('data-channels-readonly', false);

    // A tampered Livewire payload cannot switch anything: the form never sends the switches.
    Livewire::actingAs(staffUser(Role::ContentManager))->test(ChatbotSettingsScreen::class)
        ->set('form.web_enabled', true)
        ->set('form.telegram_enabled', true)
        ->call('save');

    expect(ChatbotSwitch::isEnabled(ChatChannel::Web))->toBeFalse()
        ->and(ChatbotSwitch::isEnabled(ChatChannel::Telegram))->toBeFalse();
});

it('forbids a content manager to enable a channel through the action', function (string $field) {
    cb2Privacy();

    expect(fn () => app(SaveChatbotSettings::class)->handle([$field => true], staffUser(Role::ContentManager)))
        ->toThrow(AuthorizationException::class);

    expect(ChatbotSwitch::isEnabled(ChatChannel::Web))->toBeFalse()
        ->and(ChatbotSwitch::isEnabled(ChatChannel::Telegram))->toBeFalse();
})->with(['web_enabled', 'telegram_enabled']);

it('forbids a content manager to disable a channel too', function () {
    cb2Privacy();
    app(SaveChatbotSettings::class)->handle(['telegram_enabled' => true], staffUser(Role::Admin));
    ChatbotSwitch::forget();

    expect(fn () => app(SaveChatbotSettings::class)->handle(['telegram_enabled' => false], staffUser(Role::ContentManager)))
        ->toThrow(AuthorizationException::class)
        ->and(ChatbotSwitch::isEnabled(ChatChannel::Telegram))->toBeTrue();
});

it('refuses to switch a channel on while the privacy page is not ready', function (bool $page, bool $ready) {
    if ($page) {
        cb2Privacy($ready);
    }

    $admin = staffUser(Role::Admin);

    expect(fn () => app(SaveChatbotSettings::class)->handle(['telegram_enabled' => true], $admin))
        ->toThrow(function (ValidationException $e) {
            expect(array_keys($e->errors()))->toBe(['telegram_enabled']);
        });

    expect(ChatbotSwitch::isEnabled(ChatChannel::Telegram))->toBeFalse()
        ->and(SettingsAudit::query()->where('key', ChatbotSwitch::TELEGRAM_KEY)->count())->toBe(0);
})->with([
    'no privacy page' => [false, false],
    'privacy page still in review' => [true, false],
]);

it('lets an Admin switch a channel on and off once the privacy page is ready, with audit rows', function () {
    cb2Privacy();
    $admin = staffUser(Role::Admin);

    Livewire::actingAs($admin)->test(ChatbotSettingsScreen::class)
        ->set('form.telegram_enabled', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(ChatbotSwitch::isEnabled(ChatChannel::Telegram))->toBeTrue()
        ->and(ChatbotSwitch::isEnabled(ChatChannel::Web))->toBeFalse();

    Livewire::actingAs($admin)->test(ChatbotSettingsScreen::class)
        ->set('form.telegram_enabled', false)
        ->call('save');

    ChatbotSwitch::forget();

    $actions = SettingsAudit::query()->where('key', ChatbotSwitch::TELEGRAM_KEY)->orderBy('id')->pluck('action')->all();

    expect(ChatbotSwitch::isEnabled(ChatChannel::Telegram))->toBeFalse()
        ->and($actions)->toBe([AuditAction::Enabled, AuditAction::Disabled]);
});

it('shows the Admin the privacy gate message when the page is not ready', function () {
    Livewire::actingAs(staffUser(Role::Admin))->test(ChatbotSettingsScreen::class)
        ->assertSee('data-privacy-gate', false)
        ->set('form.web_enabled', true)
        ->call('save')
        ->assertHasErrors(['form.web_enabled']);
});

it('refuses the settings action without chatbot.settings', function () {
    expect(fn () => app(SaveChatbotSettings::class)->handle(cb2SettingsInput(), staffUser(Role::SalesManager)))
        ->toThrow(AuthorizationException::class);
});

it('renders the rule form for every action and loads an existing rule with buttons and keywords', function () {
    $target = cb2Rule([], ['key' => 'target_rule']);
    $rule = cb2Rule(['kafolat'], [
        'key' => 'with_buttons',
        'in_menu' => true,
        'params' => ['label' => ['uz' => 'Kafolat']],
        'buttons' => [
            ['label' => ['uz' => 'Boshqa'], 'rule_id' => $target->id],
            ['label' => ['uz' => 'Katalog'], 'target' => 'catalog'],
        ],
    ]);

    $component = Livewire::actingAs(staffUser(Role::Admin))->test(RuleFormComponent::class, ['rule' => $rule])
        ->assertSet('form.buttons.0.kind', 'rule')
        ->assertSet('form.buttons.1.kind', 'site')
        ->assertSet('form.keywords.0.phrase', 'kafolat')
        ->assertSet('form.params.label.uz', 'Kafolat')
        ->call('addButton')
        ->call('removeButton', 2)
        ->call('save')
        ->assertHasNoErrors();

    expect($rule->fresh()->buttons)->toHaveCount(2);

    foreach (ChatAction::cases() as $action) {
        Livewire::actingAs(staffUser(Role::Admin))->test(RuleFormComponent::class)->set('form.action', $action->value)->assertOk();
    }
});
