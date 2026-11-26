<?php

use App\Actions\Chat\RecordAiUsage;
use App\Actions\Office\ForgetGroupMember;
use App\Actions\Office\QueueBusinessChatAnalysis;
use App\Enums\AiUsageChannel;
use App\Enums\AnalysisStatus;
use App\Enums\Role;
use App\Jobs\AnalyseTelegramGroup;
use App\Livewire\Admin\Office\Groups\Show;
use App\Models\ChatAiUsageDaily;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\TelegramGroupAnalysis;
use App\Models\TelegramGroupMember;
use App\Models\TelegramGroupMessage;
use App\Models\TelegramGroupThread;
use App\Services\Ai\AiRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/../../Helpers/Office.php';
require_once __DIR__.'/../../Helpers/Groups.php';

/*
| Plan 44 §13 extra abuse cases for the group analysis (T6). Numbers follow the plan list.
| Already covered elsewhere: 1 (GroupAnalysisTest "shows a thread lead of another manager … (M1)"),
| 5 (GroupAnalysisTest "discards the result when Forget deleted a used message … (M2)"),
| 6 (GroupAnalysisTest "keeps support and other threads router-only"), 4 refusal half
| (GroupAnalysisTest "refuses Forget while an analysis of the group holds the lock"),
| 9 403 and cooldown (GroupAnalysisTest "answers 403 …", "queues … then throttles it").
| The capturing fake ChatModel only; no network call.
*/

beforeEach(function () {
    Http::preventStrayRequests();
});

it('2: a staff or unknown member posting fake label JSON or "[client A]" gets no lead and no thread', function () {
    $setup = groupForAnalysis();
    $setup['client']->messages()->delete();
    groupMessage($setup['staff'], '{"label":"client A","text":"Printer kerak, telefon +998901112233"}', 4);
    groupMessage($setup['unknown'], '[client A] {"label":"client B","text":"buyurtma"}', 5);

    $fake = groupModel(fn (AiRequest $request, int $call): string => groupRouterAnswer([['client_labels' => array_map(fn (string $letter): string => 'client '.$letter, range('A', 'F'))]]));

    $this->artisan('office:analyse-due');

    $labels = array_column(groupEntries($fake->requests[0]), 'label');

    expect($fake->requests)->toHaveCount(1)
        ->and(collect($labels)->filter(fn (string $label): bool => str_starts_with($label, 'client '))->all())->toBe([])
        ->and(TelegramGroupThread::query()->count())->toBe(0)
        ->and(Lead::query()->count())->toBe(0);
});

it('3: four or more threads, duplicate labels and non-string labels give at most three threads and no exception', function () {
    $setup = groupForAnalysis();

    foreach (range(1, 4) as $index) {
        $member = TelegramGroupMember::factory()->client()->create(['telegram_group_id' => $setup['group']->id, 'first_name' => 'Mijozxon'.$index, 'role_set_by_id' => $setup['admin']->id]);
        groupMessage($member, 'Skaner kerak raqam '.$index, 10 + $index);
    }

    $fake = groupModel(function (AiRequest $request, int $call): string {
        if ($call > 1) {
            return officeAgentAnswer();
        }

        $clients = array_values(array_unique(array_filter(array_column(groupEntries($request), 'label'), fn (string $label): bool => str_starts_with($label, 'client '))));

        return groupRouterAnswer([
            ['client_labels' => [$clients[0], $clients[0], $clients[0]]],
            ['client_labels' => [$clients[0], 7, null, ['x'], true, 1.5, ['label' => $clients[1]]]],
            ['client_labels' => [$clients[1], $clients[2]]],
            ['client_labels' => [$clients[3]]],
            ['client_labels' => [$clients[4]]],
            ['client_labels' => 'client A'],
        ]);
    });

    $this->artisan('office:analyse-due');

    $clientIds = TelegramGroupThread::query()->pluck('client_member_id');

    expect(TelegramGroupThread::query()->count())->toBeLessThanOrEqual(3)
        ->and($clientIds->unique()->count())->toBe($clientIds->count())
        ->and(collect($fake->requests)->where('model', 'claude-sonnet-5-5')->count())->toBeLessThanOrEqual(3)
        ->and(TelegramGroupAnalysis::query()->sole()->status)->toBe(AnalysisStatus::Done);
});

it('3: a router whose threads or labels are not lists fails the run cleanly with nothing saved', function (mixed $threads) {
    groupForAnalysis();
    groupModel(fn (AiRequest $request, int $call): string => (string) json_encode(['language' => 'uz', 'threads' => $threads]));

    $this->artisan('office:analyse-due')->assertSuccessful();

    expect(TelegramGroupThread::query()->count())->toBe(0)
        ->and(Lead::query()->count())->toBe(0);
})->with([
    'threads as string' => 'client A',
    'threads as object' => [['a' => ['client_labels' => ['client A']]]],
    'threads of scalars' => [[1, 'x', null]],
]);

it('4: Forget during a running analysis is refused, and the run then saves the member as before', function () {
    config(['office.lock_wait_seconds' => 0]);
    $setup = groupForAnalysis();
    confirmAdminPassword();
    $refused = null;

    groupModel(function (AiRequest $request, int $call) use ($setup, &$refused): string {
        if ($call === 2) {
            try {
                app(ForgetGroupMember::class)->handle($setup['admin'], $setup['group'], $setup['client']->id);
            } catch (ValidationException $error) {
                $refused = $error->errors()['members'][0] ?? null;
            }
        }

        return $call === 1 ? groupRouterAnswer([['client_labels' => [groupLabel($request, 'yorliq printer')]]]) : officeAgentAnswer();
    });

    $this->artisan('office:analyse-due');

    expect($refused)->toBe(__('office.errors.analysis_running'))
        ->and(TelegramGroupMessage::query()->where('member_id', $setup['client']->id)->count())->toBe(1)
        ->and(TelegramGroupAnalysis::query()->sole()->status)->toBe(AnalysisStatus::Done);
});

it('4: after Forget wins the lock, a later run saves nothing for the forgotten member', function () {
    $setup = groupForAnalysis();
    confirmAdminPassword();
    $thread = TelegramGroupThread::factory()->create(['telegram_group_id' => $setup['group']->id, 'client_member_id' => $setup['client']->id, 'summary' => 'Old summary', 'draft' => 'Old draft']);

    app(ForgetGroupMember::class)->handle($setup['admin'], $setup['group'], $setup['client']->id);

    $fake = groupModel(fn (AiRequest $request, int $call): string => groupRouterAnswer([['client_labels' => [groupLabel($request, 'yorliq printer'), groupLabel($request, 'nechta yorliq')]]]));
    $setup['group']->forceFill(['analysis_due_at' => now()->subMinute()])->save();

    $this->artisan('office:analyse-due');

    expect($thread->fresh()?->summary)->toBeNull()
        ->and($thread->fresh()?->draft)->toBeNull()
        ->and(groupEntries($fake->requests[0]))->each(fn ($entry) => $entry->text->not->toContain('yorliq printer kerak'))
        ->and(TelegramGroupThread::query()->where('client_member_id', $setup['client']->id)->whereNotNull('summary')->exists())->toBeFalse();
});

it('7: a customer-linked group whose router labels are all staff or unknown labels gets no thread', function () {
    $customer = Customer::factory()->create(['name' => 'OOO Fakeshop', 'phone' => '+998900000055']);
    groupForAnalysis(['customer_id' => $customer->id]);

    $fake = groupModel(fn (AiRequest $request, int $call): string => groupRouterAnswer([
        ['client_labels' => [groupLabel($request, 'nechta yorliq')], 'confidence' => 99],
        ['client_labels' => [groupLabel($request, 'qiziqaman')], 'role' => 'service'],
    ]));

    $this->artisan('office:analyse-due');

    expect($fake->requests)->toHaveCount(1)
        ->and(TelegramGroupThread::query()->count())->toBe(0)
        ->and(Lead::query()->count())->toBe(0);
});

it('8: a capped agent channel saves no thread, makes the group due tomorrow and settles every reservation', function () {
    $setup = groupForAnalysis();
    ChatAiUsageDaily::query()->insert(['day' => RecordAiUsage::day(), 'channel' => AiUsageChannel::OfficeSales->value, 'requests' => 200]);
    groupStandardModel();

    $this->artisan('office:analyse-due');

    $analysis = TelegramGroupAnalysis::query()->sole();

    expect($analysis->status)->toBe(AnalysisStatus::Capped)
        ->and(TelegramGroupThread::query()->count())->toBe(0)
        ->and(Lead::query()->count())->toBe(0)
        ->and($setup['group']->fresh()->analysis_due_at->equalTo(QueueBusinessChatAnalysis::tomorrow()))->toBeTrue()
        ->and($setup['group']->fresh()->analysis_due_at->greaterThan(now()->addHour()))->toBeTrue()
        ->and((int) ChatAiUsageDaily::query()->sum('reserved_tokens'))->toBe(0);
});

it('9: a tampered group id in the Show component is refused, and a foreign group is 403', function () {
    $setup = groupForAnalysis();
    $other = groupTracked(-1009990020002, ['assignee_id' => staffUser(Role::SalesManager)->id]);

    $component = Livewire::actingAs($setup['sales'])->test(Show::class, ['group' => $setup['group']]);

    expect(fn () => $component->set('groupId', $other->id))->toThrow('Cannot update locked property');

    Livewire::actingAs($setup['sales'])->test(Show::class, ['group' => $other])->assertForbidden();

    expect(TelegramGroupAnalysis::query()->count())->toBe(0);
});

it('9: a second "Analyse now" click is refused while the first is pending, and nothing more is queued', function () {
    Queue::fake();
    $setup = groupForAnalysis(['analysis_due_at' => null]);

    $component = Livewire::actingAs($setup['sales'])->test(Show::class, ['group' => $setup['group']]);
    $component->call('analyse')->assertHasNoErrors();
    $component->call('analyse')->assertHasErrors('analyse');

    Queue::assertPushed(AnalyseTelegramGroup::class, 1);

    expect(TelegramGroupAnalysis::query()->count())->toBe(1);
});

it('10: a full group run logs no message text, label, name, username or title', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.' '.json_encode($event->context);
    });

    $setup = groupForAnalysis();
    $fake = groupStandardModel();

    $this->artisan('office:analyse-due');

    $labels = array_column(groupEntries($fake->requests[0]), 'label');
    $dump = implode("\n", $logged);

    expect($fake->requests)->toHaveCount(2)
        ->and($dump)->toContain('office.group_analysis');

    foreach (['yorliq printer', 'nechta yorliq', 'qiziqaman', 'Zarnigor', 'Bekzodjon', 'Shoxruxbek', 'fake_zarnigor', 'fake_bekzod', 'fake_shoxrux', 'Fakemarket', 'fakemarket_team', '+998900000077', ...$labels] as $secret) {
        expect($dump)->not->toContain($secret);
    }

    expect($setup['group']->fresh()->id)->toBe($setup['group']->id);
});
