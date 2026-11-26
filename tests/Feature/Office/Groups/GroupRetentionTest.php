<?php

use App\Actions\Office\PruneBusinessData;
use App\Actions\Office\PruneGroupData;
use App\Actions\Office\PurgeGroupData;
use App\Enums\AnalysisStatus;
use App\Enums\GroupStatus;
use App\Models\Lead;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupAnalysis;
use App\Models\TelegramGroupMember;
use App\Models\TelegramGroupMessage;
use App\Models\TelegramGroupThread;
use App\Support\Office\OfficeLock;
use Illuminate\Support\Facades\DB;

/*
| Plan 44 T7 / OG-12: group retention in office:prune, each rule just before and just after its boundary.
*/

function groupWithContent(array $group = [], array $stamp = []): TelegramGroup
{
    $row = TelegramGroup::factory()->tracked()->create($group);
    $member = TelegramGroupMember::factory()->create(['telegram_group_id' => $row->id, 'phone' => '+998901112233']);
    TelegramGroupMessage::factory()->create(['member_id' => $member->id, 'telegram_group_id' => $row->id]);
    TelegramGroupAnalysis::factory()->done()->create(['telegram_group_id' => $row->id]);
    TelegramGroupThread::factory()->create(['telegram_group_id' => $row->id, 'client_member_id' => $member->id]);

    return $row;
}

it('deletes messages, analyses and threads of a group silent for 12 months, keeps the group, members and leads', function () {
    $old = groupWithContent(['last_message_at' => now()->subMonths(12)->subDay()]);
    $fresh = groupWithContent(['last_message_at' => now()->subMonths(12)->addDay()]);
    $lead = Lead::factory()->create();
    TelegramGroupThread::query()->where('telegram_group_id', $old->id)->update(['lead_id' => $lead->id]);

    $this->artisan('office:prune')->assertSuccessful();

    expect(TelegramGroupMessage::query()->where('telegram_group_id', $old->id)->count())->toBe(0)
        ->and(TelegramGroupAnalysis::query()->where('telegram_group_id', $old->id)->count())->toBe(0)
        ->and(TelegramGroupThread::query()->where('telegram_group_id', $old->id)->count())->toBe(0)
        ->and($old->fresh())->not->toBeNull()
        ->and(TelegramGroupMember::query()->where('telegram_group_id', $old->id)->value('phone'))->toBeNull()
        ->and($lead->fresh())->not->toBeNull()
        ->and(TelegramGroupMessage::query()->where('telegram_group_id', $fresh->id)->count())->toBe(1)
        ->and(TelegramGroupAnalysis::query()->where('telegram_group_id', $fresh->id)->count())->toBe(1)
        ->and(TelegramGroupThread::query()->where('telegram_group_id', $fresh->id)->count())->toBe(1)
        ->and(TelegramGroupMember::query()->where('telegram_group_id', $fresh->id)->value('phone'))->toBe('+998901112233');
});

it('skips a silent group while an analysis is queued or its lock is held', function () {
    $queued = groupWithContent(['last_message_at' => now()->subMonths(13)]);
    TelegramGroupAnalysis::factory()->create(['telegram_group_id' => $queued->id, 'status' => AnalysisStatus::Queued]);
    $locked = groupWithContent(['last_message_at' => now()->subMonths(13)]);
    $lock = OfficeLock::forGroup($locked->id);
    expect($lock->get())->toBeTrue();

    $counts = app(PruneBusinessData::class)->handle();
    $lock->release();

    expect($counts['group_skipped'])->toBe(1)
        ->and(TelegramGroupMessage::query()->where('telegram_group_id', $queued->id)->count())->toBe(1)
        ->and(TelegramGroupMessage::query()->where('telegram_group_id', $locked->id)->count())->toBe(1);
});

it('deletes members who left more than 12 months ago and keeps the others', function (string $status, int $daysPastBoundary, bool $deleted) {
    $group = TelegramGroup::factory()->tracked()->create();
    $member = TelegramGroupMember::factory()->create([
        'telegram_group_id' => $group->id,
        'member_status' => $status,
        'last_seen_at' => now()->subMonths(12)->subDays($daysPastBoundary),
    ]);
    TelegramGroupMessage::factory()->create(['member_id' => $member->id, 'telegram_group_id' => $group->id]);

    $this->artisan('office:prune')->assertSuccessful();

    expect($member->fresh() === null)->toBe($deleted)
        ->and(TelegramGroupMessage::query()->where('member_id', $member->id)->exists())->toBe(! $deleted);
})->with([
    'left, a day past 12 months' => ['left', 1, true],
    'kicked, a day past 12 months' => ['kicked', 1, true],
    'left, a day before 12 months' => ['left', -1, false],
    'still a member, 20 months' => ['member', 240, false],
]);

it('nulls group drafts older than 30 days, keeps the newer ones', function () {
    $group = TelegramGroup::factory()->tracked()->create();
    $oldAnalysis = TelegramGroupAnalysis::factory()->done()->create(['telegram_group_id' => $group->id, 'draft_reply' => 'Old draft', 'created_at' => now()->subDays(31)]);
    $newAnalysis = TelegramGroupAnalysis::factory()->done()->create(['telegram_group_id' => $group->id, 'draft_reply' => 'New draft', 'created_at' => now()->subDays(29)]);
    $oldThread = TelegramGroupThread::factory()->create(['telegram_group_id' => $group->id, 'draft' => 'Old thread draft']);
    $newThread = TelegramGroupThread::factory()->create(['telegram_group_id' => $group->id, 'draft' => 'New thread draft']);
    DB::table('telegram_group_threads')->where('id', $oldThread->id)->update(['updated_at' => now()->subDays(31)]);
    DB::table('telegram_group_threads')->where('id', $newThread->id)->update(['updated_at' => now()->subDays(29)]);

    $this->artisan('office:prune');

    expect($oldAnalysis->fresh()->draft_reply)->toBeNull()
        ->and($newAnalysis->fresh()->draft_reply)->toBe('New draft')
        ->and($oldThread->fresh()->draft)->toBeNull()
        ->and($newThread->fresh()->draft)->toBe('New thread draft')
        ->and($oldThread->fresh()->summary)->not->toBeNull();
});

it('deletes untracked stubs silent for 30 days, keeps newer stubs, tracked and ignored groups', function () {
    $old = TelegramGroup::factory()->untracked()->create(['last_message_at' => now()->subDays(31)]);
    $left = TelegramGroup::factory()->untracked()->create(['last_message_at' => now()->subDays(40), 'bot_status' => 'left']);
    $new = TelegramGroup::factory()->untracked()->create(['last_message_at' => now()->subDays(29)]);
    $tracked = TelegramGroup::factory()->tracked()->create(['last_message_at' => now()->subDays(31)]);
    $ignored = TelegramGroup::factory()->ignored()->create(['last_message_at' => now()->subDays(60), 'title' => null]);

    $this->artisan('office:prune');

    expect($old->fresh())->toBeNull()
        ->and($left->fresh())->toBeNull()
        ->and($new->fresh())->not->toBeNull()
        ->and($tracked->fresh())->not->toBeNull()
        ->and($ignored->fresh())->not->toBeNull();
});

it('keeps the title of an ignored stub nulled', function () {
    $ignored = TelegramGroup::factory()->ignored()->create(['title' => 'Leaked title', 'username' => 'leaked_group']);

    $this->artisan('office:prune');

    expect($ignored->fresh()->title)->toBeNull()
        ->and($ignored->fresh()->username)->toBeNull()
        ->and($ignored->fresh()->status)->toBe(GroupStatus::Ignored);
});

it('never deletes a lead', function () {
    $group = groupWithContent(['last_message_at' => now()->subMonths(14)]);
    $lead = Lead::factory()->create();
    TelegramGroupThread::query()->where('telegram_group_id', $group->id)->update(['lead_id' => $lead->id]);
    TelegramGroupAnalysis::query()->where('telegram_group_id', $group->id)->update(['lead_id' => $lead->id]);

    app(PruneBusinessData::class)->handle();

    expect($lead->fresh())->not->toBeNull();
});

it('deletes in chunks: more rows than one chunk are all removed in several passes', function () {
    config()->set('office.retention.group_months', 12);
    $group = TelegramGroup::factory()->tracked()->create(['last_message_at' => now()->subMonths(13)]);
    $member = TelegramGroupMember::factory()->create(['telegram_group_id' => $group->id]);
    $rows = [];
    for ($i = 1; $i <= PurgeGroupData::CHUNK + 20; $i++) {
        $rows[] = ['telegram_group_id' => $group->id, 'member_id' => $member->id, 'telegram_message_id' => $i, 'kind' => 'text', 'text' => null, 'sent_at' => now(), 'created_at' => now()];
    }
    DB::table('telegram_group_messages')->insert($rows);

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        if (str_starts_with($query->sql, 'delete from "telegram_group_messages"')) {
            $statements[] = $query->sql;
        }
    });

    $counts = app(PruneGroupData::class)->handle();

    expect($counts['group_messages'])->toBe(PurgeGroupData::CHUNK + 20)
        ->and(TelegramGroupMessage::query()->count())->toBe(0)
        ->and(count($statements))->toBeGreaterThanOrEqual(2);
});

it('prunes every group through the command and logs counts only', function () {
    groupWithContent(['last_message_at' => now()->subMonths(13)]);

    $this->artisan('office:prune')->expectsOutputToContain('group_messages')->assertSuccessful();
});
