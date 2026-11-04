<?php

use App\Actions\Monitoring\MonitoringActionException;
use App\Actions\Monitoring\RetryFailedJobs;
use App\Actions\Telegram\ScrubBannedTelegramUsers;
use App\Enums\TelegramUserState;
use App\Jobs\SendTelegramMessage;
use App\Livewire\Admin\System\Monitoring;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\MonitoringAction;
use App\Models\TelegramMessage;
use App\Models\TelegramUpdate;
use App\Models\TelegramUser;
use App\Support\Monitoring\MonitoredTasks;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;

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

it('never prunes a banned row, unregistered or silent, and the ban still works', function (string $kind) {
    $row = $kind === 'unregistered'
        ? TelegramUser::factory()->unregistered()->create(['created_at' => now()->subDays(60)])
        : TelegramUser::factory()->registered()->create(['last_message_at' => now()->subMonths(14)]);
    $row->forceFill(['banned_at' => now()])->save();
    TelegramMessage::factory()->create(['telegram_user_id' => $row->id]);

    retentionPruneModels();

    expect(TelegramUser::query()->find($row->id)?->isBanned())->toBeTrue()
        ->and(TelegramMessage::query()->where('telegram_user_id', $row->id)->exists())->toBeTrue();
})->with(['unregistered', 'silent']);

it('refuses to retry an inbox alert from failed jobs and still retries a sales alert (T21-31)', function () {
    $queue = fn (SendTelegramMessage $job, string $uuid) => DB::table('failed_jobs')->insert([
        'uuid' => $uuid, 'connection' => 'database', 'queue' => 'default',
        'payload' => json_encode(['displayName' => SendTelegramMessage::class, 'data' => ['commandName' => SendTelegramMessage::class, 'command' => serialize($job)]]),
        'exception' => 'x', 'failed_at' => now(),
    ]);
    $inbox = (string) Str::uuid();
    $sales = (string) Str::uuid();
    $queue(new SendTelegramMessage('sales', 'CUSTOMER SECRET TEXT', [], 'TelegramInboxMessage', inbox: true), $inbox);
    $queue(new SendTelegramMessage('sales', 'order text'), $sales);
    $admin = staffUser();
    confirmAdminPassword();

    expect(fn () => app(RetryFailedJobs::class)->handle($admin, [$inbox]))->toThrow(MonitoringActionException::class);
    expect(DB::table('failed_jobs')->where('uuid', $inbox)->exists())->toBeTrue()
        ->and(MonitoringAction::query()->count())->toBe(0);

    $screen = Livewire::actingAs($admin)->test(Monitoring::class)->call('retryOne', $inbox)->assertDispatched('toast', body: __('monitoring.errors.inbox_alert'), type: 'error')->assertDontSee('CUSTOMER SECRET TEXT');
    expect(DB::table('failed_jobs')->where('uuid', $inbox)->exists())->toBeTrue();

    expect(app(RetryFailedJobs::class)->handle($admin, [$sales]))->toBe(1)
        ->and(DB::table('failed_jobs')->where('uuid', $sales)->exists())->toBeFalse();
});

function retentionBanned(array $attributes = [], bool $registered = false): TelegramUser
{
    $user = TelegramUser::factory()->{$registered ? 'registered' : 'unregistered'}()->create(array_merge([
        'username' => 'secret_user', 'first_name' => 'Ali', 'phone' => '+998901234567', 'name' => 'Ali Valiyev',
        'declared_company' => 'OOO Secret', 'declared_inn' => '123456789', 'consent_at' => now()->subYear(),
        'forward_header_message_id' => 10, 'forward_header_at' => now()->subYear(), 'forward_header_chat_id' => -100123,
        'created_at' => now()->subMonths(14), 'last_message_at' => null,
    ], $attributes));
    $user->forceFill(['banned_at' => now()->subDays(40), 'banned_by_id' => null])->save();
    TelegramMessage::factory()->create(['telegram_user_id' => $user->id, 'text' => 'secret text']);
    retentionActivity($user);

    return $user;
}

it('scrubs a banned user past retention, keeps the ban and does it only once', function () {
    $user = retentionBanned();

    expect(app(ScrubBannedTelegramUsers::class)->handle())->toBe(1);

    $fresh = $user->fresh();

    expect($fresh->banned_at)->not->toBeNull()
        ->and($fresh->isBanned())->toBeTrue()
        ->and($fresh->telegram_id)->toBe($user->telegram_id)
        ->and($fresh->state)->toBe(TelegramUserState::Language)
        ->and(collect(ScrubBannedTelegramUsers::NULLED)->filter(fn ($column) => $fresh->{$column} !== null)->all())->toBe([])
        ->and(TelegramMessage::query()->where('telegram_user_id', $user->id)->exists())->toBeFalse()
        ->and(Activity::query()->where('subject_id', $user->id)->exists())->toBeFalse();

    $updatedAt = $fresh->updated_at;
    $this->travel(1)->hours();

    expect(app(ScrubBannedTelegramUsers::class)->handle())->toBe(0)
        ->and($user->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
});

it('waits for the retention counted from the later of the last message and the ban', function () {
    $recentBan = retentionBanned();
    $recentBan->forceFill(['banned_at' => now()->subDays(2)])->save();
    $registered = retentionBanned(['last_message_at' => now()->subMonths(5)], registered: true);
    $registeredOld = retentionBanned(['last_message_at' => now()->subMonths(13)], registered: true);
    $registeredOld->forceFill(['banned_at' => now()->subMonths(13)])->save();
    $banNewer = retentionBanned(['last_message_at' => now()->subMonths(14)], registered: true);

    expect(app(ScrubBannedTelegramUsers::class)->handle())->toBe(1)
        ->and($recentBan->fresh()->username)->toBe('secret_user')
        ->and($registered->fresh()->username)->toBe('secret_user')
        ->and($banNewer->fresh()->username)->toBe('secret_user')
        ->and($registeredOld->fresh()->username)->toBeNull();
});

it('leaves a lead-linked banned thread whole', function () {
    $user = retentionBanned();
    TelegramMessage::factory()->create(['telegram_user_id' => $user->id, 'lead_id' => Lead::factory()->create()->id]);

    expect(app(ScrubBannedTelegramUsers::class)->handle())->toBe(0)
        ->and($user->fresh()->username)->toBe('secret_user')
        ->and(TelegramMessage::query()->where('telegram_user_id', $user->id)->count())->toBe(2);
});

it('makes no Telegram call and logs the count only when scrubbing, and schedules the command daily', function () {
    Http::fake();
    Log::spy();
    retentionBanned();

    Artisan::call('telegram:scrub-banned');

    Http::assertNothingSent();
    Log::shouldHaveReceived('info')->withArgs(fn ($message, $context = []) => $context === ['count' => 1])->once();
    Artisan::call('schedule:list');
    $event = MonitoredTasks::events()->get('telegram:scrub-banned');

    expect($event->expression)->toBe('10 3 * * *')->and($event->onOneServer)->toBeTrue()->and($event->withoutOverlapping)->toBeTrue();
});

it('refuses to retry a pre-flag inbox alert recognised by its context (T21-31)', function () {
    $uuid = (string) Str::uuid();
    $job = new SendTelegramMessage('sales', 'CUSTOMER SECRET TEXT', [], 'TelegramInboxMessage');
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid, 'connection' => 'database', 'queue' => 'default',
        'payload' => json_encode(['displayName' => SendTelegramMessage::class, 'data' => ['commandName' => SendTelegramMessage::class, 'command' => serialize($job)]]),
        'exception' => 'x', 'failed_at' => now(),
    ]);
    $admin = staffUser();
    confirmAdminPassword();

    expect(fn () => app(RetryFailedJobs::class)->handle($admin, [$uuid]))->toThrow(MonitoringActionException::class);
});
