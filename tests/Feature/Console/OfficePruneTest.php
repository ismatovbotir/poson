<?php

use App\Enums\AnalysisStatus;
use App\Jobs\WipeBusinessDataChunk;
use App\Models\BusinessChat;
use App\Models\BusinessChatAnalysis;
use App\Models\BusinessConnection;
use App\Models\Lead;
use App\Support\Office\OfficeLock;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;

/*
| Plan 41 §9 / VO-18: office:prune retention rules.
*/

it('deletes unmarked stubs 14 days after the last inbound message, keeps newer ones', function () {
    $old = BusinessChat::factory()->create(['last_inbound_at' => now()->subDays(15)]);
    $new = BusinessChat::factory()->create(['last_inbound_at' => now()->subDays(13)]);

    $this->artisan('office:prune')->assertSuccessful();

    expect(BusinessChat::query()->pluck('id')->all())->toBe([$new->id])
        ->and($old->fresh())->toBeNull();
});

it('blanks the names of ignored chats after 14 days but keeps the row', function () {
    $chat = BusinessChat::factory()->ignored()->create(['first_name' => 'Fake', 'username' => 'fake_user', 'last_inbound_at' => now()->subDays(20)]);

    $this->artisan('office:prune');

    expect($chat->fresh())->not->toBeNull()
        ->and($chat->fresh()->first_name)->toBeNull()
        ->and($chat->fresh()->username)->toBeNull();
});

it('deletes messages and analyses of client chats silent for 12 months; the chat row stays while it has a lead', function () {
    $withLead = BusinessChat::factory()->client()->create(['last_message_at' => now()->subMonths(13)]);
    $withLead->forceFill(['lead_id' => Lead::factory()->create()->id])->save();
    $withoutLead = BusinessChat::factory()->client()->create(['last_message_at' => now()->subMonths(13)]);
    $recent = BusinessChat::factory()->client()->create(['last_message_at' => now()->subMonths(11)]);

    foreach ([$withLead, $withoutLead, $recent] as $chat) {
        $chat->messages()->create(['telegram_message_id' => 1, 'author' => 'client', 'kind' => 'text', 'text' => 'x']);
        BusinessChatAnalysis::factory()->done()->create(['business_chat_id' => $chat->id]);
    }

    $this->artisan('office:prune');

    expect($withLead->fresh())->not->toBeNull()
        ->and($withLead->messages()->count())->toBe(0)
        ->and($withLead->analyses()->count())->toBe(0)
        ->and($withoutLead->fresh())->toBeNull()
        ->and($recent->messages()->count())->toBe(1);
});

it('keeps the newest 5 analyses per chat and blanks drafts older than 30 days', function () {
    $chat = BusinessChat::factory()->client()->create(['last_message_at' => now()]);
    $rows = collect(range(1, 7))->map(fn () => BusinessChatAnalysis::factory()->done()->create(['business_chat_id' => $chat->id, 'draft_reply' => 'Draft']));
    BusinessChatAnalysis::query()->whereKey($rows->last()->id)->update(['created_at' => now()->subDays(31)]);

    $this->artisan('office:prune');

    expect($chat->analyses()->count())->toBe(5)
        ->and($chat->analyses()->pluck('id')->all())->toEqualCanonicalizing($rows->slice(2)->pluck('id')->all())
        ->and($rows->last()->fresh()->draft_reply)->toBeNull()
        ->and($rows[3]->fresh()->draft_reply)->toBe('Draft');
});

it('never deletes a pending analysis while keeping the newest five', function () {
    $chat = BusinessChat::factory()->client()->create(['last_message_at' => now()]);
    $pending = BusinessChatAnalysis::factory()->create(['business_chat_id' => $chat->id, 'status' => AnalysisStatus::Running]);
    collect(range(1, 6))->each(fn () => BusinessChatAnalysis::factory()->done()->create(['business_chat_id' => $chat->id]));

    $this->artisan('office:prune');

    expect($pending->fresh())->not->toBeNull();
});

it('deletes disallowed connections after 30 inactive days only', function () {
    $stranger = BusinessConnection::factory()->disallowed()->create(['updated_at' => now()->subDays(31)]);
    $recent = BusinessConnection::factory()->disallowed()->create();
    $owner = BusinessConnection::factory()->create(['updated_at' => now()->subDays(60)]);

    $this->artisan('office:prune');

    expect($stranger->fresh())->toBeNull()
        ->and($recent->fresh())->not->toBeNull()
        ->and($owner->fresh())->not->toBeNull();
});

it('is scheduled nightly with an English description and help', function () {
    $this->artisan('list', ['namespace' => 'office'])->expectsOutputToContain('office:prune')->assertSuccessful();

    $commands = collect(app(Kernel::class)->all())->only(['office:prune', 'office:analyse-due']);

    expect($commands)->toHaveCount(2);

    foreach ($commands as $command) {
        expect($command->getDescription())->not->toBe('')
            ->and($command->getHelp())->not->toBe('');
    }

    $events = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command)->implode("\n");

    expect($events)->toContain('office:prune')->toContain('office:analyse-due');
});

it('skips a chat with a queued or running analysis, and one whose lock is held', function () {
    $pending = BusinessChat::factory()->create(['last_inbound_at' => now()->subDays(20)]);
    BusinessChatAnalysis::factory()->create(['business_chat_id' => $pending->id, 'status' => AnalysisStatus::Queued]);
    $locked = BusinessChat::factory()->create(['last_inbound_at' => now()->subDays(20)]);
    $lock = OfficeLock::forChat($locked->id);
    $lock->get();

    try {
        $this->artisan('office:prune');
    } finally {
        $lock->release();
    }

    expect($pending->fresh())->not->toBeNull()
        ->and($locked->fresh())->not->toBeNull();
});

it('falls back to updated_at when a client chat has no last_message_at', function () {
    $chat = BusinessChat::factory()->client()->create(['last_message_at' => null]);
    $chat->messages()->create(['telegram_message_id' => 1, 'author' => 'client', 'kind' => 'text', 'text' => 'x']);
    BusinessChat::query()->whereKey($chat->id)->update(['updated_at' => now()->subMonths(13)]);

    $this->artisan('office:prune');

    expect($chat->fresh())->toBeNull();
});

it('does not select an already purged lead chat again', function () {
    $chat = BusinessChat::factory()->client()->create(['last_message_at' => now()->subMonths(13)]);
    $chat->forceFill(['lead_id' => Lead::factory()->create()->id])->save();

    $query = fn () => BusinessChat::query()->where('kind', 'client')->where(fn ($q) => $q->whereHas('messages')->orWhereHas('analyses')->orWhereNull('lead_id'));

    expect($query()->count())->toBe(0);

    $this->artisan('office:prune');

    expect($chat->fresh())->not->toBeNull();
});

it('wipes only chats that existed when the wipe started', function () {
    $old = BusinessChat::factory()->create();
    $job = WipeBusinessDataChunk::forNow();
    $new = BusinessChat::factory()->create();

    $job->handle();

    expect($old->fresh())->toBeNull()
        ->and($new->fresh())->not->toBeNull()
        ->and($job->queue)->toBe('default');
});
