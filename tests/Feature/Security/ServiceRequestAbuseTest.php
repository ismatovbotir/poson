<?php

require_once __DIR__.'/../Chat/ChatBotHelpers.php';
require_once __DIR__.'/../Chat/Web/WebChatHelpers.php';

use App\Actions\Chat\InstallChatbotDefaults;
use App\Actions\Chat\SaveChatRule;
use App\Actions\Chat\Web\SubmitWebChatLead;
use App\Actions\Customers\ConvertLeadToCustomer;
use App\Actions\Repairs\CreateRepair;
use App\Data\Repairs\RepairData;
use App\Enums\ChatAction;
use App\Enums\ChatMode;
use App\Enums\LeadFlow;
use App\Enums\LeadStatus;
use App\Enums\RepairDeviceType;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Events\Chat\WebChatHandedOff;
use App\Livewire\Admin\Leads\Show;
use App\Livewire\Admin\Repairs\Form;
use App\Models\ChatRule;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Repair;
use App\Models\Setting;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Models\User;
use App\Models\WebChat;
use App\Support\Chat\ChatSettings;
use App\Support\Chat\LeadDialog;
use App\Support\Chat\RuleIndex;
use App\Support\Chat\WebChatDialog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * Plan 36 I3 abuse cases P36-10 ... P36-15: a lead may become a repair only
 * for who may take it in, re-checked on every request and inside the action.
 */
function abuseFormFilled(User $user): Testable
{
    return Livewire::actingAs($user)->test(Form::class)
        ->set('form.contact_name', 'Tamper')->set('form.contact_phone', '90 111 22 33')
        ->set('form.device_type', 'pos')->set('form.problem', 'x')->set('form.intake_condition', 'y');
}

it('refuses a tampered form.lead_id for an engineer', function (string $case) {
    $engineer = staffUser(Role::ServiceEngineer);
    $colleague = User::factory()->withRole(Role::ServiceEngineer)->create();

    $leadId = match ($case) {
        'sales' => Lead::factory()->quote()->create()->id,
        'lost' => Lead::factory()->serviceRequest()->assignedTo($engineer)->lost()->create()->id,
        'colleague' => Lead::factory()->serviceRequest()->assignedTo($colleague)->create()->id,
        default => 987654,
    };

    abuseFormFilled($engineer)->set('form.lead_id', (string) $leadId)->call('save')->assertForbidden();

    expect(Repair::query()->count())->toBe(0);
})->with(['sales', 'lost', 'colleague', 'missing']);

it('rejects the same tampering in the action with a lead_id error', function (string $case) {
    $engineer = staffUser(Role::ServiceEngineer);
    $colleague = User::factory()->withRole(Role::ServiceEngineer)->create();

    $leadId = match ($case) {
        'sales' => Lead::factory()->quote()->create()->id,
        'lost' => Lead::factory()->serviceRequest()->assignedTo($engineer)->lost()->create()->id,
        'colleague' => Lead::factory()->serviceRequest()->assignedTo($colleague)->create()->id,
        default => 987654,
    };

    $data = new RepairData(contactName: 'A', contactPhone: '+998901112233', deviceType: RepairDeviceType::Pos, intakeCondition: 'y', problem: 'x', leadId: $leadId);

    expect(fn () => app(CreateRepair::class)->handle($data, $engineer))->toThrow(ValidationException::class);
    expect(Repair::query()->count())->toBe(0);
})->with(['sales', 'lost', 'colleague', 'missing']);

it('gives a sales manager no repair form at all with ?lead=, and no button on Show', function () {
    $sales = staffUser(Role::SalesManager);
    $lead = Lead::factory()->serviceRequest()->create(['name' => 'Private Name']);

    Livewire::withQueryParams(['lead' => $lead->id])->actingAs($sales)->test(Form::class)->assertForbidden();
    expect(Repair::query()->count())->toBe(0);

    Livewire::actingAs($sales)->test(Show::class, ['lead' => $lead])->assertDontSeeHtml('data-action="create-repair"');
});

it('re-checks the lead under the lock when it changes right after resolveLead reads it', function (string $change) {
    $engineer = staffUser(Role::ServiceEngineer);
    $colleague = User::factory()->withRole(Role::ServiceEngineer)->create();
    $lead = Lead::factory()->serviceRequest()->assignedTo($engineer)->create();
    $data = new RepairData(contactName: 'A', contactPhone: '+998901112233', deviceType: RepairDeviceType::Pos, intakeCondition: 'y', problem: 'x', leadId: $lead->id);

    $fired = false;
    Lead::retrieved(function (Lead $retrieved) use (&$fired, $change, $colleague): void {
        if ($fired) {
            return;
        }

        $fired = true;
        Lead::query()->whereKey($retrieved->id)->update($change === 'lost'
            ? ['status' => LeadStatus::Lost]
            : ['manager_id' => $colleague->id]);
    });

    try {
        app(CreateRepair::class)->handle($data, $engineer);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('lead_id');
    }

    expect(Repair::query()->count())->toBe(0)
        ->and($lead->fresh()->status)->toBe($change === 'lost' ? LeadStatus::Lost : LeadStatus::New);
})->with(['lost', 'reassigned']);

it('rejects a malformed form.lead_id', function () {
    abuseFormFilled(staffUser())->set('form.lead_id', '+5')->call('save')->assertHasErrors(['form.lead_id']);
    expect(Repair::query()->count())->toBe(0);
});

it('no longer lets a customer be linked to a lead converted by a repair (MED-8)', function () {
    $admin = staffUser();
    $engineer = staffUser(Role::ServiceEngineer);
    $lead = Lead::factory()->serviceRequest()->assignedTo($engineer)->create();
    $data = new RepairData(contactName: 'A', contactPhone: '+998901112233', deviceType: RepairDeviceType::Pos, intakeCondition: 'y', problem: 'x', leadId: $lead->id);

    app(CreateRepair::class)->handle($data, $engineer);
    $lead->refresh();

    expect($lead->status)->toBe(LeadStatus::Converted)
        ->and(fn () => app(ConvertLeadToCustomer::class)->handle($lead, Customer::factory()->create(), $admin))->toThrow(ValidationException::class)
        ->and($engineer->can('convert', $lead))->toBeFalse();
});

it('never converts a lead twice and keeps the repair when the lead is already converted', function () {
    $admin = staffUser();
    $lead = Lead::factory()->serviceRequest()->converted()->create();
    $data = new RepairData(contactName: 'A', contactPhone: '+998901112233', deviceType: RepairDeviceType::Pos, intakeCondition: 'y', problem: 'x', leadId: $lead->id);

    $repair = app(CreateRepair::class)->handle($data, $admin);

    expect($repair->lead_id)->toBe($lead->id)->and($lead->fresh()->status)->toBe(LeadStatus::Converted);
});

it('refuses a customer the user may not view even when the form is tampered', function () {
    $engineer = staffUser(Role::ServiceEngineer);
    Spatie\Permission\Models\Role::findByName(Role::ServiceEngineer->value)->revokePermissionTo('customers.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $customer = Customer::factory()->create();

    abuseFormFilled($engineer->fresh())->set('form.customer_id', (string) $customer->id)->call('save')->assertHasErrors(['form.customer_id']);
    expect(Repair::query()->count())->toBe(0);
});

it('escapes linked repair numbers and device text on the lead page', function () {
    $engineer = staffUser(Role::ServiceEngineer);
    $lead = Lead::factory()->serviceRequest()->assignedTo($engineer)->create(['device_brand' => '<script>alert(1)</script>']);

    Livewire::actingAs($engineer)->test(Show::class, ['lead' => $lead])
        ->assertDontSeeHtml('<script>alert(1)</script>')->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;');
});

/*
|--------------------------------------------------------------------------
| Plan 36 I4, cases 16 ... 21: chat bot hand-off target (P36-16 ... P36-18)
|--------------------------------------------------------------------------
*/

/**
 * The production state before plan 36, from FROZEN literals (the keyword sets shipped in chatbot_defaults.php at
 * the end of plan 35, as stored: normalized and de-duplicated), never from the live defaults file.
 */
function abuseLegacyChatRules(): void
{
    $rules = [
        'repair_intake' => ['Repair request', null, 5, ['word|buzildi', 'word|ishlamayapti', 'phrase|ne rabotaet', 'prefix|slomal', 'phrase|not working']],
        'availability' => ['Availability', null, 5, ['prefix|omborda bor', 'word|mavjudmi', 'phrase|v nalichii', 'word|nalichie', 'phrase|in stock', 'word|availability']],
        'dealer' => ['Dealers and partners', null, 5, [
            'prefix|diler', 'prefix|dealer', 'word|reseller', 'phrase|qayta sotuvchi', 'prefix|pereprodaj',
            'prefix|integrator', 'word|ulgurji', 'prefix|optov', 'word|optom', 'word|wholesale',
        ]],
        'complaint' => ['Complaint or return', null, 10, [
            'prefix|shikoyat', 'prefix|pretenziya', 'prefix|qaytarish', 'prefix|jalob', 'prefix|pretenzi',
            'prefix|vozvrat', 'prefix|complaint', 'prefix|refund', 'prefix|return',
        ]],
        'warranty_service' => ['Warranty and service', ['label' => ['uz' => 'Kafolat va servis', 'ru' => 'Гарантия и сервис', 'en' => 'Warranty and service']], 0, [
            'prefix|kafolat', 'prefix|garantiy', 'prefix|garanti', 'word|warranty', 'word|guarantee', 'prefix|servis', 'word|service',
            'prefix|remont', 'prefix|tamir', 'word|repair', 'prefix|sozlash', 'prefix|sozlab', 'prefix|nastroyk', 'word|nastroit',
            'prefix|ornatish', 'prefix|ustanovk', 'word|setup', 'prefix|integratsiya', 'prefix|integrats',
        ]],
    ];

    foreach ($rules as $key => [$name, $params, $priority, $keywords]) {
        $id = DB::table('chat_rules')->insertGetId([
            'key' => $key, 'name' => $name, 'action' => $key === 'warranty_service' ? 'answer' : 'handoff',
            'params' => $params === null ? null : json_encode($params, JSON_UNESCAPED_UNICODE),
            'answer' => json_encode(['uz' => 'x']), 'in_menu' => $key === 'warranty_service', 'menu_sort' => $key === 'warranty_service' ? 4 : 0,
            'priority' => $priority, 'is_active' => true, 'is_system' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($keywords as $keyword) {
            [$type, $normalized] = explode('|', $keyword);
            DB::table('chat_rule_keywords')->insert(['chat_rule_id' => $id, 'locale' => null, 'phrase' => $normalized, 'normalized' => $normalized, 'match_type' => $type, 'weight' => 10, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}

function abuseRunChatMigration(): void
{
    (require database_path('migrations/2026_11_18_140000_update_chatbot_rules_for_service_flow.php'))->up();
}

/**
 * @return array<string, mixed>
 */
function abuseChatSnapshot(): array
{
    return [
        DB::table('chat_rules')->orderBy('id')->get(['key', 'params', 'priority', 'is_system'])->toArray(),
        DB::table('chat_rule_keywords')->orderBy('chat_rule_id')->orderBy('match_type')->orderBy('normalized')->get(['chat_rule_id', 'match_type', 'normalized'])->toArray(),
    ];
}

it('16 rejects or drops a tampered handoff_target', function (mixed $target) {
    $admin = staffUser(Role::Admin);
    $handoff = ['key' => 'abuse_handoff', 'name' => 'H', 'action' => 'handoff', 'params' => ['handoff_target' => $target], 'keywords' => [['phrase' => 'zzz', 'match_type' => 'word']]];

    expect(fn () => app(SaveChatRule::class)->handle($handoff, $admin))->toThrow(ValidationException::class);

    $answer = ['key' => 'abuse_answer', 'name' => 'A', 'action' => 'answer', 'answer' => ['uz' => 'x'], 'params' => ['handoff_target' => 'service'], 'keywords' => [['phrase' => 'zzz', 'match_type' => 'word']]];

    expect(app(SaveChatRule::class)->handle($answer, $admin)->params)->toBeNull();
})->with(['admin', [['service']], 1]);

it('17, 18 and 19 service target: dialog not hand-off, +888 falls back to sales, manager stays sales', function () {
    cbSetup();
    $registered = cbUser(['locale' => 'ru']);
    $anonymous = TelegramUser::factory()->anonymousNumber()->create(['telegram_id' => CB_USER + 1, 'locale' => 'ru', 'chat_mode' => ChatMode::Bot]);

    cbText('не работает');
    cbText('не работает', CB_USER + 1);

    expect($registered->fresh()->chat_mode)->toBe(ChatMode::Bot)
        ->and($registered->fresh()->bot_dialog[LeadDialog::KEY]['flow'] ?? null)->toBe('service')
        ->and($anonymous->fresh()->chat_mode)->toBe(ChatMode::Manager)
        ->and(TelegramMessage::query()->where('direction', 'in')->count())->toBe(1);

    Setting::query()->updateOrCreate(['key' => ChatSettings::key('handoff_target')], ['group' => SettingsArea::Chatbot->group(), 'value' => 'service']);
    ChatSettings::forget();
    $other = cbUser(['locale' => 'ru'], CB_USER + 2);

    cbPress('chat:handoff', CB_USER + 2);

    expect($other->fresh()->chat_mode)->toBe(ChatMode::Manager);
});

it('18 web service target: no manager mode, no alert, one lead per chat', function () {
    wcSetup();
    Event::fake([WebChatHandedOff::class]);

    wcSend('printer is not working');
    $chat = WebChat::query()->sole();

    expect($chat->mode)->toBe(ChatMode::Bot);
    Event::assertNotDispatched(WebChatHandedOff::class);

    expect(app(SubmitWebChatLead::class)->handle($chat, 'Ali', '+998901234567', 'Does not print'))->toBeInstanceOf(Lead::class)
        ->and(app(SubmitWebChatLead::class)->handle($chat->fresh(), 'Ali', '+998901234567', 'Again'))->toBeNull()
        ->and(Lead::query()->count())->toBe(1);
});

it('20 migrates the shipped rules once, twice gives the same state, and bumps the rule cache', function () {
    abuseLegacyChatRules();
    $before = RuleIndex::version();

    abuseRunChatMigration();
    $first = abuseChatSnapshot();
    abuseRunChatMigration();

    expect(abuseChatSnapshot())->toEqual($first)
        ->and(RuleIndex::version())->toBeGreaterThan($before)
        ->and(ChatRule::query()->where('key', 'repair_intake')->sole()->params)->toBe(['handoff_target' => 'service'])
        ->and(ChatRule::query()->where('key', 'dealer')->sole()->params)->toBe(['handoff_target' => 'sales'])
        ->and(ChatRule::query()->where('key', 'setup_integration')->count())->toBe(1)
        ->and(ChatRule::query()->where('key', 'warranty_service')->sole()->keywords)->toHaveCount(10);

    $left = ChatRule::query()->where('key', 'warranty_service')->sole()->keywords->pluck('normalized')->all();

    foreach (['sozlash', 'sozlab', 'nastroyk', 'nastroit', 'ornatish', 'ustanovk', 'setup', 'integratsiya', 'integrats'] as $moved) {
        expect($left)->not->toContain($moved);
    }
});

it('20 leaves an owner-edited rule untouched', function () {
    abuseLegacyChatRules();
    $rule = ChatRule::query()->where('key', 'repair_intake')->sole();
    $rule->keywords()->create(['phrase' => 'extra', 'normalized' => 'extra', 'match_type' => 'word', 'weight' => 10]);

    $dealer = ChatRule::query()->where('key', 'dealer')->sole();
    $dealer->forceFill(['action' => ChatAction::Answer])->save();

    abuseRunChatMigration();

    expect($rule->fresh()->params)->toBeNull()
        ->and($dealer->fresh()->params)->toBeNull()
        ->and(ChatRule::query()->where('key', 'availability')->sole()->params)->toBe(['handoff_target' => 'sales']);
});

it('20 down() restores the original phrases and keeps an owner-edited setup_integration', function () {
    abuseLegacyChatRules();
    abuseRunChatMigration();

    $migration = require database_path('migrations/2026_11_18_140000_update_chatbot_rules_for_service_flow.php');
    $setup = ChatRule::query()->where('key', 'setup_integration')->sole();
    $setup->keywords()->create(['phrase' => 'extra', 'normalized' => 'extra', 'match_type' => 'word', 'weight' => 10]);

    $migration->down();

    $phrases = ChatRule::query()->where('key', 'warranty_service')->sole()->keywords->pluck('phrase')->all();

    expect($phrases)->toContain('sozlash', 'настройк', 'настроить', 'интеграц', 'setup')
        ->and(ChatRule::query()->where('key', 'setup_integration')->exists())->toBeTrue()
        ->and(ChatRule::query()->where('key', 'repair_intake')->sole()->params)->toBeNull();

    $setup->keywords()->where('normalized', 'extra')->delete();
    $migration->down();

    expect(ChatRule::query()->where('key', 'setup_integration')->exists())->toBeFalse();
});

it('keeps the migration and the shipped defaults in step for setup_integration', function () {
    $snapshot = fn (): array => [
        ChatRule::query()->where('key', 'setup_integration')->get(['action', 'params', 'priority', 'in_menu', 'is_system', 'answer'])->map->toArray()->all(),
        ChatRule::query()->where('key', 'setup_integration')->sole()->keywords->map(fn ($k) => [$k->phrase, $k->normalized, $k->match_type->value, $k->weight, $k->locale])->sortBy(0)->values()->all(),
    ];

    abuseLegacyChatRules();
    abuseRunChatMigration();
    $migrated = $snapshot();

    DB::table('chat_rules')->where('key', 'setup_integration')->delete();
    app(InstallChatbotDefaults::class)->handle();

    expect($snapshot())->toEqual($migrated);
});

it('20 is a no-op on an empty or non-system chat_rules table', function () {
    abuseRunChatMigration();

    expect(DB::table('chat_rules')->count())->toBe(0);

    ChatRule::factory()->create(['key' => 'custom']);
    abuseRunChatMigration();

    expect(DB::table('chat_rules')->count())->toBe(1);
});

it('21 shares the lead budget: a service form in the web chat gives one lead only', function () {
    wcSetup();

    wcSend('scanner not working', ip: '203.0.113.50');
    $chat = WebChat::query()->sole();

    expect(WebChatDialog::form($chat->dialog)['flow'])->toBe(LeadFlow::Service)
        ->and(app(SubmitWebChatLead::class)->handle($chat, 'Ali', '+998901234567', 'x'))->toBeInstanceOf(Lead::class)
        ->and(app(SubmitWebChatLead::class)->handle($chat->fresh(), 'Ali', '+998901234568', 'y'))->toBeNull();
});
