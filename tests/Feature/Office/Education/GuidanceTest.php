<?php

use App\Actions\Office\CallOfficeModel;
use App\Actions\Office\PublishAgentProfileVersion;
use App\Actions\Office\SaveAgentProfileVersion;
use App\Enums\AgentRole;
use App\Enums\AiUsageChannel;
use App\Enums\AuditAction;
use App\Enums\DraftFeedbackReason;
use App\Enums\DraftHold;
use App\Enums\DraftOutcome;
use App\Enums\EscalationAction;
use App\Enums\EscalationTrigger;
use App\Enums\ToneOption;
use App\Livewire\Admin\Office\Education;
use App\Models\AgentProfile;
use App\Models\BusinessChat;
use App\Models\OfficeDraftFeedback;
use App\Models\SettingsAudit;
use App\Support\Office\AgentGuidance;
use App\Support\Office\OfficePrompt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\McpExclusionScanner;

require_once __DIR__.'/../../../Helpers/Office.php';

/*
| Plan 46 T1 + T2: the structured guidance of an agent version (ED-2).
*/

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function guidanceSave(array $overrides = []): array
{
    return array_merge(['instructions' => 'Help the sales team qualify requests.', 'model' => 'claude-sonnet-5-5', 'max_tokens' => 800], $overrides);
}

function saveGuidance(array $guidance, array $overrides = [], AgentRole $role = AgentRole::Sales)
{
    return app(SaveAgentProfileVersion::class)->handle(staffUser(), $role, guidanceSave(['guidance' => $guidance] + $overrides));
}

it('creates the new tables, columns and the encrypted feedback row', function () {
    expect(Schema::hasColumn('agent_profile_versions', 'guidance'))->toBeTrue()
        ->and(Schema::hasColumns('business_chat_analyses', ['needs_human', 'escalation', 'draft_hold']))->toBeTrue()
        ->and(Schema::hasColumns('telegram_group_threads', ['needs_human', 'escalation', 'draft_hold', 'agent_version_id']))->toBeTrue();

    $feedback = OfficeDraftFeedback::factory()->edited('Final reply text')->create(['note' => 'Too stiff']);

    expect($feedback->fresh()->final_text)->toBe('Final reply text')
        ->and($feedback->outcome)->toBe(DraftOutcome::Edited)
        ->and(DB::table('office_draft_feedback')->value('final_text'))->not->toContain('Final reply text')
        ->and(DB::table('office_draft_feedback')->value('note'))->not->toContain('Too stiff');
});

it('deletes the feedback with its chat (cascade, ED46-8)', function () {
    $feedback = OfficeDraftFeedback::factory()->create();

    BusinessChat::query()->whereKey($feedback->business_chat_id)->delete();

    expect(OfficeDraftFeedback::query()->count())->toBe(0);
});

it('has uz, ru and en labels for every new enum case', function (string $locale) {
    foreach ([DraftHold::cases(), EscalationTrigger::cases(), EscalationAction::cases(), DraftOutcome::cases(), DraftFeedbackReason::cases(), ToneOption::cases(), [AiUsageChannel::OfficeDry]] as $cases) {
        foreach ($cases as $case) {
            $key = 'enums.'.match (true) {
                $case instanceof DraftHold => 'draft_hold',
                $case instanceof EscalationTrigger => 'escalation_trigger',
                $case instanceof EscalationAction => 'escalation_action',
                $case instanceof DraftOutcome => 'draft_outcome',
                $case instanceof DraftFeedbackReason => 'draft_feedback_reason',
                $case instanceof ToneOption => 'tone_option',
                default => 'ai_usage_channel',
            }.'.'.$case->value;

            expect(trans($key, [], $locale))->not->toBe($key);
        }
    }
})->with(['uz', 'ru', 'en']);

it('has the dry channel in the office caller and caps', function () {
    expect(AiUsageChannel::OfficeDry->value)->toBe('vo_dry')
        ->and(CallOfficeModel::CHANNELS)->toContain(AiUsageChannel::OfficeDry)
        ->and(AiUsageChannel::office())->toContain(AiUsageChannel::OfficeDry)
        ->and(config('office.caps.vo_dry'))->toBe(['requests' => 30, 'tokens' => 300000]);
});

it('stores a normalised guidance with every field and the discount lock', function () {
    officeAgents();

    $version = saveGuidance([
        'tone' => ['preset' => 'formal', 'note' => 'Short and polite.'],
        'must_ask' => ['Company name', 'Product type'],
        'escalations' => ['triggers' => ['complaint' => ['flag', 'raise_urgency']], 'custom' => [['text' => 'Customs questions', 'action' => 'no_draft']]],
        'service_facts' => ['Intake is by appointment.'],
        'knowledge' => ['sources' => ['page', 'faq'], 'top_n' => 4],
        'forbidden_topics' => ['competitor names'],
        'confidence_min' => 55,
        'unknown_key' => 'dropped',
    ]);

    $stored = $version->fresh()->guidance;

    expect($stored)->not->toHaveKey('unknown_key')
        ->and($stored['tone']['preset'])->toBe('formal')
        ->and($stored['escalations']['triggers']['complaint'])->toBe(['flag', 'raise_urgency'])
        ->and($stored['escalations']['triggers']['discount_request'])->toBe(['flag', 'no_draft'])
        ->and($stored['escalations']['triggers']['legal_contract'])->toBe(['flag', 'no_draft'])
        ->and($stored['escalations']['custom'])->toBe([['text' => 'Customs questions', 'action' => 'no_draft']])
        ->and($stored['knowledge'])->toBe(['sources' => ['page', 'faq'], 'top_n' => 4])
        ->and($stored['confidence_min'])->toBe(55)
        ->and(SettingsAudit::query()->where('key', 'office.education.sales')->where('action', AuditAction::Created->value)->exists())->toBeTrue();
});

it('rejects over-cap lists, strict enum values and a bad confidence server-side', function (array $guidance) {
    officeAgents();

    expect(fn () => saveGuidance($guidance))->toThrow(ValidationException::class);
})->with([
    'must_ask over 8' => [['must_ask' => array_fill(0, 9, 'Question')]],
    'must_ask item too long' => [['must_ask' => [str_repeat('a', 151)]]],
    'service facts over 15' => [['service_facts' => array_fill(0, 16, 'Fact')]],
    'forbidden over 10' => [['forbidden_topics' => array_fill(0, 11, 'topic')]],
    'custom over 4' => [['escalations' => ['custom' => array_fill(0, 5, ['text' => 'x', 'action' => 'flag'])]]],
    'tone preset' => [['tone' => ['preset' => 'sarcastic']]],
    'tone note too long' => [['tone' => ['preset' => 'formal', 'note' => str_repeat('a', 301)]]],
    'escalation action' => [['escalations' => ['triggers' => ['complaint' => ['delete_chat']]]]],
    'knowledge source' => [['knowledge' => ['sources' => ['https://example.com']]]],
    'top_n low' => [['knowledge' => ['top_n' => 2]]],
    'top_n high' => [['knowledge' => ['top_n' => 9]]],
    'confidence under the floor' => [['confidence_min' => 29]],
    'confidence over 100' => [['confidence_min' => 101]],
    'budget question' => [['must_ask' => ['What is your budget?']]],
    'budget question ru' => [['must_ask' => ['Какой у вас бюджет?']]],
]);

it('accepts the confidence floor of 30 and the caps', function () {
    officeAgents();

    $version = saveGuidance(['confidence_min' => 30, 'must_ask' => array_fill(0, 8, 'Question'), 'knowledge' => ['sources' => [], 'top_n' => 8]]);

    expect($version->guidance['confidence_min'])->toBe(30)
        ->and($version->guidance['knowledge'])->toBe(['sources' => [], 'top_n' => 8]);
});

it('rejects a discount offer in every text field', function (string $field) {
    officeAgents();
    $guidance = match ($field) {
        'tone note' => ['tone' => ['note' => 'Offer 10% discount']],
        'must ask' => ['must_ask' => ['Offer 10% discount']],
        'service fact' => ['service_facts' => ['Offer 10% discount']],
        'custom escalation' => ['escalations' => ['custom' => [['text' => 'Offer 10% discount', 'action' => 'flag']]]],
        'forbidden' => ['forbidden_topics' => ['Offer 10% discount']],
        default => [],
    };
    $extra = match ($field) {
        'instructions' => ['instructions' => 'Offer 10% discount'],
        'rules' => ['rules' => 'Offer 10% discount'],
        'knowledge' => ['knowledge' => 'Offer 10% discount'],
        'example' => ['examples' => [['kind' => 'good', 'client' => 'Hi', 'reply' => 'Offer 10% discount']]],
        default => [],
    };

    expect(fn () => saveGuidance($guidance, $extra))->toThrow(ValidationException::class);
})->with(['tone note', 'must ask', 'service fact', 'custom escalation', 'forbidden', 'instructions', 'rules', 'knowledge', 'example']);

it('rejects contact data in guidance and in instructions, rules, knowledge and examples (ED46-2)', function (string $text) {
    officeAgents();

    foreach ([
        ['guidance' => ['service_facts' => [$text]], 'extra' => []],
        ['guidance' => [], 'extra' => ['instructions' => $text]],
        ['guidance' => [], 'extra' => ['rules' => $text]],
        ['guidance' => [], 'extra' => ['knowledge' => $text]],
        ['guidance' => [], 'extra' => ['examples' => [['client' => $text, 'reply' => 'Ok']]]],
    ] as $case) {
        expect(fn () => saveGuidance($case['guidance'], $case['extra']))->toThrow(ValidationException::class);
    }
})->with([
    'phone' => 'Call +998 90 123 45 67 for the intake.',
    'url' => 'See https://example.com/repair for details.',
    'email' => 'Write to someone@example.com.',
    'handle' => 'Ask @someone on Telegram.',
    't.me' => 'Join t.me/somechat today.',
    'card' => 'Pay to 8600 1234 5678 9012.',
    'digit run' => 'The code is 12345.',
]);

it('lets ordinary texts and short numbers through', function () {
    officeAgents();

    $version = saveGuidance(['service_facts' => ['Warranty repair takes up to 14 days.', 'Opening hours 9:00-18:00, Monday to Friday.']], ['rules' => 'Model ZD421 is a desktop label printer.']);

    expect($version->guidance['service_facts'])->toHaveCount(2);
});

it('allows the extended examples shape within the kind caps', function () {
    officeAgents();

    $examples = array_merge(
        array_fill(0, 6, ['kind' => 'good', 'language' => 'ru', 'client' => 'Нужен принтер этикеток', 'reply' => 'Подскажите размер этикетки.', 'note' => 'Asks first']),
        array_fill(0, 4, ['kind' => 'bad', 'language' => 'en', 'client' => 'Need a scanner', 'reply' => 'Whatever.', 'note' => 'Rude']),
    );

    $version = saveGuidance([], ['examples' => $examples]);

    expect($version->examples)->toHaveCount(10)
        ->and($version->examples[0])->toMatchArray(['kind' => 'good', 'language' => 'ru'])
        ->and($version->examples[9]['kind'])->toBe('bad');

    expect(fn () => saveGuidance([], ['examples' => array_fill(0, 7, ['kind' => 'good', 'client' => 'a', 'reply' => 'b'])]))->toThrow(ValidationException::class)
        ->and(fn () => saveGuidance([], ['examples' => array_fill(0, 5, ['kind' => 'bad', 'client' => 'a', 'reply' => 'b'])]))->toThrow(ValidationException::class)
        ->and(fn () => saveGuidance([], ['examples' => [['kind' => 'oops', 'client' => 'a', 'reply' => 'b']]]))->toThrow(ValidationException::class);
});

it('reads an old example without a kind as good, and keeps route examples to the router', function () {
    officeAgents();

    $legacy = saveGuidance([], ['examples' => [['client' => 'Hi', 'reply' => 'Hello', 'note' => '']]]);
    $routed = app(SaveAgentProfileVersion::class)->handle(staffUser(), AgentRole::Router, ['instructions' => 'Route chats.', 'model' => 'claude-haiku-5-5', 'max_tokens' => 300, 'examples' => [['kind' => 'route', 'role' => 'service', 'client' => 'Printer is broken', 'reply' => '']]]);
    $agentRoute = saveGuidance([], ['examples' => [['kind' => 'route', 'role' => 'service', 'client' => 'a', 'reply' => 'b']]]);

    expect($legacy->examples[0]['kind'])->toBe('good')
        ->and($routed->examples[0])->toMatchArray(['kind' => 'route', 'role' => 'service'])
        ->and($agentRoute->examples[0]['kind'])->toBe('good');
});

it('keeps the router guidance to forbidden topics and routing hints', function () {
    officeAgents();

    $version = app(SaveAgentProfileVersion::class)->handle(staffUser(), AgentRole::Router, [
        'instructions' => 'Route chats.', 'model' => 'claude-haiku-5-5', 'max_tokens' => 300,
        'guidance' => ['forbidden_topics' => ['politics'], 'routing_hints' => ['sales' => 'Buying and quotes.', 'other' => 'Greetings only.'], 'tone' => ['preset' => 'formal'], 'confidence_min' => 99],
    ]);

    expect(array_keys($version->guidance))->toBe(['forbidden_topics', 'routing_hints'])
        ->and($version->guidance['routing_hints']['sales'])->toBe('Buying and quotes.')
        ->and(fn () => app(SaveAgentProfileVersion::class)->handle(staffUser(), AgentRole::Router, ['instructions' => 'Route.', 'model' => 'claude-haiku-5-5', 'max_tokens' => 300, 'guidance' => ['routing_hints' => ['sales' => str_repeat('a', 501)]]]))->toThrow(ValidationException::class);
});

it('caps the rendered guidance at 16 000 characters', function () {
    officeAgents();
    config(['office.education.guidance.rendered_max' => 200]);

    expect(fn () => saveGuidance(['service_facts' => array_fill(0, 15, str_repeat('a', 200))]))->toThrow(ValidationException::class);
});

it('keeps a legacy version without guidance: renders, publishes and rolls back unchanged', function () {
    officeAgents();
    $profile = AgentProfile::forRole(AgentRole::Sales);
    $v1 = $profile->currentVersion;

    expect($v1->guidance)->toBeNull()->and($v1->guidanceFor(AgentRole::Sales))->toBeNull();

    $v2 = saveGuidance(['confidence_min' => 70]);
    app(PublishAgentProfileVersion::class)->handle(staffUser(), $profile->fresh(), $v2->id);
    app(PublishAgentProfileVersion::class)->handle(staffUser(), $profile->fresh(), $v1->id);

    // A caller without a guidance key keeps null (old tests, scripts).
    $v3 = app(SaveAgentProfileVersion::class)->handle(staffUser(), AgentRole::Sales, guidanceSave());

    expect($profile->fresh()->current_version_id)->toBe($v1->id)
        ->and($v3->guidance)->toBeNull()
        ->and(OfficePrompt::teaching($v1))->toBe(OfficePrompt::teaching($v1->fresh()));

    Livewire::actingAs(staffUser())->test(Education::class, ['role' => 'sales'])->assertOk();
});

it('shows the Fields and Examples tabs and saves the guidance from the screen', function () {
    officeAgents();

    $component = Livewire::actingAs(staffUser())->test(Education::class, ['role' => 'sales'])
        ->assertSee(__('office.education.tab_fields'))
        ->assertSee(__('office.education.tab_examples'))
        ->assertSet('guidance.confidence_min', 60)
        ->set('guidance.must_ask', "Company name\nQuantity")
        ->set('guidance.service_facts', 'Intake is by appointment.')
        ->set('guidance.confidence_min', 65)
        ->set('examples', [])
        ->call('addExample', 'bad')
        ->set('examples.0.client', 'Need a printer')
        ->set('examples.0.reply', 'Buy anything.')
        ->call('save');

    $component->assertHasNoErrors();
    $latest = AgentProfile::forRole(AgentRole::Sales)->versions()->orderByDesc('version')->first();

    expect($latest->guidance['must_ask'])->toBe(['Company name', 'Quantity'])
        ->and($latest->guidance['confidence_min'])->toBe(65)
        ->and($latest->examples[0]['kind'])->toBe('bad');
});

it('refuses a tampered discount lock from the screen too', function () {
    officeAgents();

    Livewire::actingAs(staffUser())->test(Education::class, ['role' => 'sales'])
        ->set('guidance.escalations.triggers.discount_request', ['flag'])
        ->call('save')
        ->assertHasErrors(['guidance.escalations.triggers.discount_request']);
});

it('puts the guidance in the version diff', function () {
    officeAgents();
    $profile = AgentProfile::forRole(AgentRole::Sales);
    $v2 = saveGuidance(['confidence_min' => 77]);

    $component = Livewire::actingAs(staffUser())->test(Education::class, ['role' => 'sales'])->call('compare', $v2->id);

    expect(collect($component->viewData('diff'))->where('type', 'other')->pluck('line')->implode("\n"))->toContain('confidence_min: 77');
    expect($v2->guidanceFor(AgentRole::Sales)->canonicalText())->toContain('escalation discount_request: flag, no_draft', 'confidence_min: 77');
});

it('has the new model and table in the MCP exclusion scanner (ED46-9)', function () {
    expect(McpExclusionScanner::CRM_MODELS)->toContain('OfficeDraftFeedback')
        ->and(McpExclusionScanner::labels('<?php return DB::table("office_draft_feedback")->count();', 'app/Mcp/Tools/Fixture.php'))->toContain('CRM table name', 'office_draft')
        ->and(McpExclusionScanner::labels('<?php return OfficeDraftFeedback::query()->first();', 'app/Mcp/Tools/Fixture.php'))->toContain('office_draft')
        ->and(McpExclusionScanner::labels('<?php return $row->final_text;', 'app/Mcp/Tools/Fixture.php'))->toContain('office_draft')
        ->and(McpExclusionScanner::labels('<?php return $row->draft_text;', 'app/Mcp/Tools/Fixture.php'))->toContain('office_draft')
        ->and(McpExclusionScanner::labels('<?php return Product::query()->count();', 'app/Mcp/Tools/Fixture.php'))->toBe([]);
});

it('builds the same defaults for every role', function () {
    expect(AgentGuidance::defaults(AgentRole::Service)->toArray()['confidence_min'])->toBe(70)
        ->and(AgentGuidance::defaults(AgentRole::Sales)->toArray()['must_ask'])->toHaveCount(6)
        ->and(AgentGuidance::defaults(AgentRole::Router)->toArray())->toHaveKeys(['forbidden_topics', 'routing_hints']);
});
