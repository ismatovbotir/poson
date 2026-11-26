<?php

use App\Actions\Chat\RecordAiUsage;
use App\Actions\Office\DispatchDueAnalyses;
use App\Actions\Office\ForgetGroupMember;
use App\Actions\Office\RunGroupAnalysis;
use App\Enums\AgentRole;
use App\Enums\AiUsageChannel;
use App\Enums\AnalysisStatus;
use App\Enums\LeadSource;
use App\Enums\Role;
use App\Jobs\AnalyseBusinessChat;
use App\Jobs\AnalyseTelegramGroup;
use App\Livewire\Admin\Office\Groups\Show;
use App\Models\BusinessChat;
use App\Models\ChatAiUsageDaily;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupAnalysis;
use App\Models\TelegramGroupMember;
use App\Models\TelegramGroupMessage;
use App\Models\TelegramGroupThread;
use App\Services\Ai\AiRequest;
use App\Support\Office\OfficeLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/../../../Helpers/Office.php';
require_once __DIR__.'/../../../Helpers/Groups.php';

/*
| Plan 44 T6: analysis of tracked Telegram groups (OG-4, OG-5, OG-6, OG-11, M2).
| The capturing fake ChatModel only; no network call.
*/

beforeEach(function () {
    Http::preventStrayRequests();
});

it('analyses a due group: router then one sales agent, a thread per client stored encrypted, a lead for the assignee, usage counted', function () {
    $setup = groupForAnalysis();
    $fake = groupStandardModel();

    $this->artisan('office:analyse-due')->assertSuccessful();

    $analysis = TelegramGroupAnalysis::query()->sole();
    $thread = TelegramGroupThread::query()->sole();
    $lead = Lead::query()->sole();

    expect($fake->requests)->toHaveCount(2)
        ->and($fake->requests[0]->model)->toBe('claude-haiku-5-5')
        ->and($fake->requests[1]->model)->toBe('claude-sonnet-5-5')
        ->and($fake->requests[0]->outputSchema['properties'])->toHaveKey('threads')
        ->and($fake->requests[0]->system[0]['text'])->toContain('Mandatory rules')
        ->and($fake->requests[1]->messages[0]['content'])->toContain('<thread>client A</thread>')
        ->and($analysis->status)->toBe(AnalysisStatus::Done)
        ->and($analysis->role)->toBe(AgentRole::Sales)
        ->and($analysis->member_ids)->toEqualCanonicalizing([$setup['client']->id, $setup['staff']->id, $setup['unknown']->id])
        ->and($analysis->input_hash)->toHaveLength(64)
        ->and($thread->client_member_id)->toBe($setup['client']->id)
        ->and($thread->member_ids)->toBe([$setup['client']->id])
        ->and($thread->topic)->toBe('Label printer for a shop')
        ->and($thread->draft)->toContain('yorliq')
        ->and($thread->draft_blocked)->toBeFalse()
        ->and($thread->lead_id)->toBe($lead->id)
        ->and($analysis->lead_action)->toBe(TelegramGroupAnalysis::LEAD_CREATED)
        ->and($lead->source)->toBe(LeadSource::TelegramBusiness)
        ->and($lead->manager_id)->toBe($setup['sales']->id)
        ->and($lead->phone)->toBe('+998900000077')
        ->and($lead->message)->toBe("Label printer for a shop\nTelegram group #".$setup['group']->id)
        ->and($setup['group']->fresh()->analysis_due_at)->toBeNull()
        ->and($setup['group']->fresh()->analyses_today)->toBe(1)
        ->and(ChatAiUsageDaily::query()->where('channel', AiUsageChannel::OfficeRoute->value)->value('requests'))->toBe(1)
        ->and(ChatAiUsageDaily::query()->where('channel', AiUsageChannel::OfficeSales->value)->value('requests'))->toBe(1);
});

it('sends the transcript as structured {label, time, text} entries with labels that are not names', function () {
    groupForAnalysis();
    $fake = groupStandardModel();

    $this->artisan('office:analyse-due');

    $entries = groupEntries($fake->requests[0]);

    expect($entries)->toHaveCount(3)
        ->and(array_keys($entries[0]))->toBe(['label', 'time', 'text'])
        ->and(array_column($entries, 'label'))->toEqualCanonicalizing(['client A', 'staff 1', 'unknown A']);
});

it('updates the open lead on a later run instead of creating a second one', function () {
    $setup = groupForAnalysis();
    groupStandardModel();
    $this->artisan('office:analyse-due');

    groupMessage($setup['client'], 'Yana bitta skaner ham kerak.', 4);
    TelegramGroup::query()->whereKey($setup['group']->id)->update(['analysis_due_at' => now()->subSecond()]);
    groupStandardModel();
    $this->artisan('office:analyse-due');

    expect(Lead::query()->count())->toBe(1)
        ->and(TelegramGroupThread::query()->count())->toBe(1)
        ->and(TelegramGroupAnalysis::query()->latest('id')->first()->lead_action)->toBe(TelegramGroupAnalysis::LEAD_UPDATED);
});

it('skips without a call when nothing changed since the last done run', function () {
    $setup = groupForAnalysis();
    groupStandardModel();
    $this->artisan('office:analyse-due');

    TelegramGroup::query()->whereKey($setup['group']->id)->update(['analysis_due_at' => now()->subSecond()]);
    $fake = groupStandardModel();
    $this->artisan('office:analyse-due');

    expect($fake->requests)->toBe([])
        ->and(TelegramGroupAnalysis::query()->latest('id')->first()->error)->toBe('unchanged');
});

it('makes no call when a gate is off (OG-4)', function (Closure $gate) {
    $setup = groupForAnalysis();
    $gate($setup['group']);
    $fake = groupStandardModel();

    $this->artisan('office:analyse-due');
    app(DispatchDueAnalyses::class)->handle();

    expect($fake->requests)->toBe([])
        ->and(Lead::query()->count())->toBe(0)
        ->and(TelegramGroupThread::query()->count())->toBe(0);
})->with([
    'groups off' => [fn () => officeSettings(['groups_enabled' => false])],
    'ingest off' => [fn () => officeSettings(['ingest_enabled' => false])],
    'ai off' => [fn () => officeSettings(['ai_enabled' => false])],
    'untracked' => [fn (TelegramGroup $group) => $group->forceFill(['status' => 'untracked'])->save()],
    'unassigned' => [fn (TelegramGroup $group) => $group->forceFill(['assignee_id' => null])->save()],
    'bot left' => [fn (TelegramGroup $group) => $group->forceFill(['bot_status' => 'left'])->save()],
    'bot kicked' => [fn (TelegramGroup $group) => $group->forceFill(['bot_status' => 'kicked'])->save()],
]);

it('makes no call for a queued run whose gate closed before it ran', function () {
    $setup = groupForAnalysis();
    $analysis = $setup['group']->analyses()->create(['status' => AnalysisStatus::Queued, 'trigger' => 'manual']);
    officeSettings(['groups_enabled' => false]);
    $fake = groupStandardModel();

    app(RunGroupAnalysis::class)->handle($analysis->id, $fake);

    expect($fake->requests)->toBe([])->and($analysis->fresh()->error)->toBe('gated');
});

it('counts group runs toward the per-chat daily cap: over it, no call and due tomorrow', function () {
    $setup = groupForAnalysis();
    officeSettings(['per_chat_daily' => 1]);
    $setup['group']->forceFill(['analyses_today' => 1, 'analyses_day' => now('Asia/Tashkent')->toDateString()])->save();
    $fake = groupStandardModel();

    $this->artisan('office:analyse-due');

    expect($fake->requests)->toBe([])
        ->and(TelegramGroupAnalysis::query()->count())->toBe(0)
        ->and($setup['group']->fresh()->analysis_due_at->greaterThan(now()))->toBeTrue();
});

it('writes one capped row and makes no call when the router channel is over its cap', function () {
    $setup = groupForAnalysis();
    ChatAiUsageDaily::query()->insert(['day' => RecordAiUsage::day(), 'channel' => AiUsageChannel::OfficeRoute->value, 'requests' => 400]);
    $fake = groupStandardModel();

    foreach (range(1, 3) as $minute) {
        $this->travel(1)->minutes();
        $this->artisan('office:analyse-due');
    }

    expect($fake->requests)->toBe([])
        ->and($setup['group']->analyses()->where('status', AnalysisStatus::Capped->value)->count())->toBe(1);
});

it('stops as capped, saving nothing, when an agent channel is over its cap mid-run', function () {
    groupForAnalysis();
    ChatAiUsageDaily::query()->insert(['day' => RecordAiUsage::day(), 'channel' => AiUsageChannel::OfficeSales->value, 'requests' => 200]);
    $fake = groupStandardModel();

    $this->artisan('office:analyse-due');

    expect($fake->requests)->toHaveCount(1)
        ->and(TelegramGroupAnalysis::query()->sole()->status)->toBe(AnalysisStatus::Capped)
        ->and(TelegramGroupThread::query()->count())->toBe(0);
});

it('makes at most three agent calls per run, even with more client threads', function () {
    $setup = groupForAnalysis();

    foreach (range(1, 4) as $index) {
        $member = TelegramGroupMember::factory()->client()->create(['telegram_group_id' => $setup['group']->id, 'first_name' => 'Mijozxon'.$index, 'role_set_by_id' => $setup['admin']->id]);
        groupMessage($member, 'Skaner kerak raqam '.$index, 10 + $index);
    }

    $fake = groupModel(function (AiRequest $request, int $call): string {
        if ($call > 1) {
            return officeAgentAnswer();
        }

        $clients = array_values(array_filter(array_unique(array_column(groupEntries($request), 'label')), fn (string $label): bool => str_starts_with($label, 'client ')));

        return groupRouterAnswer([
            ['client_labels' => array_slice($clients, 0, 2)],
            ['client_labels' => array_slice($clients, 2, 2), 'role' => 'service'],
            ['client_labels' => array_slice($clients, 4)],
            ['client_labels' => $clients],
        ]);
    });

    $this->artisan('office:analyse-due');

    expect(count($fake->requests))->toBe(4)
        ->and(collect($fake->requests)->where('model', 'claude-sonnet-5-5')->count())->toBe(3)
        ->and(TelegramGroupThread::query()->count())->toBe(3);
});

it('keeps support and other threads router-only: no agent call, no lead', function () {
    groupForAnalysis();
    $fake = groupStandardModel(thread: ['role' => 'support']);

    $this->artisan('office:analyse-due');

    expect($fake->requests)->toHaveCount(1)
        ->and(TelegramGroupThread::query()->sole()->draft)->toBeNull()
        ->and(TelegramGroupThread::query()->sole()->summary)->toBe('The client needs a label printer.')
        ->and(Lead::query()->count())->toBe(0);
});

it('makes a customer-linked group one thread (client_member_id null) with a single agent call', function () {
    $customer = Customer::factory()->create(['name' => 'OOO Fakeshop', 'phone' => '+998900000055']);
    $setup = groupForAnalysis(['customer_id' => $customer->id]);
    $second = TelegramGroupMember::factory()->client()->create(['telegram_group_id' => $setup['group']->id, 'first_name' => 'Ikkinchixon', 'role_set_by_id' => $setup['admin']->id]);
    groupMessage($second, 'Bizga ham kerak.', 5);

    $fake = groupModel(fn (AiRequest $request, int $call): string => $call === 1
        ? groupRouterAnswer([['client_labels' => [groupLabel($request, 'yorliq printer')], 'role' => 'support', 'confidence' => 99], ['client_labels' => [groupLabel($request, 'ham kerak')], 'confidence' => 60]])
        : officeAgentAnswer());

    $this->artisan('office:analyse-due');

    $thread = TelegramGroupThread::query()->sole();

    expect(collect($fake->requests)->where('model', 'claude-sonnet-5-5')->count())->toBe(1)
        ->and($fake->requests[1]->messages[0]['content'])->toContain('<thread>the whole group; clients: ')
        ->and($thread->client_member_id)->toBeNull()
        ->and($thread->customer_id)->toBe($customer->id)
        ->and($thread->role)->toBe(AgentRole::Sales)
        ->and($thread->member_ids)->toEqualCanonicalizing([$setup['client']->id, $second->id]);
});

it('discards the result when Forget deleted a used message during the run (M2)', function () {
    $setup = groupForAnalysis();
    $fake = groupModel(function (AiRequest $request, int $call) use ($setup): string {
        if ($call === 2) {
            // What a Forget does: the member's messages go (here without the lock, to prove the re-check).
            TelegramGroupMessage::query()->where('member_id', $setup['client']->id)->delete();
        }

        return $call === 1 ? groupRouterAnswer([['client_labels' => [groupLabel($request, 'yorliq printer')]]]) : officeAgentAnswer();
    });

    $this->artisan('office:analyse-due');

    $analysis = TelegramGroupAnalysis::query()->sole();

    expect($fake->requests)->toHaveCount(2)
        ->and($analysis->status)->toBe(AnalysisStatus::Skipped)
        ->and($analysis->error)->toBe('forgotten')
        ->and($analysis->summary)->toBeNull()
        ->and(TelegramGroupThread::query()->count())->toBe(0)
        ->and(Lead::query()->count())->toBe(0);
});

it('refuses Forget while an analysis of the group holds the lock (M2)', function () {
    config(['office.lock_wait_seconds' => 0]);
    $setup = groupForAnalysis();
    confirmAdminPassword();
    $lock = OfficeLock::forGroup($setup['group']->id);
    $lock->get();

    try {
        expect(fn () => app(ForgetGroupMember::class)->handle($setup['admin'], $setup['group'], $setup['client']->id))->toThrow(ValidationException::class);
    } finally {
        $lock->release();
    }

    expect(TelegramGroupMessage::query()->where('member_id', $setup['client']->id)->count())->toBe(1);
});

it('skips a run while Forget holds the group lock', function () {
    $setup = groupForAnalysis();
    $analysis = $setup['group']->analyses()->create(['status' => AnalysisStatus::Queued, 'trigger' => 'manual']);
    $lock = OfficeLock::forGroup($setup['group']->id);
    $lock->get();
    $fake = groupStandardModel();

    try {
        app(RunGroupAnalysis::class)->handle($analysis->id, $fake);
    } finally {
        $lock->release();
    }

    expect($fake->requests)->toBe([])->and($analysis->fresh()->error)->toBe('locked');
});

it('nulls on Forget only the analyses and threads that included the member', function () {
    $setup = groupForAnalysis();
    groupStandardModel();
    $this->artisan('office:analyse-due');
    $other = TelegramGroupMember::factory()->client()->create(['telegram_group_id' => $setup['group']->id]);
    $untouched = TelegramGroupThread::factory()->create(['telegram_group_id' => $setup['group']->id, 'client_member_id' => $other->id, 'member_ids' => [$other->id], 'summary' => 'Other summary']);
    $untouchedRun = TelegramGroupAnalysis::factory()->done('Other run')->create(['telegram_group_id' => $setup['group']->id, 'member_ids' => [$other->id]]);
    confirmAdminPassword();

    $counts = app(ForgetGroupMember::class)->handle($setup['admin'], $setup['group'], $setup['unknown']->id);

    expect($counts)->toBe(['messages' => 1, 'analyses' => 1, 'threads' => 0])
        ->and(TelegramGroupThread::query()->where('client_member_id', $setup['client']->id)->sole()->summary)->not->toBeNull()
        ->and($untouched->fresh()->summary)->toBe('Other summary')
        ->and($untouchedRun->fresh()->summary)->toBe('Other run')
        ->and(TelegramGroupAnalysis::query()->whereKeyNot($untouchedRun->id)->sole()->summary)->toBeNull();
});

it('stores summary, next step and draft encrypted at rest', function () {
    groupForAnalysis();
    groupStandardModel();

    $this->artisan('office:analyse-due');

    $thread = DB::table('telegram_group_threads')->first();
    $analysis = DB::table('telegram_group_analyses')->first();

    foreach ([$thread->summary, $thread->next_step, $thread->draft, $analysis->summary, $analysis->next_step, $analysis->draft_reply] as $raw) {
        expect($raw)->not->toBeNull()
            ->and($raw)->not->toContain('label')
            ->and($raw)->not->toContain('yorliq');
    }

    expect(TelegramGroupThread::query()->sole()->draft)->toContain('yorliq');
});

it('blocks a draft with a discount phrase, and drops such a summary', function () {
    groupForAnalysis();
    groupStandardModel(agent: ['draft_reply' => 'Sizga 10% chegirma beramiz, eng arzon narx!', 'summary' => 'Offer the client a 15% discount.']);

    $this->artisan('office:analyse-due');

    $thread = TelegramGroupThread::query()->sole();

    expect($thread->draft)->toBeNull()
        ->and($thread->draft_blocked)->toBeTrue()
        ->and($thread->summary)->not->toContain('discount');
});

it('queues "Analyse now" for a user with office.analyse on a visible group, then throttles it', function () {
    Queue::fake();
    $setup = groupForAnalysis(['analysis_due_at' => null]);

    Livewire::actingAs($setup['sales'])->test(Show::class, ['group' => $setup['group']])
        ->assertSeeHtml('data-action="analyse"')
        ->call('analyse')->assertHasNoErrors();

    Queue::assertPushed(AnalyseTelegramGroup::class, 1);

    TelegramGroupAnalysis::query()->update(['status' => AnalysisStatus::Done->value]);

    Livewire::actingAs($setup['sales'])->test(Show::class, ['group' => $setup['group']])
        ->call('analyse')->assertHasErrors('analyse');

    Queue::assertPushed(AnalyseTelegramGroup::class, 1);
});

it('refuses "Analyse now" while the gates are off, and shows threads with a copy-only draft and the lead link', function () {
    Queue::fake();
    $setup = groupForAnalysis();
    $lead = Lead::factory()->create(['manager_id' => $setup['sales']->id]);
    TelegramGroupThread::factory()->create(['telegram_group_id' => $setup['group']->id, 'client_member_id' => $setup['client']->id, 'draft' => 'Assalomu alaykum, tanlab beramiz.', 'lead_id' => $lead->id]);
    officeSettings(['ai_enabled' => false]);

    Livewire::actingAs($setup['admin'])->test(Show::class, ['group' => $setup['group']])
        ->assertSeeHtml('data-not-analysable')
        ->assertSeeHtml('data-copy="Assalomu alaykum, tanlab beramiz."')
        ->assertSee($lead->reference())
        ->assertSee(__('office.show.draft_not_sent'))
        ->call('analyse')->assertHasErrors('analyse');

    Queue::assertNothingPushed();
});

it('answers 403 to "Analyse now" without office.analyse or without visibility', function () {
    $setup = groupForAnalysis();
    $viewer = staffUser(Role::ContentManager);
    $viewer->givePermissionTo('office.chats');
    $setup['group']->forceFill(['assignee_id' => $viewer->id])->save();

    Livewire::actingAs($viewer)->test(Show::class, ['group' => $setup['group']])
        ->assertDontSeeHtml('data-action="analyse"')
        ->call('analyse')->assertForbidden();

    $stranger = staffUser(Role::SalesManager);
    $this->actingAs($stranger)->get(route('admin.office.groups.show', $setup['group']))->assertForbidden();

    expect(TelegramGroupAnalysis::query()->count())->toBe(0);
});

it('shows a thread lead of another manager to the sales assignee without its link (M1)', function () {
    $setup = groupForAnalysis();
    $lead = Lead::factory()->create(['manager_id' => staffUser(Role::SalesManager)->id]);
    TelegramGroupThread::factory()->create(['telegram_group_id' => $setup['group']->id, 'client_member_id' => $setup['client']->id, 'lead_id' => $lead->id]);

    $this->actingAs($setup['sales'])->get(route('admin.office.groups.show', $setup['group']))
        ->assertOk()
        ->assertDontSee('data-thread-lead', false)
        ->assertDontSee($lead->reference());
});

it('keeps one slot for due groups while chats are due too (no starvation)', function () {
    Queue::fake();
    $connection = officeIngestReady(ai: true);
    groupForAnalysis();

    foreach (range(1, 5) as $index) {
        BusinessChat::factory()->client()->create(['business_connection_id' => $connection->id, 'analysis_due_at' => now()->subMinutes(10 + $index)]);
    }

    app(DispatchDueAnalyses::class)->handle();

    Queue::assertPushed(AnalyseTelegramGroup::class, 1);
    Queue::assertPushed(AnalyseBusinessChat::class, 2);
});

it('saves nothing when the run was closed (stale) while it ran (L3)', function () {
    $setup = groupForAnalysis();
    groupModel(function (AiRequest $request, int $call): string {
        if ($call === 2) {
            TelegramGroupAnalysis::query()->update(['status' => AnalysisStatus::Failed->value, 'error' => 'stale']);
        }

        return $call === 1 ? groupRouterAnswer([['client_labels' => [groupLabel($request, 'yorliq printer')]]]) : officeAgentAnswer();
    });

    $this->artisan('office:analyse-due');

    expect(TelegramGroupAnalysis::query()->sole()->error)->toBe('stale')
        ->and(TelegramGroupThread::query()->count())->toBe(0)
        ->and(Lead::query()->count())->toBe(0);
});

it('retries a locked run in 2 minutes without using the daily cap, and a failed run in 15 minutes within it (L4)', function () {
    $setup = groupForAnalysis(['analysis_due_at' => null]);
    $setup['group']->forceFill(['analyses_today' => 1, 'analyses_day' => now('Asia/Tashkent')->toDateString()])->save();
    $locked = $setup['group']->analyses()->create(['status' => AnalysisStatus::Queued, 'trigger' => 'manual']);
    $lock = OfficeLock::forGroup($setup['group']->id);
    $lock->get();
    app(RunGroupAnalysis::class)->handle($locked->id, groupStandardModel());
    $lock->release();

    $group = $setup['group']->fresh();
    expect($group->analyses_today)->toBe(0)
        ->and($group->analysis_due_at->between(now()->addMinute(), now()->addMinutes(3)))->toBeTrue();

    $group->forceFill(['analysis_due_at' => null])->save();
    $failed = $setup['group']->analyses()->create(['status' => AnalysisStatus::Queued, 'trigger' => 'manual']);
    app(RunGroupAnalysis::class)->handle($failed->id, groupModel(fn (): string => 'not json'));

    expect($failed->fresh()->status)->toBe(AnalysisStatus::Failed)
        ->and($setup['group']->fresh()->analysis_due_at->between(now()->addMinutes(14), now()->addMinutes(16)))->toBeTrue();

    officeSettings(['per_chat_daily' => 1]);
    TelegramGroup::query()->whereKey($setup['group']->id)->update(['analysis_due_at' => null, 'analyses_today' => 1]);
    $again = $setup['group']->analyses()->create(['status' => AnalysisStatus::Queued, 'trigger' => 'manual']);
    app(RunGroupAnalysis::class)->handle($again->id, groupModel(fn (): string => 'not json'));

    expect($setup['group']->fresh()->analysis_due_at)->toBeNull();
});

it('deletes threads the latest run no longer found, and clears the draft of such a thread with a lead', function () {
    $setup = groupForAnalysis();
    $gone = TelegramGroupThread::factory()->create(['telegram_group_id' => $setup['group']->id, 'client_member_id' => $setup['unknown']->id]);
    $withLead = TelegramGroupThread::factory()->create(['telegram_group_id' => $setup['group']->id, 'client_member_id' => $setup['staff']->id, 'draft' => 'Old draft', 'lead_id' => Lead::factory()->create()->id]);
    groupStandardModel();

    $this->artisan('office:analyse-due');

    expect($gone->fresh())->toBeNull()
        ->and($withLead->fresh()->draft)->toBeNull()
        ->and($withLead->fresh()->lead_id)->not->toBeNull()
        ->and(TelegramGroupThread::query()->where('client_member_id', $setup['client']->id)->exists())->toBeTrue();
});

it('nulls topic and lead hints of the affected analyses on Forget (L1)', function () {
    $setup = groupForAnalysis();
    groupStandardModel();
    $this->artisan('office:analyse-due');
    confirmAdminPassword();

    app(ForgetGroupMember::class)->handle($setup['admin'], $setup['group'], $setup['client']->id);

    $analysis = TelegramGroupAnalysis::query()->sole();

    expect($analysis->topic)->toBeNull()->and($analysis->lead_fields)->toBeNull()->and($analysis->summary)->toBeNull();
});

it('masks each name and username token and a 2-letter name as a whole word (L2)', function () {
    $setup = groupForAnalysis(clientText: 'Valiyev aka va Bo bilan gaplashdim, Zarnigorbonu emas. fake ham.');
    $setup['client']->forceFill(['first_name' => 'Zarnigor Valiyev'])->save();
    $setup['unknown']->forceFill(['first_name' => 'Bo'])->save();
    $fake = groupStandardModel(needle: 'aka');

    $this->artisan('office:analyse-due');

    $text = $fake->requests[0]->messages[0]['content'];

    expect($text)->not->toContain('Valiyev')
        ->and($text)->not->toMatch('/\bBo\b/')
        ->and($text)->not->toMatch('/\bfake\b/')
        ->and($text)->toContain('Zarnigorbonu');
});
