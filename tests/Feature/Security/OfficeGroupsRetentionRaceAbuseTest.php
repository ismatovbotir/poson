<?php

use App\Actions\Office\PruneBusinessData;
use App\Actions\Office\PurgeGroupData;
use App\Actions\Office\RunGroupAnalysis;
use App\Actions\Office\TrackGroup;
use App\Actions\Office\UntrackGroup;
use App\Actions\Office\WipeBusinessData;
use App\Actions\Telegram\HandleTelegramUpdate;
use App\Enums\ActivityType;
use App\Enums\GroupStatus;
use App\Enums\LeadSource;
use App\Enums\Role;
use App\Jobs\AnalyseTelegramGroup;
use App\Jobs\WipeGroupDataChunk;
use App\Models\Lead;
use App\Models\SettingsAudit;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupAnalysis;
use App\Models\TelegramGroupMember;
use App\Models\TelegramGroupMessage;
use App\Models\TelegramGroupThread;
use App\Services\Ai\ChatModel;
use App\Support\Office\OfficeLock;
use App\Support\Office\OfficeSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeChatModel;

require_once __DIR__.'/../../Helpers/Office.php';
require_once __DIR__.'/../../Helpers/Groups.php';

/*
| Plan 44 T7 (security review): races between ingest, Untrack, Wipe, retention and the analysis job.
*/

function raceGroupWithData(): array
{
    $group = groupTracked(GROUP_SUPER_ID);
    $member = TelegramGroupMember::factory()->create(['telegram_group_id' => $group->id, 'phone' => '+998901112233']);
    TelegramGroupMessage::factory()->create(['member_id' => $member->id, 'telegram_group_id' => $group->id]);
    TelegramGroupAnalysis::factory()->done()->create(['telegram_group_id' => $group->id]);
    TelegramGroupThread::factory()->create(['telegram_group_id' => $group->id]);

    return [$group, $member];
}

function raceRows(TelegramGroup $group): int
{
    return TelegramGroupMessage::query()->where('telegram_group_id', $group->id)->count()
        + TelegramGroupMember::query()->where('telegram_group_id', $group->id)->count()
        + TelegramGroupAnalysis::query()->where('telegram_group_id', $group->id)->count()
        + TelegramGroupThread::query()->where('telegram_group_id', $group->id)->count();
}

it('case 1: an ingest that read the group as tracked, while Untrack commits, stores nothing', function () {
    Http::preventStrayRequests();
    groupsReady();
    $group = groupTracked(GROUP_SUPER_ID);
    $flipped = false;

    DB::listen(function ($query) use (&$flipped, $group): void {
        if (! $flipped && str_starts_with($query->sql, 'select * from "telegram_groups"') && str_contains($query->sql, 'telegram_chat_id')) {
            $flipped = true;
            DB::table('telegram_groups')->where('id', $group->id)->update(['status' => GroupStatus::Untracked->value]);
        }
    });

    $result = groupHandle(groupFixture('message_supergroup', 81));

    expect($flipped)->toBeTrue()
        ->and(TelegramGroupMessage::query()->count())->toBe(0)
        ->and(TelegramGroupMember::query()->count())->toBe(0)
        ->and($group->fresh()->status)->toBe(GroupStatus::Untracked);
    expect($result)->toBe(HandleTelegramUpdate::GROUP);
});

it('case 2: Untrack again after a partial purge finishes the job and is audited as a retry', function () {
    [$group] = raceGroupWithData();
    // A purge that stopped halfway: already untracked, data left.
    $group->forceFill(['status' => GroupStatus::Untracked, 'assignee_id' => null])->save();
    confirmAdminPassword();

    app(UntrackGroup::class)->handle(staffUser(Role::Admin), $group);

    expect(raceRows($group))->toBe(0)
        ->and(SettingsAudit::query()->where('key', 'office.group.'.$group->id.'.untracked')->sole()->new_text)->toStartWith('retry ');

    // An untracked group with nothing left is still refused.
    expect(fn () => app(UntrackGroup::class)->handle(staffUser(Role::Admin), $group))->toThrow(ValidationException::class);
});

it('case 2b: the nightly prune purges a non-tracked group that still holds data', function () {
    [$group] = raceGroupWithData();
    $group->forceFill(['status' => GroupStatus::Untracked])->save();

    app(PruneBusinessData::class)->handle();

    expect(raceRows($group))->toBe(0);
});

it('case 3: the wipe job goes on after a throwing group and re-dispatches itself', function () {
    officeSettings(['ingest_enabled' => true, 'groups_enabled' => true]);
    $bad = TelegramGroup::factory()->tracked()->create();
    $good = TelegramGroup::factory()->tracked()->create();
    TelegramGroupMessage::factory()->inGroup($good)->create();

    $purge = Mockery::mock(PurgeGroupData::class);
    $purge->shouldReceive('handle')->andReturnUsing(function (TelegramGroup $group) use ($bad) {
        if ($group->id === $bad->id) {
            throw new RuntimeException('boom');
        }

        return app(PurgeGroupData::class)->handle($group);
    });

    Queue::fake();
    (new WipeGroupDataChunk(TelegramGroup::query()->max('id')))->handle($purge);

    expect(TelegramGroupMessage::query()->count())->toBe(0)
        ->and($good->fresh()->status)->toBe(GroupStatus::Untracked)
        ->and($bad->fresh()->status)->toBe(GroupStatus::Tracked);
    Queue::assertPushed(WipeGroupDataChunk::class, fn (WipeGroupDataChunk $job) => $job->round === 2);
});

it('case 3b: after its tries the wipe job re-dispatches once and has a backoff', function () {
    Queue::fake();
    $job = new WipeGroupDataChunk(10, 3);

    $job->failed(new RuntimeException('boom'));

    expect($job->backoff)->toBe([30, 120]);
    Queue::assertPushed(WipeGroupDataChunk::class, 1);

    // A job that is already the retry does not loop.
    Queue::fake();
    (new WipeGroupDataChunk(10, WipeGroupDataChunk::MAX_ROUNDS - 10))->failed(new RuntimeException('boom'));
    Queue::assertNothingPushed();
});

it('case 3c: a log warning when the rounds run out with groups left', function () {
    TelegramGroup::factory()->tracked()->create();
    Log::spy();
    Queue::fake();

    (new WipeGroupDataChunk(TelegramGroup::query()->max('id'), WipeGroupDataChunk::MAX_ROUNDS))->handle(Mockery::mock(PurgeGroupData::class)->shouldIgnoreMissing(new class {}));

    Log::shouldHaveReceived('warning')->with('office.wipe.groups', Mockery::on(fn (array $context) => $context['remaining'] === 1));
});

it('case 4: Wipe-all nulls the topic meta on the group leads, keeps the leads', function () {
    officeSettings(['ingest_enabled' => true, 'groups_enabled' => true]);
    $lead = Lead::factory()->create(['source' => LeadSource::TelegramBusiness]);
    $lead->activities()->create(['type' => ActivityType::AiSummary, 'meta' => ['group_analysis_id' => 1, 'topic' => 'Secret topic'], 'created_at' => now()]);
    TelegramGroup::factory()->tracked()->create();
    confirmAdminPassword();

    app(WipeBusinessData::class)->handle(staffUser(Role::Admin));

    expect($lead->fresh())->not->toBeNull()
        ->and($lead->activities()->where('type', 'ai_summary')->value('meta'))->toBeNull();
});

it('case 5: a queued analysis job whose row Untrack deleted exits without writing', function () {
    officeSettings(['ingest_enabled' => true, 'groups_enabled' => true, 'ai_enabled' => true]);
    [$group] = raceGroupWithData();
    $analysis = TelegramGroupAnalysis::factory()->create(['telegram_group_id' => $group->id]);
    $id = $analysis->id;
    confirmAdminPassword();
    app(UntrackGroup::class)->handle(staffUser(Role::Admin), $group);

    $model = new FakeChatModel;
    app()->instance(ChatModel::class, $model);
    (new AnalyseTelegramGroup($id))->handle($model, app(RunGroupAnalysis::class));

    expect(TelegramGroupAnalysis::query()->count())->toBe(0)
        ->and(TelegramGroupThread::query()->count())->toBe(0)
        ->and(Lead::query()->count())->toBe(0);
});

it('case 6: Track after a Wipe is allowed, but nothing is ingested until the groups switch is on', function () {
    Http::preventStrayRequests();
    groupsReady();
    $admin = staffUser(Role::Admin);
    $group = TelegramGroup::factory()->untracked()->create(['telegram_chat_id' => GROUP_SUPER_ID]);
    confirmAdminPassword();
    app(WipeBusinessData::class)->handle($admin);
    expect(OfficeSettings::groupsStored())->toBeFalse();

    app(TrackGroup::class)->handle($admin, $group, $admin->id, null, true);

    officeSettings(['ingest_enabled' => true]);
    groupHandle(groupFixture('message_supergroup', 91));

    expect($group->fresh()->status)->toBe(GroupStatus::Tracked)
        ->and(TelegramGroupMessage::query()->count())->toBe(0);

    officeSettings(['groups_enabled' => true]);
    groupHandle(groupFixture('message_supergroup', 92));

    expect(TelegramGroupMessage::query()->count())->toBe(1);
});

it('case 7: the prune skips a group while Untrack holds its lock', function () {
    [$group] = raceGroupWithData();
    $group->forceFill(['status' => GroupStatus::Untracked, 'last_message_at' => now()->subMonths(14)])->save();
    $lock = OfficeLock::forGroup($group->id);
    expect($lock->get())->toBeTrue();
    $before = raceRows($group);

    $counts = app(PruneBusinessData::class)->handle();

    expect($counts['group_skipped'])->toBeGreaterThanOrEqual(1)
        ->and(raceRows($group))->toBe($before);
    $lock->release();
});
