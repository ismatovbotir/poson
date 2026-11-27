<?php

use App\Actions\Chat\AiKnowledgeHit;
use App\Actions\Chat\FindAiKnowledge;
use App\Actions\Office\DispatchDueAnalyses;
use App\Actions\Office\InstallAgentGuidance;
use App\Actions\Office\InstallAgentProfiles;
use App\Actions\Office\PublishAgentProfileVersion;
use App\Actions\Office\RunBusinessChatAnalysis;
use App\Actions\Office\SaveAgentProfileVersion;
use App\Enums\AgentRole;
use App\Enums\AiKnowledgeSource;
use App\Enums\AnalysisStatus;
use App\Enums\BusinessAuthor;
use App\Enums\DraftHold;
use App\Livewire\Admin\Office\Chats\Show;
use App\Livewire\Admin\Office\Education;
use App\Models\AgentProfile;
use App\Models\BusinessChat;
use App\Models\BusinessChatAnalysis;
use App\Models\TelegramGroupThread;
use App\Support\Office\AgentGuidance;
use App\Support\Office\BusinessTranscript;
use App\Support\Office\CoreRules;
use App\Support\Office\OfficePrompt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\FakeChatModel;

require_once __DIR__.'/../../../Helpers/Office.php';
require_once __DIR__.'/../../../Helpers/Groups.php';
require_once __DIR__.'/../OfficeAnalysisTest.php';

/*
| Plan 46 T3 + T4: prompt order, site sources, escalation and draft holds enforced in PHP, the default guidance seed.
| The fake ChatModel only; no network call.
*/

/**
 * Publish a sales version with guidance (and optional free text) for a due chat.
 *
 * @param  array<string, mixed>  $guidance
 * @param  array<string, mixed>  $version
 */
function guidedChat(array $guidance = [], ?string $clientText = null, array $version = []): BusinessChat
{
    $chat = officeDueChat([], $clientText);
    officeAgentVersion(AgentRole::Sales, array_merge(['guidance' => AgentGuidance::parse($guidance, AgentRole::Sales)->toArray()], $version));

    return $chat;
}

/**
 * @param  array<string, mixed>  $agent
 * @param  array<string, mixed>  $router
 */
function runGuided(BusinessChat $chat, array $agent = [], array $router = []): BusinessChatAnalysis
{
    FakeChatModel::bind(officeRouterAnswer($router), officeAgentAnswer($agent));
    test()->artisan('office:analyse-due')->assertSuccessful();

    return $chat->analyses()->latest('id')->firstOrFail();
}

it('orders the system blocks: CoreRules, code contract, operator guidance (cached), site facts, then the transcript', function () {
    $chat = guidedChat(['tone' => ['preset' => 'formal'], 'confidence_min' => 40], version: ['instructions' => 'Qualify label printer requests.']);
    $this->mock(FindAiKnowledge::class)->shouldReceive('handle')->andReturn([new AiKnowledgeHit(AiKnowledgeSource::Page, 1, 'uz', 'Service', 'We repair printers.', 'https://poson.test/uz/page/service')]);

    $fake = FakeChatModel::bind(officeRouterAnswer(), officeAgentAnswer());
    $this->artisan('office:analyse-due')->assertSuccessful();

    [$router, $agent] = $fake->requests;

    expect($router->system[0]['text'])->toBe(CoreRules::text())
        ->and($router->system[1]['text'])->toBe(OfficePrompt::rolesLine())
        ->and($router->system)->toHaveCount(3)
        ->and($agent->system)->toHaveCount(4)
        ->and($agent->system[0]['text'])->toBe(CoreRules::text())
        ->and($agent->system[1]['text'])->toBe(OfficePrompt::agentContract())
        ->and($agent->system[2]['text'])->toStartWith('<operator_guidance>')
        ->and($agent->system[2]['text'])->toContain('Lower priority than the mandatory rules above', 'Qualify label printer requests.')
        ->and($agent->system[2]['cache'])->toBeTrue()
        ->and($agent->system[3]['text'])->toContain('Facts from Poson', 'We repair printers.')
        ->and($agent->system[3]['cache'])->toBeFalse()
        ->and(array_column($agent->system, 'cache'))->toBe([false, false, true, false])
        ->and($agent->messages[0]['content'])->toContain('<conversation>')
        ->and($agent->outputSchema['properties'])->toHaveKeys(['needs_human', 'escalation'])
        ->and($agent->outputSchema['properties']['escalation']['enum'])->toContain('none', 'discount_request', 'legal_contract');
});

it('passes the version sources and top-N to the site search, and nothing for a legacy version', function () {
    $chat = guidedChat(['knowledge' => ['sources' => ['faq', 'service'], 'top_n' => 3]]);
    $this->mock(FindAiKnowledge::class)->shouldReceive('handle')->once()
        ->withArgs(fn (string $text, string $locale, ?array $sources, ?int $limit) => $sources === ['service', 'faq'] && $limit === 3)->andReturn([]);

    runGuided($chat);
});

it('keeps all sources and the old limit for a legacy version', function () {
    $chat = officeDueChat();
    $this->mock(FindAiKnowledge::class)->shouldReceive('handle')->once()
        ->withArgs(fn (string $text, string $locale, ?array $sources, ?int $limit) => $sources === null && $limit === null)->andReturn([]);

    runGuided($chat);
});

it('does not search the site when the profile switch is off, whatever the sources say', function () {
    $chat = guidedChat(['knowledge' => ['sources' => ['faq'], 'top_n' => 3]]);
    AgentProfile::forRole(AgentRole::Sales)->update(['use_site_knowledge' => false]);
    $this->mock(FindAiKnowledge::class)->shouldReceive('handle')->never();

    runGuided($chat);
});

it('filters the knowledge query by the chosen source types', function () {
    DB::enableQueryLog();
    app(FindAiKnowledge::class)->handle('label printer', 'en', ['news'], 3);
    $bindings = collect(DB::getQueryLog())->pluck('bindings')->flatten()->all();

    expect($bindings)->toContain('news')->not->toContain('page');
    expect(app(FindAiKnowledge::class)->handle('label printer', 'en', []))->toBe([]);
});

it('holds a draft below the role confidence, keeps it and shows it collapsed', function () {
    $chat = guidedChat(['confidence_min' => 90]);
    $analysis = runGuided($chat, router: ['confidence' => 85]);

    expect($analysis->draft_hold)->toBe(DraftHold::LowConfidence)
        ->and($analysis->draft_reply)->not->toBeNull()
        ->and($analysis->draft_blocked)->toBeFalse();

    Livewire::actingAs(staffUser())->test(Show::class, ['businessChat' => $chat])
        ->assertSeeHtml('data-draft-held')
        ->assertSee(DraftHold::LowConfidence->label());
});

it('does not hold a draft at or above the threshold, nor a legacy version', function () {
    $analysis = runGuided(guidedChat(['confidence_min' => 85]), router: ['confidence' => 85]);

    expect($analysis->draft_hold)->toBeNull()->and($analysis->needs_human)->toBeFalse()->and($analysis->escalation)->toBeNull();
});

it('holds a draft whose script does not match the router language', function () {
    $analysis = runGuided(guidedChat(), ['draft_reply' => 'Здравствуйте! Подскажите размер этикетки.'], ['language' => 'uz']);

    expect($analysis->draft_hold)->toBe(DraftHold::Language)->and($analysis->draft_reply)->toContain('Здравствуйте');
});

it('does not store a time promise that is not in the service facts, and keeps one that is', function (array $facts, ?DraftHold $hold) {
    $analysis = runGuided(guidedChat(['service_facts' => $facts]), ['draft_reply' => 'Assalomu alaykum! Printer 2 kun ichida tayyor boʻladi.']);

    expect($analysis->draft_hold)->toBe($hold)
        ->and($analysis->draft_reply === null)->toBe($hold !== null)
        ->and($analysis->draft_blocked)->toBeFalse();
})->with([
    'not in the facts' => [['Intake is by appointment.'], DraftHold::Sla],
    'no facts' => [[], DraftHold::Sla],
    'verbatim in the facts' => [['Typical repair time is 2 kun.'], null],
]);

it('blocks a draft with a short forbidden topic as a literal phrase, and ignores long entries', function (string $topic, ?DraftHold $hold) {
    $analysis = runGuided(guidedChat(['forbidden_topics' => [$topic]]));

    expect($analysis->draft_hold)->toBe($hold)
        ->and($analysis->draft_reply === null)->toBe($hold !== null)
        ->and($analysis->draft_blocked)->toBe($hold !== null);
})->with([
    'short' => ['YORLIQ', DraftHold::Forbidden],
    'long entry is guidance only' => [str_repeat('yorliq ', 7), null],
]);

it('applies no_draft: flags a human, stores no draft and records the topic', function () {
    $analysis = runGuided(guidedChat(), ['needs_human' => false, 'escalation' => 'legal_contract']);

    expect($analysis->needs_human)->toBeTrue()
        ->and($analysis->escalation)->toBe('legal_contract')
        ->and($analysis->draft_reply)->toBeNull()
        ->and($analysis->draft_hold)->toBe(DraftHold::Escalation);

    Livewire::actingAs(staffUser())->test(Show::class, ['businessChat' => BusinessChat::query()->find($analysis->business_chat_id)])
        ->assertSeeHtml('data-needs-human')
        ->assertSeeHtml('data-draft-hold');
});

it('flags a legal keyword in the client text even when the model says none (flag only, the draft stays)', function () {
    $analysis = runGuided(guidedChat(clientText: 'Shartnoma shartlarini muhokama qilmoqchimiz, yorliq printer kerak.'), ['escalation' => 'none']);

    expect($analysis->needs_human)->toBeTrue()
        ->and($analysis->escalation)->toBe('legal_contract')
        ->and($analysis->draft_reply)->not->toBeNull()
        ->and($analysis->draft_hold)->toBeNull();
});

it('flags a payment dispute keyword in the client text (flag only)', function (string $text) {
    $analysis = runGuided(guidedChat(clientText: $text), ['escalation' => 'none']);

    expect($analysis->needs_human)->toBeTrue()->and($analysis->escalation)->toBe('payment_dispute')->and($analysis->draft_reply)->not->toBeNull();
})->with(['Оплата не прошла, а деньги списали, верните деньги', 'You charged twice, I want a refund', 'Pulni qaytaring, ikki marta yechildi']);

it('does not scan staff text for the backstops', function () {
    $chat = officeDueChat([], 'Salom, doʻkon uchun yorliq printer kerak.');
    officeAgentVersion(AgentRole::Sales, ['guidance' => AgentGuidance::parse([], AgentRole::Sales)->toArray()]);
    $chat->messages()->create(['telegram_message_id' => 3, 'author' => BusinessAuthor::Owner, 'kind' => 'text', 'text' => 'Shartnoma tayyor, chegirma yoʻq.', 'sent_at' => now()->subMinutes(10)]);

    $analysis = runGuided($chat);

    expect($analysis->needs_human)->toBeFalse()->and($analysis->escalation)->toBeNull();
});

it('keeps the discount rule for a legacy version too', function () {
    $analysis = runGuided(officeDueChat([], 'Menga chegirma qilib bera olasizmi? yorliq printer kerak'));

    expect($analysis->needs_human)->toBeTrue()->and($analysis->escalation)->toBe('discount_request')->and($analysis->draft_reply)->toBeNull();
});

it('renders good, bad and route examples with their own labels', function () {
    $good = ['kind' => 'good', 'client' => 'Need a printer', 'reply' => 'Which label size?', 'note' => 'Asks first'];
    $bad = ['kind' => 'bad', 'client' => 'Is it free?', 'reply' => 'Yes, free.', 'note' => 'Invented promise'];
    $route = ['kind' => 'route', 'client' => 'Printer is broken', 'role' => 'service', 'reply' => '', 'note' => 'A broken device'];
    $legacy = ['client' => 'Hi', 'reply' => 'Hello', 'note' => ''];

    $version = officeAgentVersion(AgentRole::Sales, ['examples' => [$good, $bad, $route, $legacy]]);
    $text = OfficePrompt::teaching($version);

    expect($text)->toContain("Good reply: Which label size?\nNote: Asks first")
        ->and($text)->toContain("Do not reply like this: Yes, free.\nWhy not: Invented promise")
        ->and($text)->toContain("Routing example 3:\nClient: Printer is broken\nCorrect role: service\nWhy: A broken device")
        ->and($text)->toContain("Example 4:\nClient: Hi\nGood reply: Hello");
});

it('stores the escalation columns of a group thread and orders the group blocks', function () {
    groupForAnalysis(clientText: 'Shartnoma kerak, yorliq printer haqida.');
    officeAgentVersion(AgentRole::Sales, ['guidance' => AgentGuidance::parse(['confidence_min' => 99], AgentRole::Sales)->toArray()]);
    $fake = groupStandardModel(agent: ['escalation' => 'none']);

    $this->artisan('office:analyse-due')->assertSuccessful();

    $thread = TelegramGroupThread::query()->sole();
    [, $agent] = $fake->requests;

    expect($thread->needs_human)->toBeTrue()
        ->and($thread->escalation)->toBe('legal_contract')
        ->and($thread->draft_hold)->toBe(DraftHold::LowConfidence)
        ->and($thread->draft)->not->toBeNull()
        ->and($thread->agent_version_id)->not->toBeNull()
        ->and($agent->system[0]['text'])->toBe(CoreRules::text())
        ->and($agent->system[1]['text'])->toContain('Telegram group', 'needs_human')
        ->and($agent->system[2]['text'])->toStartWith('<operator_guidance>');
});

it('puts FORMAT_VERSION in the input hash and never makes a chat due by itself (ED46-10)', function () {
    $chat = officeDueChat();
    $transcript = BusinessTranscript::build($chat);
    $run = app(RunBusinessChatAnalysis::class);
    $hash = (new ReflectionMethod($run, 'hash'))->invoke($run, $transcript, [1 => 5]);

    expect($hash)->not->toBe(hash('sha256', (string) json_encode([$transcript->stamps, [1 => 5], CoreRules::VERSION])))
        ->and($hash)->toBe(hash('sha256', (string) json_encode([$transcript->stamps, [1 => 5], CoreRules::VERSION, OfficePrompt::FORMAT_VERSION])));

    // A chat that was analysed and has no new client message is not due, whatever the hash would be now.
    $chat->update(['analysis_due_at' => null]);
    Queue::fake();
    officeSettings(['ai_enabled' => true]);

    expect(app(DispatchDueAnalyses::class)->handle())->toMatchArray(['queued' => 0]);
    Queue::assertNothingPushed();
});

it('seeds one unpublished guidance version per role, idempotently, and never publishes', function () {
    app(InstallAgentProfiles::class)->handle();
    $published = AgentProfile::query()->pluck('current_version_id', 'role')->all();

    expect(app(InstallAgentGuidance::class)->handle())->toBe(4)
        ->and(app(InstallAgentGuidance::class)->handle())->toBe(0);

    foreach (AgentRole::profiles() as $role) {
        $profile = AgentProfile::forRole($role);
        $seeded = $profile->versions()->whereNotNull('guidance')->sole();

        expect($seeded->version)->toBe(2)
            ->and($profile->current_version_id)->toBe($published[$role->value])
            ->and($profile->current_version_id)->not->toBe($seeded->id)
            ->and($profile->versions()->count())->toBe(2);
    }

    $sales = AgentProfile::forRole(AgentRole::Sales)->versions()->whereNotNull('guidance')->sole();

    expect($sales->guidance['confidence_min'])->toBe(60)
        ->and($sales->guidance['escalations']['triggers']['discount_request'])->toBe(['flag', 'no_draft'])
        ->and($sales->guidance['escalations']['custom'])->toHaveCount(2)
        ->and(collect($sales->examples)->countBy('kind')->all())->toBe(['good' => 6, 'bad' => 4])
        ->and(AgentProfile::forRole(AgentRole::Router)->versions()->whereNotNull('guidance')->sole()->guidance)->toHaveKeys(['forbidden_topics', 'routing_hints'])
        ->and(AgentProfile::forRole(AgentRole::Service)->versions()->whereNotNull('guidance')->sole()->guidance['confidence_min'])->toBe(70);
});

it('passes the seeded guidance and examples through the save validation (BrandSafeText and ExampleTextGuard)', function () {
    $data = require database_path('seeders/data/office_guidance.php');

    foreach ($data as $role => $seed) {
        $case = AgentRole::from($role);
        $validator = Validator::make(
            ['instructions' => 'x', 'model' => config("office.agents.{$role}.model"), 'max_tokens' => config("office.agents.{$role}.max_tokens"), 'guidance' => $seed['guidance'], 'examples' => $seed['examples']],
            SaveAgentProfileVersion::rules($case),
        );

        expect($validator->errors()->keys())->toBe([], $role.': '.implode(', ', $validator->errors()->keys()));
        expect(mb_strlen(AgentGuidance::parse($seed['guidance'], $case)->render()))->toBeLessThan(16000);
    }
});

it('refuses to publish a version with {placeholders} left, and publishes once they are replaced', function () {
    $admin = staffUser();
    app(InstallAgentProfiles::class)->handle();
    app(InstallAgentGuidance::class)->handle();
    $profile = AgentProfile::forRole(AgentRole::Service);
    $seeded = $profile->versions()->whereNotNull('guidance')->sole();

    expect(fn () => app(PublishAgentProfileVersion::class)->handle($admin, $profile, $seeded->id))->toThrow(ValidationException::class)
        ->and($profile->fresh()->current_version_id)->not->toBe($seeded->id);

    $facts = collect($seeded->guidance['service_facts'])->reject(fn (string $fact) => str_contains($fact, '{'))->values()->all();
    $clean = app(SaveAgentProfileVersion::class)->handle($admin, AgentRole::Service, [
        'instructions' => $seeded->instructions, 'model' => $seeded->model, 'max_tokens' => $seeded->max_tokens,
        'guidance' => [...$seeded->guidance, 'service_facts' => $facts],
    ], publish: true);

    expect($profile->fresh()->current_version_id)->toBe($clean->id);
});

it('warns on save and refuses save-and-publish while a placeholder remains', function () {
    app(InstallAgentProfiles::class)->handle();

    $component = Livewire::actingAs(staffUser())->test(Education::class, ['role' => 'service'])
        ->set('guidance.service_facts', 'Opening hours: {work_hours}')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', body: __('office.errors.placeholders_left'), type: 'error', title: __('admin.toast.saved'));

    $before = AgentProfile::forRole(AgentRole::Service)->versions()->count();
    $component->call('saveAndPublish');

    expect(AgentProfile::forRole(AgentRole::Service)->versions()->count())->toBe($before);
});

it('keeps the analysis status done for a held draft (a hold is not a failure)', function () {
    $analysis = runGuided(guidedChat(['confidence_min' => 95]), router: ['confidence' => 50]);

    expect($analysis->status)->toBe(AnalysisStatus::Done);
});
