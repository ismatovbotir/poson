<?php

use App\Models\Activity;
use App\Models\Lead;
use App\Models\TelegramMessage;
use App\Models\TelegramUpdate;
use App\Models\TelegramUser;
use App\Support\Monitoring\MonitoredTasks;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Plan 21 B3 (TG-27 amended, T21-20): retention schedule and the shared
 * `deleting` hook. Retention: unregistered 30 days, silent threads 12 months,
 * updates 3 days, failed jobs 30 days; lead-linked threads kept.
 */
function retentionPruneModels(): void
{
    Artisan::call('model:prune', ['--model' => [TelegramUser::class, TelegramUpdate::class]]);
}

function retentionActivity(TelegramUser $user): Activity
{
    return Activity::factory()->subject($user)->create();
}

it('schedules the Telegram prune lines on one server without overlap (T21-20)', function () {
    Artisan::call('schedule:list');
    $events = MonitoredTasks::events();

    expect($events->keys()->all())->toContain('queue:prune-failed --hours=720', 'model:prune --model=App\Models\TelegramUser --model=App\Models\TelegramUpdate');

    $prune = $events->first(fn ($event, $key) => str_starts_with($key, 'model:prune') && str_contains($key, 'TelegramUser'));
    $failed = $events->get('queue:prune-failed --hours=720');

    expect($prune)->not->toBeNull()
        ->and($prune->command)->toContain('TelegramUser')->toContain('TelegramUpdate')
        ->and($prune->expression)->toBe('0 3 * * *')
        ->and($prune->onOneServer)->toBeTrue()
        ->and($prune->withoutOverlapping)->toBeTrue()
        ->and($failed->expression)->toBe('20 3 * * *')
        ->and($failed->onOneServer)->toBeTrue()
        ->and($failed->withoutOverlapping)->toBeTrue();
});

it('prunes by the 12 month / 30 day / 3 day rules and keeps lead-linked threads', function () {
    $oldVisitor = TelegramUser::factory()->unregistered()->create(['created_at' => now()->subDays(31)]);
    $newVisitor = TelegramUser::factory()->unregistered()->create(['created_at' => now()->subDays(29)]);
    $silent = TelegramUser::factory()->registered()->create(['last_message_at' => now()->subMonths(12)->subDay()]);
    $recent = TelegramUser::factory()->registered()->create(['last_message_at' => now()->subMonths(11)]);
    $withLead = TelegramUser::factory()->registered()->create(['last_message_at' => now()->subMonths(30)]);
    TelegramMessage::factory()->create(['telegram_user_id' => $withLead->id, 'lead_id' => Lead::factory()->create()->id]);
    TelegramMessage::factory()->create(['telegram_user_id' => $silent->id]);
    TelegramUpdate::factory()->create(['update_id' => 1, 'created_at' => now()->subDays(4)]);
    TelegramUpdate::factory()->create(['update_id' => 2, 'created_at' => now()->subDays(2)]);

    retentionPruneModels();

    expect(TelegramUser::query()->pluck('id')->sort()->values()->all())->toBe(collect([$newVisitor->id, $recent->id, $withLead->id])->sort()->values()->all())
        ->and(TelegramUser::query()->whereKey([$oldVisitor->id, $silent->id])->exists())->toBeFalse()
        ->and(TelegramMessage::query()->where('telegram_user_id', $silent->id)->exists())->toBeFalse()
        ->and(TelegramMessage::query()->where('telegram_user_id', $withLead->id)->exists())->toBeTrue()
        ->and(TelegramUpdate::query()->pluck('update_id')->all())->toBe([2]);
});

it('removes the activities of a pruned thread and no others', function () {
    $stale = TelegramUser::factory()->registered()->create(['last_message_at' => now()->subMonths(13)]);
    $kept = TelegramUser::factory()->registered()->create(['last_message_at' => now()]);
    retentionActivity($stale);
    retentionActivity($stale);
    $keptActivity = retentionActivity($kept);

    retentionPruneModels();

    expect(Activity::query()->count())->toBe(1)
        ->and(Activity::query()->sole()->is($keptActivity))->toBeTrue();
});

it('removes the activities of a thread deleted by hand through the same hook', function () {
    $user = TelegramUser::factory()->registered()->create();
    $other = TelegramUser::factory()->registered()->create();
    retentionActivity($user);
    retentionActivity($other);

    $user->delete();

    expect(Activity::query()->where('subject_type', $user->getMorphClass())->where('subject_id', $user->id)->exists())->toBeFalse()
        ->and(Activity::query()->where('subject_id', $other->id)->count())->toBe(1);
});

it('makes no Telegram call when pruning and logs no thread content', function () {
    Http::fake();
    Log::spy();
    $stale = TelegramUser::factory()->registered()->create(['name' => 'Private Person', 'last_message_at' => now()->subMonths(13)]);
    TelegramMessage::factory()->forwarded()->create(['telegram_user_id' => $stale->id, 'text' => 'secret text']);

    retentionPruneModels();

    Http::assertNothingSent();
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('warning');
    expect(TelegramUser::query()->count())->toBe(0);
});

it('prunes failed jobs older than 30 days only', function () {
    $row = fn (string $uuid, $failedAt) => [
        'uuid' => $uuid, 'connection' => 'database', 'queue' => 'default',
        'payload' => '{}', 'exception' => 'x', 'failed_at' => $failedAt,
    ];
    DB::table('failed_jobs')->insert([
        $row('old-job', now()->subDays(31)),
        $row('new-job', now()->subDays(29)),
    ]);

    Artisan::call('queue:prune-failed', ['--hours' => 720]);

    expect(DB::table('failed_jobs')->pluck('uuid')->all())->toBe(['new-job']);
});
