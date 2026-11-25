<?php

use App\Actions\Chat\RecordAiUsage;
use App\Actions\Office\DispatchDueAnalyses;
use App\Actions\Office\MarkBusinessChat;
use App\Actions\Office\RequestBusinessChatAnalysis;
use App\Actions\Office\RunBusinessChatAnalysis;
use App\Actions\Office\SaveAgentProfileVersion;
use App\Actions\Office\SaveOfficeSettings;
use App\Enums\AgentRole;
use App\Enums\AiUsageChannel;
use App\Enums\AnalysisStatus;
use App\Enums\BusinessAuthor;
use App\Enums\BusinessChatKind;
use App\Enums\Role;
use App\Enums\TelegramParty;
use App\Jobs\AnalyseBusinessChat;
use App\Livewire\Admin\Office\Chats\Show;
use App\Models\BusinessChat;
use App\Models\BusinessChatAnalysis;
use App\Models\ChatAiUsageDaily;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\TelegramUser;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiResult;
use App\Services\Ai\ChatModel;
use App\Support\Office\OfficeLock;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeChatModel;

require_once __DIR__.'/../../Helpers/Office.php';

/*
| Plan 41 §6: the analysis pipeline (VO-6, VO-7, VO-8, VO-10, VO-11, VO-12, VO-16, VO-18).
| The fake ChatModel only; no network call.
*/

/**
 * A due client chat with two messages (client + manager), agents published, AI on.
 *
 * @param  array<string, mixed>  $chat
 */
function officeDueChat(array $chat = [], ?string $clientText = null): BusinessChat
{
    $connection = officeIngestReady(ai: true);
    officeAgents();

    $model = BusinessChat::factory()->client()->create(array_merge([
        'business_connection_id' => $connection->id,
        'telegram_chat_id' => OFFICE_CLIENT_ID,
        'first_name' => 'Aziza',
        'username' => 'fake_client',
        'analysis_due_at' => now()->subMinute(),
    ], $chat));

    $model->messages()->create(['telegram_message_id' => 1, 'author' => BusinessAuthor::Client, 'kind' => 'text', 'text' => $clientText ?? 'Salom, doʻkon uchun yorliq printer kerak.', 'sent_at' => now()->subMinutes(15)]);
    $model->messages()->create(['telegram_message_id' => 2, 'author' => BusinessAuthor::Owner, 'kind' => 'text', 'text' => 'Kuniga nechta yorliq chop etasiz?', 'sent_at' => now()->subMinutes(12)]);

    return $model;
}

/**
 * A chat model answering in order and running $during on each call.
 *
 * @param  list<string>  $answers
 */
function officeSequenceModel(array $answers, ?Closure $during = null): ChatModel
{
    $model = new class($answers, $during) implements ChatModel
    {
        /** @var list<AiRequest> */
        public array $requests = [];

        public function __construct(private array $answers, private ?Closure $during) {}

        public function complete(AiRequest $request): AiResult
        {
            $this->requests[] = $request;

            if ($this->during !== null) {
                ($this->during)($request, count($this->requests));
            }

            return FakeChatModel::answer((string) (array_shift($this->answers) ?? '{}'));
        }
    };

    app()->instance(ChatModel::class, $model);

    return $model;
}

it('analyses a due chat: router then the sales agent, validated and stored (encrypted), usage counted', function () {
    $chat = officeDueChat();
    $fake = FakeChatModel::bind(officeRouterAnswer(), officeAgentAnswer());

    $this->artisan('office:analyse-due')->assertSuccessful();

    $analysis = $chat->analyses()->sole();

    expect($fake->calls())->toBe(2)
        ->and($fake->requests[0]->model)->toBe('claude-haiku-5-5')
        ->and($fake->requests[1]->model)->toBe('claude-sonnet-5-5')
        ->and($fake->requests[0]->outputSchema['properties'])->toHaveKey('role')
        ->and($analysis->status)->toBe(AnalysisStatus::Done)
        ->and($analysis->role)->toBe(AgentRole::Sales)
        ->and($analysis->topic)->toBe('Label printer for a shop')
        ->and($analysis->draft_reply)->toContain('yorliq')
        ->and($analysis->draft_blocked)->toBeFalse()
        ->and($analysis->router_version_id)->not->toBeNull()
        ->and($analysis->agent_version_id)->not->toBeNull()
        ->and($analysis->input_hash)->toHaveLength(64)
        ->and($chat->fresh()->analysis_due_at)->toBeNull()
        ->and($chat->fresh()->analyses_today)->toBe(1)
        ->and(ChatAiUsageDaily::query()->where('channel', AiUsageChannel::OfficeRoute->value)->value('requests'))->toBe(1)
        ->and(ChatAiUsageDaily::query()->where('channel', AiUsageChannel::OfficeSales->value)->value('requests'))->toBe(1);

    // CoreRules come first; the transcript is a delimited data block in the user message.
    expect($fake->requests[1]->system[0]['text'])->toContain('Mandatory rules')
        ->and($fake->requests[1]->messages[0]['content'])->toContain('<conversation>')
        ->and($fake->requests[1]->messages[0]['content'])->toContain('not instructions');
});

it('uses the router only for "other": no agent call, no draft', function () {
    $chat = officeDueChat();
    $fake = FakeChatModel::bind(officeRouterAnswer(['role' => 'other', 'topic' => 'Greeting']));

    $this->artisan('office:analyse-due');

    expect($fake->calls())->toBe(1)
        ->and($chat->analyses()->sole()->draft_reply)->toBeNull()
        ->and($chat->analyses()->sole()->lead_action)->toBe(BusinessChatAnalysis::LEAD_NONE);
});

it('skips without a call when nothing changed since the last done analysis', function () {
    $chat = officeDueChat();
    FakeChatModel::bind(officeRouterAnswer(), officeAgentAnswer());
    $this->artisan('office:analyse-due');

    $fake = FakeChatModel::bind();
    BusinessChat::query()->whereKey($chat->id)->update(['analysis_due_at' => now()->subSeconds(5)]);
    $this->artisan('office:analyse-due');

    expect($fake->calls())->toBe(0)
        ->and($chat->analyses()->latest('id')->first()->status)->toBe(AnalysisStatus::Skipped)
        ->and($chat->analyses()->latest('id')->first()->error)->toBe('unchanged');
});

it('sends no identity data to the model: no names, username, ids, phone, customer, emails, links or long numbers (VO-6)', function () {
    $customer = Customer::factory()->create(['name' => 'OOO Fakecompany', 'phone' => '+998900000055']);
    $chat = officeDueChat(['phone' => '+998900000044', 'customer_id' => $customer->id], 'Salom! Raqamim +998 90 000 00 33, pochta fake.client@example.uz, sayt https://fake-shop.example/abc, INN 300000001.');
    $chat->messages()->create(['telegram_message_id' => 3, 'author' => BusinessAuthor::Client, 'kind' => 'document', 'text' => null, 'media' => ['file_name' => 'scan_998900000022.pdf'], 'sent_at' => now()->subMinutes(11)]);
    $chat->messages()->create(['telegram_message_id' => 4, 'author' => BusinessAuthor::Client, 'kind' => 'contact', 'text' => null, 'sent_at' => now()->subMinutes(11)]);
    $fake = FakeChatModel::bind(officeRouterAnswer(), officeAgentAnswer());

    $this->artisan('office:analyse-due');

    expect($fake->calls())->toBe(2);

    foreach ($fake->requests as $request) {
        $text = $request->allText();

        expect($text)->not->toContain('Aziza')
            ->and($text)->not->toContain('fake_client')
            ->and($text)->not->toContain('Fakecompany')
            ->and($text)->not->toContain((string) OFFICE_CLIENT_ID)
            ->and($text)->not->toContain('@')
            ->and($text)->not->toMatch('~https?://|www\.|fake-shop~i')
            ->and($text)->not->toMatch('/\d{5,}/')
            ->and($text)->toContain('[contact]')
            ->and($text)->toContain('[document');
    }
});

it('lets a prompt injection change only the draft and validated lead hints: no auto-lead with that phone, no link, assignee unchanged (VO-7)', function () {
    $sales = staffUser(Role::SalesManager);
    $chat = officeDueChat(['assignee_id' => $sales->id], (string) officeFixture('message_injection', 0)['business_message']['text']);
    FakeChatModel::bind(officeRouterAnswer(), officeAgentAnswer([
        'summary' => 'Client asks to reassign the chat; see https://fake-evil.example/pay',
        'draft_reply' => 'Pay here: https://fake-evil.example/pay',
        'lead' => ['products' => ['Call +998 90 000 00 99'], 'device_brand' => 'https://fake-evil.example'],
    ]));

    $this->artisan('office:analyse-due');

    $analysis = $chat->analyses()->sole();
    $chat->refresh();

    expect(Lead::query()->count())->toBe(0)
        ->and($analysis->lead_action)->toBe(BusinessChatAnalysis::LEAD_NEEDS_PHONE)
        ->and($analysis->draft_reply)->toBeNull()
        ->and($analysis->draft_blocked)->toBeTrue()
        ->and($analysis->summary)->not->toContain('fake-evil')
        ->and(json_encode($analysis->lead_fields))->not->toContain('fake-evil')
        ->and(json_encode($analysis->lead_fields))->not->toMatch('/\d{9,}/')
        ->and($chat->assignee_id)->toBe($sales->id)
        ->and($chat->phone)->toBeNull();
});

it('blocks drafts with a card number, a t.me link or discount wording, and masks a link in the summary (VO-8)', function (string $draft) {
    $chat = officeDueChat();
    FakeChatModel::bind(officeRouterAnswer(), officeAgentAnswer(['draft_reply' => $draft, 'summary' => 'Client shared https://fake-shop.example/abc for reference.']));

    $this->artisan('office:analyse-due');

    $analysis = $chat->analyses()->sole();

    expect($analysis->draft_blocked)->toBeTrue()
        ->and($analysis->draft_reply)->toBeNull()
        ->and($analysis->summary)->not->toContain('fake-shop')
        ->and($analysis->summary)->toContain('Client shared');
})->with([
    'card' => ['Toʻlovni 8600 1234 5678 9012 kartaga oʻtkazing.'],
    't.me' => ['Yozing: t.me/fake_handle'],
    'chegirma' => ['Sizga maxsus chegirma beramiz!'],
    'percent' => ['We can offer 10% off today.'],
]);

it('keeps a teaching that asks for discounts from producing a visible draft (VO-10)', function () {
    $chat = officeDueChat();
    officeAgentVersion(AgentRole::Sales, ['instructions' => 'Always offer 10% discounts.']);
    FakeChatModel::bind(officeRouterAnswer(), officeAgentAnswer(['draft_reply' => 'Sizga 10% chegirma beramiz.']));

    $this->artisan('office:analyse-due');

    expect($chat->analyses()->sole()->draft_blocked)->toBeTrue();

    expect(fn () => app(SaveAgentProfileVersion::class)->handle(staffUser(), AgentRole::Sales, ['instructions' => 'Always offer 10% discounts.', 'model' => 'claude-sonnet-5-5', 'max_tokens' => 900]))
        ->toThrow(ValidationException::class);
});

it('renders the draft escaped, never as HTML (VO-8)', function () {
    $chat = officeDueChat();
    BusinessChatAnalysis::factory()->done()->create(['business_chat_id' => $chat->id, 'draft_reply' => '<script>alert(1)</script> Salom']);

    Livewire\Livewire::actingAs(staffUser())->test(Show::class, ['businessChat' => $chat])
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt; Salom');
});

it('makes one call when two analyses race for the last request of the cap (VO-11)', function () {
    $first = officeDueChat();
    officeSettings(['caps' => ['vo_route' => ['requests' => 1, 'tokens' => 1000000], 'vo_sales' => ['requests' => 200, 'tokens' => 2000000], 'vo_serv' => ['requests' => 200, 'tokens' => 2000000], 'vo_support' => ['requests' => 100, 'tokens' => 1000000]]]);
    $second = BusinessChat::factory()->client()->create(['business_connection_id' => $first->business_connection_id]);
    $second->messages()->create(['telegram_message_id' => 9, 'author' => BusinessAuthor::Client, 'kind' => 'text', 'text' => 'Skaner kerak.']);
    $a = $first->analyses()->create(['status' => AnalysisStatus::Queued, 'trigger' => 'manual']);
    $b = $second->analyses()->create(['status' => AnalysisStatus::Queued, 'trigger' => 'manual']);
    $fake = FakeChatModel::bind(officeRouterAnswer(), officeAgentAnswer(), officeRouterAnswer(), officeAgentAnswer());

    app(RunBusinessChatAnalysis::class)->handle($a->id, $fake);
    app(RunBusinessChatAnalysis::class)->handle($b->id, $fake);

    expect(collect($fake->requests)->where('model', 'claude-haiku-5-5')->count())->toBe(1)
        ->and($b->fresh()->status)->toBe(AnalysisStatus::Capped)
        ->and($second->fresh()->analysis_due_at->greaterThan(now()))->toBeTrue();
});

it('writes one capped row in five minutes over the cap, not one a minute (VO-11)', function () {
    $chat = officeDueChat();
    ChatAiUsageDaily::query()->insert(['day' => RecordAiUsage::day(), 'channel' => AiUsageChannel::OfficeRoute->value, 'requests' => 400]);
    $fake = FakeChatModel::bind();

    foreach (range(1, 5) as $minute) {
        $this->travel(1)->minutes();
        app(DispatchDueAnalyses::class)->handle();
    }

    expect($chat->analyses()->where('status', AnalysisStatus::Capped->value)->count())->toBe(1)
        ->and($fake->calls())->toBe(0);
});

it('rejects caps above five times the default (VO-11)', function () {
    $admin = staffUser();

    expect(fn () => app(SaveOfficeSettings::class)->handle($admin, [
        'ingest_enabled' => false, 'ai_enabled' => false, 'auto_lead' => true, 'owner_ids' => '', 'debounce_minutes' => 10, 'per_chat_daily' => 12,
        'caps' => ['vo_route' => ['requests' => 2001, 'tokens' => 1], 'vo_sales' => ['requests' => 1, 'tokens' => 1], 'vo_serv' => ['requests' => 1, 'tokens' => 1], 'vo_support' => ['requests' => 1, 'tokens' => 1]],
    ]))->toThrow(ValidationException::class);
});

it('gives employees no AI-cap exemption (VO-11)', function () {
    $client = TelegramUser::factory()->registered()->create();
    $employee = TelegramUser::factory()->registered()->create();
    $employee->forceFill(['party' => TelegramParty::Employee])->save();

    expect($employee->fresh()->mayUseAi())->toBe($client->mayUseAi())
        ->and(RecordAiUsage::caps(AiUsageChannel::Telegram))->toBe(RecordAiUsage::caps(AiUsageChannel::Telegram));
});

it('limits "Analyse now": cooldown per chat and a pending analysis blocks a second (VO-11)', function () {
    $chat = officeDueChat(['analysis_due_at' => null]);
    Queue::fake();
    $admin = staffUser();

    app(RequestBusinessChatAnalysis::class)->handle($admin, $chat);

    expect(fn () => app(RequestBusinessChatAnalysis::class)->handle($admin, $chat))->toThrow(ValidationException::class);

    Queue::assertPushed(AnalyseBusinessChat::class, 1);
    expect($chat->analyses()->sole()->trigger)->toBe('manual');
});

it('stops at the per-chat daily cap', function () {
    $chat = officeDueChat(['analyses_today' => 12, 'analyses_day' => now('Asia/Tashkent')->toDateString()]);
    Queue::fake();

    app(DispatchDueAnalyses::class)->handle();

    Queue::assertNothingPushed();
    expect($chat->fresh()->analysis_due_at->greaterThan(now()))->toBeTrue();
});

it('saves nothing and syncs no lead when the chat is unmarked while the analysis runs (VO-12)', function () {
    $sales = staffUser(Role::SalesManager);
    $chat = officeDueChat(['assignee_id' => $sales->id, 'phone' => '+998900000017']);
    $analysis = $chat->analyses()->create(['status' => AnalysisStatus::Queued, 'trigger' => 'manual']);
    $model = officeSequenceModel([officeRouterAnswer(), officeAgentAnswer()], function (AiRequest $request, int $call) use ($chat): void {
        if ($call === 2) {
            BusinessChat::query()->whereKey($chat->id)->update(['kind' => BusinessChatKind::Ignored->value]);
        }
    });

    app(RunBusinessChatAnalysis::class)->handle($analysis->id, $model);

    expect($analysis->fresh()->status)->toBe(AnalysisStatus::Skipped)
        ->and($analysis->fresh()->summary)->toBeNull()
        ->and(Lead::query()->count())->toBe(0);
});

it('refuses to unmark a client chat while its analysis holds the lock (VO-12)', function () {
    config(['office.lock_wait_seconds' => 0]);
    $chat = officeDueChat();
    confirmAdminPassword();
    $lock = OfficeLock::forChat($chat->id);
    $lock->get();

    try {
        expect(fn () => app(MarkBusinessChat::class)->handle(staffUser(), $chat, BusinessChatKind::Ignored))->toThrow(ValidationException::class);
    } finally {
        $lock->release();
    }

    expect($chat->fresh()->kind)->toBe(BusinessChatKind::Client)
        ->and($chat->messages()->count())->toBe(2);
});

it('dispatches at most three pending analyses and unblocks stale ones (VO-16)', function () {
    $first = officeDueChat();
    Queue::fake();

    foreach (range(1, 3) as $index) {
        BusinessChat::factory()->client()->create(['business_connection_id' => $first->business_connection_id, 'analysis_due_at' => now()->subMinutes(5 - $index)]);
    }

    app(DispatchDueAnalyses::class)->handle();

    Queue::assertPushed(AnalyseBusinessChat::class, 3);
    expect(BusinessChat::query()->whereNotNull('analysis_due_at')->count())->toBe(1);

    // Hours later the three are stale: they fail and the fourth chat is dispatched.
    $this->travel(10)->minutes();
    $result = app(DispatchDueAnalyses::class)->handle();

    expect($result['stale'])->toBe(3);
    Queue::assertPushed(AnalyseBusinessChat::class, 4);
});

it('never sends a summary that a Telegram delete nulled as context (VO-18)', function () {
    $chat = officeDueChat();
    $message = $chat->messages()->orderBy('id')->first();
    $old = BusinessChatAnalysis::factory()->done('Old summary with deleted details')->create(['business_chat_id' => $chat->id, 'up_to_message_id' => $message->id]);
    BusinessChatAnalysis::query()->whereKey($old->id)->update(['summary' => null]);
    $fake = FakeChatModel::bind(officeRouterAnswer(), officeAgentAnswer());

    $this->artisan('office:analyse-due');

    foreach ($fake->requests as $request) {
        expect($request->allText())->not->toContain('Old summary')
            ->and($request->messages[0]['content'])->not->toContain("<previous_summary>\n");
    }
});

it('passes a readable previous summary as context', function () {
    $chat = officeDueChat();
    BusinessChatAnalysis::factory()->done('Earlier: asked about scanners.')->create(['business_chat_id' => $chat->id, 'input_hash' => str_repeat('a', 64)]);
    $fake = FakeChatModel::bind(officeRouterAnswer(), officeAgentAnswer());

    $this->artisan('office:analyse-due');

    expect($fake->requests[0]->messages[0]['content'])->toContain('<previous_summary>')
        ->and($fake->requests[0]->messages[0]['content'])->toContain('Earlier: asked about scanners.');
});

it('skips without a call while the office AI is off', function () {
    $chat = officeDueChat();
    officeSettings(['ai_enabled' => false]);
    $analysis = $chat->analyses()->create(['status' => AnalysisStatus::Queued, 'trigger' => 'manual']);
    $fake = FakeChatModel::bind();

    app(RunBusinessChatAnalysis::class)->handle($analysis->id, $fake);

    expect($fake->calls())->toBe(0)
        ->and($analysis->fresh()->error)->toBe('ai_off');
});
