<?php

use App\Actions\Chat\RecordAiUsage;
use App\Actions\Office\MarkBusinessChat;
use App\Actions\Office\PublishAgentProfileVersion;
use App\Actions\Office\RequestBusinessChatAnalysis;
use App\Actions\Office\RunBusinessChatAnalysis;
use App\Actions\Office\SaveOfficeSettings;
use App\Actions\Office\UpsertBusinessConnection;
use App\Actions\Telegram\ConfigureTelegramWebhook;
use App\Actions\Telegram\HandleTelegramUpdate;
use App\Enums\AgentRole;
use App\Enums\AiUsageChannel;
use App\Enums\AnalysisStatus;
use App\Enums\AuditAction;
use App\Enums\BusinessAuthor;
use App\Enums\BusinessChatKind;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Enums\TelegramParty;
use App\Jobs\AnalyseBusinessChat;
use App\Jobs\FetchBusinessConnection;
use App\Livewire\Admin\Office\Chats\Index;
use App\Livewire\Admin\Office\Chats\Show;
use App\Livewire\Admin\Office\Education;
use App\Livewire\Admin\Telegram\Bot;
use App\Models\AgentProfile;
use App\Models\BusinessChat;
use App\Models\BusinessChatAnalysis;
use App\Models\BusinessConnection;
use App\Models\BusinessMessage;
use App\Models\ChatAiUsageDaily;
use App\Models\Lead;
use App\Models\SettingsAudit;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiResult;
use App\Services\Ai\ChatModel;
use App\Services\Telegram\TelegramClient;
use App\Support\Office\OfficeLimits;
use App\Support\Office\OfficeSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\FakeChatModel;

require_once __DIR__.'/../../Helpers/Office.php';

/*
| Plan 41 §18 abuse cases for the Virtual office (VO-1 ... VO-18), in process,
| test DB only, the fake ChatModel and Http::fake: no Anthropic, no Telegram.
|
| Already covered properly elsewhere (not repeated here):
|  - case 2 delimiter breakout, client text and previous summary
|    (OfficeReviewFixesTest "cannot close the conversation delimiter ...", M1);
|  - case 3 file names at rest (OfficeReviewFixesTest "stores document file names only redacted", M2);
|  - case 12 wipe completeness: activities of a business chat, connections (OfficeEducationSettingsTest "wipes all business data ...");
|  - case 13 delete clears the topic on the analysis and the AiSummary meta (OfficeReviewFixesTest L1);
|  - case 1 with ingest off (OfficeEducationSettingsTest "ignores the submitted ids while OFFICE_OWNER_TELEGRAM_IDS locks ..."),
|    only the AI-off variant is added below.
*/

beforeEach(function () {
    RateLimiter::clear(OfficeLimits::NEW_CHATS_MINUTE);
    RateLimiter::clear(OfficeLimits::NEW_CHATS_DAY);
    RateLimiter::clear(OfficeLimits::FETCHES_HOUR);
});

/**
 * A client chat with one stored client message, due now, AI on, agents installed.
 *
 * @param  array<string, mixed>  $attributes
 */
function abuseOfficeChat(array $attributes = [], string $text = 'Salom, doʻkon uchun yorliq printer kerak.'): BusinessChat
{
    $connection = BusinessConnection::query()->where('connection_id', OFFICE_CONNECTION_ID)->first() ?? officeIngestReady(ai: true);
    officeAgents();

    $chat = BusinessChat::factory()->client()->create(array_merge([
        'business_connection_id' => $connection->id,
        'first_name' => 'Aziza',
        'analysis_due_at' => now()->subMinute(),
    ], $attributes));
    $chat->messages()->create(['telegram_message_id' => 1, 'author' => BusinessAuthor::Client, 'kind' => 'text', 'text' => $text, 'sent_at' => now()->subMinutes(15)]);

    return $chat;
}

function abuseQueuedAnalysis(BusinessChat $chat, string $trigger = 'manual'): BusinessChatAnalysis
{
    return $chat->analyses()->create(['status' => AnalysisStatus::Queued, 'trigger' => $trigger]);
}

/**
 * Today's counters of one usage channel.
 *
 * @return array{requests: int, capped: int}
 */
function abuseUsage(AiUsageChannel $channel): array
{
    $row = ChatAiUsageDaily::query()->where('day', RecordAiUsage::day())->where('channel', $channel->value)->first();

    return ['requests' => (int) ($row->requests ?? 0), 'capped' => (int) ($row->capped ?? 0)];
}

function abuseSeedUsage(AiUsageChannel $channel, int $requests): void
{
    ChatAiUsageDaily::query()->insert(['day' => RecordAiUsage::day(), 'channel' => $channel->value, 'requests' => $requests]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function abuseSettingsData(array $overrides = []): array
{
    return array_replace_recursive([
        'ingest_enabled' => true, 'ai_enabled' => false, 'auto_lead' => true, 'owner_ids' => '', 'debounce_minutes' => 10, 'per_chat_daily' => 12,
        'caps' => [
            'vo_route' => ['requests' => 400, 'tokens' => 1000000], 'vo_sales' => ['requests' => 200, 'tokens' => 2000000],
            'vo_serv' => ['requests' => 200, 'tokens' => 2000000], 'vo_support' => ['requests' => 100, 'tokens' => 1000000],
        ],
    ], $overrides);
}

// 1. Owner id locked in .env -----------------------------------------------------------------

it('saves while the allow-list is locked in .env with an empty DB value, with ingest or AI off, and allows an earlier connection (VO-1)', function (array $overrides) {
    config(['office.owner_telegram_ids' => (string) OFFICE_OWNER_ID]);
    $connection = BusinessConnection::factory()->create(['owner_telegram_id' => OFFICE_OWNER_ID, 'is_allowed' => false]);

    // No password confirmation and no stored ids: the locked value is not a change.
    $changed = app(SaveOfficeSettings::class)->handle(staffUser(), abuseSettingsData($overrides + ['owner_ids' => '']));

    expect($changed)->toBeGreaterThan(0)
        ->and(OfficeSettings::ownerIds())->toBe([OFFICE_OWNER_ID])
        ->and($connection->fresh()->is_allowed)->toBeTrue();
})->with([
    'AI off' => [['ai_enabled' => false]],
    'ingest off' => [['ingest_enabled' => false]],
    'both off' => [['ingest_enabled' => false, 'ai_enabled' => false]],
]);

// 4. Manual analysis limit -------------------------------------------------------------------

it('refuses the 51st "Analyse now" of one user in a Tashkent day across different chats, each past its cooldown (VO-11)', function () {
    officeIngestReady(ai: true);
    Queue::fake();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $other = User::factory()->withRole(Role::Admin)->create();
    $chats = BusinessChat::factory()->client()->count(52)->create(['business_connection_id' => BusinessConnection::query()->value('id')]);

    foreach ($chats->take(50) as $chat) {
        $chat->messages()->create(['telegram_message_id' => 1, 'author' => BusinessAuthor::Client, 'kind' => 'text', 'text' => 'Skaner kerak']);
        app(RequestBusinessChatAnalysis::class)->handle($admin, $chat);
    }

    $fifty1 = $chats[50];

    expect(fn () => app(RequestBusinessChatAnalysis::class)->handle($admin, $fifty1))->toThrow(ValidationException::class)
        ->and($fifty1->analyses()->count())->toBe(0);

    Queue::assertPushed(AnalyseBusinessChat::class, 50);

    // The counter is per user: a colleague is not blocked, and the refused chat did not burn its cooldown.
    app(RequestBusinessChatAnalysis::class)->handle($other, $fifty1);

    expect($fifty1->analyses()->count())->toBe(1);

    // The next Tashkent day the first user may analyse again.
    $this->travel(25)->hours();
    app(RequestBusinessChatAnalysis::class)->handle($admin, $chats[51]);

    expect($chats[51]->analyses()->count())->toBe(1);
});

// 5. Concurrency at the cap ------------------------------------------------------------------

it('makes one router call when two runs on different chats interleave one request below the cap (VO-11)', function () {
    $first = abuseOfficeChat();
    $second = BusinessChat::factory()->client()->create(['business_connection_id' => $first->business_connection_id]);
    $second->messages()->create(['telegram_message_id' => 9, 'author' => BusinessAuthor::Client, 'kind' => 'text', 'text' => 'Skaner kerak.']);
    $cap = OfficeSettings::caps(AiUsageChannel::OfficeRoute)['requests'];
    abuseSeedUsage(AiUsageChannel::OfficeRoute, $cap - 1);
    $a = abuseQueuedAnalysis($first);
    $b = abuseQueuedAnalysis($second);

    // The first run holds its reservation (taken, not yet settled) while the second run starts on its own chat lock.
    $model = new class(app(RunBusinessChatAnalysis::class), $b->id) implements ChatModel
    {
        /** @var list<AiRequest> */
        public array $requests = [];

        public function __construct(private RunBusinessChatAnalysis $run, private int $otherAnalysisId) {}

        public function complete(AiRequest $request): AiResult
        {
            $this->requests[] = $request;

            if (count($this->requests) === 1) {
                $this->run->handle($this->otherAnalysisId, $this);
            }

            return FakeChatModel::answer(count($this->requests) === 1 ? officeRouterAnswer() : officeAgentAnswer());
        }
    };

    app(RunBusinessChatAnalysis::class)->handle($a->id, $model);

    $routerCalls = collect($model->requests)->where('model', 'claude-haiku-5-5')->count();

    expect($routerCalls)->toBe(1)
        ->and($b->fresh()->status)->toBe(AnalysisStatus::Capped)
        ->and($a->fresh()->status)->toBe(AnalysisStatus::Done)
        ->and(abuseUsage(AiUsageChannel::OfficeRoute))->toBe(['requests' => $cap, 'capped' => 1]);
});

// 6. Employees ---------------------------------------------------------------------------------

it('refuses to analyse an employee chat and makes no call or count (VO-11)', function () {
    $chat = abuseOfficeChat();
    $chat->forceFill(['kind' => BusinessChatKind::Employee])->save();
    $fake = FakeChatModel::bind();
    Queue::fake();
    $before = [abuseUsage(AiUsageChannel::OfficeRoute), abuseUsage(AiUsageChannel::OfficeSales)];

    // The Admin passes the policy (Gate::before), the action still refuses a chat that is not a client chat.
    expect(fn () => app(RequestBusinessChatAnalysis::class)->handle(staffUser(), $chat))->toThrow(ValidationException::class)
        ->and(fn () => app(RequestBusinessChatAnalysis::class)->handle(staffUser(Role::SalesManager), $chat))->toThrow(AuthorizationException::class);

    Queue::assertNothingPushed();

    // A row queued before the mark is skipped without a call.
    $analysis = abuseQueuedAnalysis($chat);
    app(RunBusinessChatAnalysis::class)->handle($analysis->id, $fake);

    expect($fake->calls())->toBe(0)
        ->and($analysis->fresh()->status)->toBe(AnalysisStatus::Skipped)
        ->and($analysis->fresh()->error)->toBe('not_client')
        ->and([abuseUsage(AiUsageChannel::OfficeRoute), abuseUsage(AiUsageChannel::OfficeSales)])->toBe($before);
});

it('still counts the calls of a run whose chat became an employee chat meanwhile, and saves nothing (VO-11, VO-12)', function () {
    $sales = staffUser(Role::SalesManager);
    $chat = abuseOfficeChat(['assignee_id' => $sales->id, 'phone' => '+998900000017']);
    $analysis = abuseQueuedAnalysis($chat);
    $before = [abuseUsage(AiUsageChannel::OfficeRoute), abuseUsage(AiUsageChannel::OfficeSales)];
    $answers = [officeRouterAnswer(), officeAgentAnswer()];
    $model = new class($chat->id, $answers) implements ChatModel
    {
        public int $calls = 0;

        public function __construct(private int $chatId, private array $answers) {}

        public function complete(AiRequest $request): AiResult
        {
            if ($this->calls++ === 0) {
                BusinessChat::query()->whereKey($this->chatId)->update(['kind' => BusinessChatKind::Employee->value]);
            }

            return FakeChatModel::answer($this->answers[$this->calls - 1]);
        }
    };

    app(RunBusinessChatAnalysis::class)->handle($analysis->id, $model);

    expect($model->calls)->toBe(2)
        ->and(abuseUsage(AiUsageChannel::OfficeRoute)['requests'] - $before[0]['requests'])->toBe(1)
        ->and(abuseUsage(AiUsageChannel::OfficeSales)['requests'] - $before[1]['requests'])->toBe(1)
        ->and($analysis->fresh()->status)->toBe(AnalysisStatus::Skipped)
        ->and($analysis->fresh()->summary)->toBeNull()
        ->and(Lead::query()->count())->toBe(0);
});

// 7. Agent channel at its cap ----------------------------------------------------------------

it('caps the analysis when only the agent channel is at its cap: due tomorrow, no lead, one capped increment (VO-11)', function () {
    $sales = staffUser(Role::SalesManager);
    $chat = abuseOfficeChat(['assignee_id' => $sales->id, 'phone' => '+998900000017']);
    abuseSeedUsage(AiUsageChannel::OfficeSales, OfficeSettings::caps(AiUsageChannel::OfficeSales)['requests']);
    $analysis = abuseQueuedAnalysis($chat);
    $fake = FakeChatModel::bind(officeRouterAnswer(), officeAgentAnswer());
    $salesBefore = abuseUsage(AiUsageChannel::OfficeSales);

    app(RunBusinessChatAnalysis::class)->handle($analysis->id, $fake);

    $due = $chat->fresh()->analysis_due_at;

    expect($fake->calls())->toBe(1)
        ->and($analysis->fresh()->status)->toBe(AnalysisStatus::Capped)
        ->and($analysis->fresh()->draft_reply)->toBeNull()
        ->and($due->setTimezone('Asia/Tashkent')->toDateString())->toBe(now('Asia/Tashkent')->addDay()->toDateString())
        ->and($due->setTimezone('Asia/Tashkent')->format('H:i'))->toBe('00:00')
        ->and(Lead::query()->count())->toBe(0)
        ->and(abuseUsage(AiUsageChannel::OfficeSales)['requests'])->toBe($salesBefore['requests'])
        ->and(abuseUsage(AiUsageChannel::OfficeSales)['capped'])->toBe($salesBefore['capped'] + 1)
        ->and(abuseUsage(AiUsageChannel::OfficeRoute))->toBe(['requests' => 1, 'capped' => 0]);

    // The same day the chat is not queued again; one capped row for the router stage did not pile up.
    $this->travel(5)->minutes();
    Queue::fake();
    $this->artisan('office:analyse-due')->assertSuccessful();

    Queue::assertNothingPushed();
});

// 8. Sender spoofing -------------------------------------------------------------------------

it('stores no client message and no unread count for a message from the owner in the owner chat (Saved Messages) (VO-1)', function (string $kind) {
    $connection = officeIngestReady();

    if ($kind === 'client') {
        BusinessChat::factory()->client()->create(['business_connection_id' => $connection->id, 'telegram_chat_id' => OFFICE_OWNER_ID]);
    }

    app(HandleTelegramUpdate::class)->handle(officeFixture('message_client', 1, ['business_message' => ['chat' => ['id' => OFFICE_OWNER_ID], 'from' => ['id' => OFFICE_OWNER_ID]]]));

    expect(BusinessMessage::query()->where('author', BusinessAuthor::Client->value)->count())->toBe(0)
        ->and((int) BusinessChat::query()->sum('unread_count'))->toBe(0)
        ->and(BusinessMessage::query()->count())->toBe($kind === 'client' ? 1 : 0);
})->with(['unmarked stub' => ['unmarked'], 'client chat' => ['client']]);

// 9. Unknown-connection spoof ----------------------------------------------------------------

it('stores nothing from a getBusinessConnection answer whose id differs from the requested one, and drops the later messages (VO-3, VO-13)', function () {
    telegramBotConfigured();
    officeSettings(['ingest_enabled' => true, 'owner_telegram_ids' => [OFFICE_OWNER_ID]]);
    Http::preventStrayRequests();
    $spoofed = officeFixture('connection_enabled', 0)['business_connection'];
    $spoofed['id'] = 'fake-conn-7777';
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => $spoofed])]);

    (new FetchBusinessConnection(OFFICE_CONNECTION_ID))->handle(app(TelegramClient::class), app(UpsertBusinessConnection::class));

    // The queue is sync in tests: an unknown connection id in a message fetches it at once, and the spoofed answer is not stored.
    app(HandleTelegramUpdate::class)->handle(officeFixture('message_client', 1));
    app(HandleTelegramUpdate::class)->handle(officeFixture('message_client', 2, ['business_message' => ['message_id' => 102]]));

    expect(BusinessConnection::query()->count())->toBe(0)
        ->and(BusinessChat::query()->count())->toBe(0)
        ->and(BusinessMessage::query()->count())->toBe(0);
});

it('stores an answer whose owner is not on the allow-list as a disallowed row, and drops its messages (VO-1)', function () {
    telegramBotConfigured();
    officeSettings(['ingest_enabled' => true, 'owner_telegram_ids' => [9990000002]]);
    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => officeFixture('connection_enabled', 0)['business_connection']])]);

    (new FetchBusinessConnection(OFFICE_CONNECTION_ID))->handle(app(TelegramClient::class), app(UpsertBusinessConnection::class));

    app(HandleTelegramUpdate::class)->handle(officeFixture('message_client', 1));
    app(HandleTelegramUpdate::class)->handle(officeFixture('message_client', 2, ['business_message' => ['message_id' => 102]]));

    expect(BusinessConnection::query()->sole()->is_allowed)->toBeFalse()
        ->and(BusinessChat::query()->count())->toBe(0)
        ->and(BusinessMessage::query()->count())->toBe(0);
});

// 10. failed_jobs ----------------------------------------------------------------------------

it('keeps message text, summary and names out of failed_jobs when the analysis job throws on the database queue (VO-5)', function () {
    config(['queue.default' => 'database']);
    $chat = abuseOfficeChat(['first_name' => 'Zafarbek', 'username' => 'fake_zafarbek'], 'Maxfiy matn: 40 mm yorliq printer kerak');
    $analysis = abuseQueuedAnalysis($chat);
    BusinessChatAnalysis::factory()->done('Maxfiy xulosa: doʻkon yorliq printer')->create(['business_chat_id' => $chat->id, 'input_hash' => str_repeat('a', 64)]);

    // The action reads the decrypted data, then fails like a crash after the model call.
    $this->mock(RunBusinessChatAnalysis::class)->shouldReceive('handle')->once()->andReturnUsing(function () use ($chat): void {
        $chat->messages()->first()->text;
        $chat->analyses()->whereNotNull('summary')->first()->summary;

        throw new RuntimeException('forced failure');
    });

    dispatch(new AnalyseBusinessChat($analysis->id));

    expect(DB::table('jobs')->count())->toBe(1);

    Artisan::call('queue:work', ['--once' => true, '--queue' => 'ai-admin', '--tries' => 1, '--sleep' => 0]);

    $failed = DB::table('failed_jobs')->sole();
    $dump = $failed->payload.' '.$failed->exception;

    expect($dump)->not->toContain('Maxfiy')
        ->and($dump)->not->toContain('Zafarbek')
        ->and($dump)->not->toContain('fake_zafarbek')
        ->and($dump)->not->toContain('yorliq')
        ->and(json_decode($failed->payload, true)['data']['command'])->toContain('AnalyseBusinessChat')
        ->and($analysis->fresh()->status)->toBe(AnalysisStatus::Failed)
        ->and($analysis->fresh()->error)->toBe('error');
});

// 11. Re-marking -----------------------------------------------------------------------------

it('brings back no messages or analyses on Client, Ignored, Client, and clears the party on Ignored (VO-12)', function () {
    $connection = officeIngestReady();
    $admin = staffUser();
    $chat = BusinessChat::factory()->client()->create(['business_connection_id' => $connection->id, 'telegram_chat_id' => OFFICE_CLIENT_ID]);
    app(HandleTelegramUpdate::class)->handle(officeFixture('message_client', 1));
    BusinessChatAnalysis::factory()->done('Old summary')->create(['business_chat_id' => $chat->id, 'up_to_message_id' => $chat->messages()->value('id')]);
    $chat->forceFill(['last_analysed_message_id' => $chat->messages()->value('id')])->save();
    $botUser = TelegramUser::factory()->create(['telegram_id' => OFFICE_CLIENT_ID]);
    $botUser->forceFill(['party' => TelegramParty::Client])->save();
    confirmAdminPassword();

    app(MarkBusinessChat::class)->handle($admin, $chat, BusinessChatKind::Ignored);

    expect($botUser->fresh()->party)->toBeNull()
        ->and($chat->messages()->count())->toBe(0)
        ->and($chat->analyses()->count())->toBe(0);

    // A new message while ignored stores nothing, and the first Telegram edit / delete of old messages creates nothing later.
    app(HandleTelegramUpdate::class)->handle(officeFixture('message_client', 2, ['business_message' => ['message_id' => 150]]));
    app(MarkBusinessChat::class)->handle($admin, $chat->fresh(), BusinessChatKind::Client);
    app(HandleTelegramUpdate::class)->handle(officeFixture('message_edited', 3));
    app(HandleTelegramUpdate::class)->handle(officeFixture('messages_deleted', 4));

    $fresh = $chat->fresh();

    expect($fresh->kind)->toBe(BusinessChatKind::Client)
        ->and($fresh->messages()->count())->toBe(0)
        ->and($fresh->analyses()->count())->toBe(0)
        ->and($fresh->last_analysed_message_id)->toBeNull()
        ->and($fresh->analysis_due_at)->toBeNull()
        ->and($fresh->assignee_id)->toBeNull()
        ->and($botUser->fresh()->party)->toBe(TelegramParty::Client);
});

// 14. Tampered Show properties ---------------------------------------------------------------

dataset('abuse-assignees', [
    'inactive user' => fn () => User::factory()->withRole(Role::SalesManager)->create(['is_active' => false])->id,
    'content manager' => fn () => User::factory()->withRole(Role::ContentManager)->create()->id,
    'missing id' => fn () => 987654321,
]);

it('rejects an assign target that is inactive, a content manager or missing (VO-9)', function (int $target, Role $actorRole) {
    $actor = staffUser($actorRole);
    $chat = BusinessChat::factory()->client($actor)->create();

    Livewire::actingAs($actor)->test(Show::class, ['businessChat' => $chat])
        ->set('assignTarget', $target)
        ->call('assign')
        ->assertHasErrors('assignee');

    expect($chat->fresh()->assignee_id)->toBe($actor->id);
})->with('abuse-assignees')->with([Role::Admin, Role::SalesManager]);

it('rejects a re-mark assignee that is inactive, a content manager or missing (VO-9)', function (int $target) {
    $admin = staffUser();
    $chat = BusinessChat::factory()->client($admin)->create();

    Livewire::actingAs($admin)->test(Show::class, ['businessChat' => $chat])
        ->set('markKind', 'client')
        ->set('markAssignee', $target)
        ->call('remark')
        ->assertHasErrors('assignee');

    expect($chat->fresh()->assignee_id)->toBe($admin->id);
})->with('abuse-assignees');

it('rejects lead type order and a junk lead phone on the lead form (VO-7)', function (string $type, string $phone, string $error) {
    $admin = staffUser();
    $chat = BusinessChat::factory()->client($admin)->create();

    Livewire::actingAs($admin)->test(Show::class, ['businessChat' => $chat])
        ->set('leadType', $type)
        ->set('leadPhone', $phone)
        ->call('createLead')
        ->assertHasErrors($error);

    expect(Lead::query()->count())->toBe(0)
        ->and($chat->fresh()->lead_id)->toBeNull()
        ->and($chat->fresh()->phone)->toBeNull();
})->with([
    'order type' => ['order', '+998900000017', 'leadType'],
    'unknown type' => ['admin', '+998900000017', 'leadType'],
    'letters' => ['quote', 'call me maybe', 'leadPhone'],
    'markup' => ['quote', '<script>alert(1)</script>', 'leadPhone'],
    'too short' => ['quote', '123', 'leadPhone'],
    'too long' => ['quote', str_repeat('9', 33), 'leadPhone'],
]);

// 15. Cross-role versions --------------------------------------------------------------------

it('refuses publish and edit with another role’s version id and leaves the profile unchanged (VO-10)', function () {
    officeAgents();
    $admin = staffUser();
    $router = AgentProfile::forRole(AgentRole::Router);
    $foreign = AgentProfile::forRole(AgentRole::Sales)->current_version_id;
    $currentId = $router->current_version_id;
    $versions = $router->versions()->count();
    $audits = SettingsAudit::query()->where('area', SettingsArea::Office->value)->count();

    expect(fn () => app(PublishAgentProfileVersion::class)->handle($admin, $router, $foreign))->toThrow(ValidationException::class);

    $screen = Livewire::actingAs($admin)->test(Education::class, ['role' => 'router']);
    $instructions = $screen->get('instructions');
    $screen->call('publish', $foreign)->assertHasErrors('version')
        ->call('edit', $foreign)->assertHasErrors('version')
        ->assertSet('instructions', $instructions);

    expect($router->fresh()->current_version_id)->toBe($currentId)
        ->and($router->versions()->count())->toBe($versions)
        ->and(AgentProfile::forRole(AgentRole::Sales)->versions()->count())->toBe(1)
        ->and(SettingsAudit::query()->where('area', SettingsArea::Office->value)->count())->toBe($audits);
});

// 16. Index filter tampering -----------------------------------------------------------------

it('shows a sales manager only own chats whatever the assignee filter says (VO-9)', function (string $assignee) {
    $sales = staffUser(Role::SalesManager);
    $colleague = User::factory()->withRole(Role::SalesManager)->create();
    BusinessChat::factory()->client($sales)->create(['first_name' => 'OwnChatAziza']);
    BusinessChat::factory()->client($colleague)->create(['first_name' => 'ForeignChatBobur']);
    BusinessChat::factory()->client()->create(['first_name' => 'UnassignedChatCamila']);

    $component = Livewire::actingAs($sales)->test(Index::class)->set('assignee', $assignee === 'colleague' ? (string) $colleague->id : $assignee);

    $component->assertSee('OwnChatAziza')
        ->assertDontSee('ForeignChatBobur')
        ->assertDontSee('UnassignedChatCamila');
})->with(['other user' => ['colleague'], 'none' => ['none'], 'junk' => ['1 OR 1=1']]);

// 17. Global flood limit ---------------------------------------------------------------------

it('creates at most 30 new chats a minute across two allowed connections (VO-3)', function () {
    officeIngestReady();
    BusinessConnection::factory()->create(['connection_id' => 'fake-conn-0002', 'owner_telegram_id' => OFFICE_OWNER_ID, 'user_chat_id' => OFFICE_OWNER_ID]);

    foreach (range(1, 31) as $index) {
        $id = 9990040000 + $index;
        app(HandleTelegramUpdate::class)->handle(officeFixture('message_client', $index, ['business_message' => [
            'business_connection_id' => $index % 2 === 0 ? 'fake-conn-0002' : OFFICE_CONNECTION_ID,
            'chat' => ['id' => $id], 'from' => ['id' => $id],
        ]]));
    }

    expect(BusinessChat::query()->count())->toBe(30)
        ->and(BusinessChat::query()->where('telegram_chat_id', 9990040031)->exists())->toBeFalse()
        ->and(BusinessChat::query()->distinct()->count('business_connection_id'))->toBe(2);
});

// 18. Edit after delete ----------------------------------------------------------------------

it('does not restore a deleted message when an edit arrives after the delete (VO-18)', function () {
    $connection = officeIngestReady();
    $chat = BusinessChat::factory()->client()->create(['business_connection_id' => $connection->id, 'telegram_chat_id' => OFFICE_CLIENT_ID]);
    app(HandleTelegramUpdate::class)->handle(officeFixture('message_client', 1));
    app(HandleTelegramUpdate::class)->handle(officeFixture('messages_deleted', 2));
    app(HandleTelegramUpdate::class)->handle(officeFixture('message_edited', 3));

    $message = $chat->messages()->sole();

    expect($message->text)->toBeNull()
        ->and($message->media)->toBeNull()
        ->and($message->deleted_at)->not->toBeNull()
        ->and($message->edited_at)->toBeNull()
        ->and((string) DB::table('business_messages')->value('text'))->toBe('');
});

// 19. Allowed-updates button -----------------------------------------------------------------

it('refuses updateAllowedUpdates to content and sales managers and sends nothing (VO-15)', function (Role $role) {
    telegramBotConfigured();
    Http::preventStrayRequests();
    Http::fake();

    Livewire::actingAs(staffUser($role))->test(Bot::class)->assertForbidden();

    expect(fn () => app(ConfigureTelegramWebhook::class)->updateAllowedUpdates(staffUser($role), '10.0.0.1'))
        ->toThrow(AuthorizationException::class);

    Http::assertNothingSent();
    expect(SettingsAudit::query()->where('area', SettingsArea::TelegramBot->value)->count())->toBe(0);
})->with([Role::ContentManager, Role::SalesManager, Role::ServiceEngineer]);

it('audits updateAllowedUpdates for the Admin and keeps pending updates (VO-15)', function () {
    telegramBotConfigured();
    Http::preventStrayRequests();
    Http::fake([
        'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => ConfigureTelegramWebhook::expectedUrl(), 'pending_update_count' => 3, 'allowed_updates' => ['message']]]),
        'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 1, 'username' => 'fake_bot']]),
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
    ]);
    $admin = staffUser();
    confirmAdminPassword();

    Livewire::actingAs($admin)->test(Bot::class)->call('updateAllowedUpdates');

    $audit = SettingsAudit::query()->where('area', SettingsArea::TelegramBot->value)->sole();

    expect($audit->user_id)->toBe($admin->id)
        ->and($audit->key)->toBe(ConfigureTelegramWebhook::AUDIT_KEY)
        ->and($audit->action)->toBe(AuditAction::WebhookSet)
        ->and(collect(telegramApiCalls())->firstWhere('method', 'setWebhook')['data']['drop_pending_updates'])->toBeFalse();
});

// 20. Webhook budget -------------------------------------------------------------------------

/*
| Expected cost of one business update on the shared webhook (VO-2, VO-3): no outbound HTTP call
| (only an unknown connection dispatches ONE queued getBusinessConnection fetch per id and hour),
| nothing queued for a stored message, and a constant number of queries that does not grow with the
| size of the chat history. One webhook hit also uses one slot of the 600/min webhook limiter.
*/
it('costs a business update no outbound call and a bounded (measured 7 to 9), history-independent number of queries (VO-2, VO-3)', function () {
    telegramBotConfigured();
    RateLimiter::clear('telegram-webhook');
    Http::preventStrayRequests();
    Http::fake();
    Queue::fake();
    $connection = officeIngestReady();
    $chat = BusinessChat::factory()->client()->create(['business_connection_id' => $connection->id, 'telegram_chat_id' => OFFICE_CLIENT_ID]);

    $queries = function (array $update): int {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        postTelegramUpdate($update)->assertOk();

        return $count;
    };

    $small = $queries(officeFixture('message_client', 1, ['business_message' => ['message_id' => 500]]));

    foreach (range(1, 120) as $index) {
        $chat->messages()->create(['telegram_message_id' => 1000 + $index, 'author' => BusinessAuthor::Client, 'kind' => 'text', 'text' => 'Xabar '.$index, 'sent_at' => now()->subMinutes(200 - $index)]);
    }

    $large = $queries(officeFixture('message_client', 2, ['business_message' => ['message_id' => 501]]));
    $edit = $queries(officeFixture('message_edited', 3, ['edited_business_message' => ['message_id' => 1010]]));
    $deleted = $queries(officeFixture('messages_deleted', 4, ['deleted_business_messages' => ['message_ids' => [1011, 1012]]]));

    Http::assertNothingSent();
    Queue::assertNothingPushed();

    expect($large)->toBe($small)
        ->and($small)->toBeLessThanOrEqual(12)
        ->and($edit)->toBeLessThanOrEqual(12)
        ->and($deleted)->toBeLessThanOrEqual(12);
});

it('costs an unknown connection only one queued fetch for many updates, and no outbound call from the webhook (VO-3)', function () {
    telegramBotConfigured();
    RateLimiter::clear('telegram-webhook');
    Http::preventStrayRequests();
    Http::fake();
    Bus::fake([FetchBusinessConnection::class]);
    officeSettings(['ingest_enabled' => true, 'owner_telegram_ids' => [OFFICE_OWNER_ID]]);

    foreach (range(1, 20) as $index) {
        postTelegramUpdate(officeFixture('message_client', $index, ['business_message' => ['message_id' => $index]]))->assertOk();
    }

    Http::assertNothingSent();
    Bus::assertDispatchedTimes(FetchBusinessConnection::class, 1);
    expect(BusinessChat::query()->count())->toBe(0);
});
