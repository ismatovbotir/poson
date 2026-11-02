<?php

use App\Actions\Monitoring\ForgetFailedJobs;
use App\Actions\Monitoring\MonitoringActionException;
use App\Actions\Monitoring\RetryFailedJobs;
use App\Actions\Monitoring\RunTaskNow;
use App\Enums\Role;
use App\Jobs\RunScheduledTask;
use App\Livewire\Admin\System\Monitoring;
use App\Models\MonitoringAction;
use App\Models\ScheduledTaskRun;
use App\Support\Admin\Menu;
use App\Support\Monitoring\Heartbeat;
use App\Support\Monitoring\MonitoredTasks;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Livewire\Livewire;

const PAYLOAD_SECRET_PHONE = '+998901234567';
const PAYLOAD_SECRET_TOKEN = 'payload-token-FAKE-0001';

function failedJob(string $class = 'App\Jobs\SendTelegramMessage', ?string $exception = null, ?string $failedAt = null): string
{
    $file = base_path('app/Jobs/SendTelegramMessage.php');
    $exception ??= "RuntimeException: Telegram API error (HTTP 500) in {$file}:50\nStack trace:\n#0 {$file}(50): fail()\n#1 {main}";
    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode([
            'displayName' => $class,
            'data' => ['command' => 'O:1:"x":2:{s:5:"phone";s:13:"'.PAYLOAD_SECRET_PHONE.'";s:5:"token";s:23:"'.PAYLOAD_SECRET_TOKEN.'";}'],
        ]),
        'exception' => $exception,
        'failed_at' => $failedAt ?? now()->toDateTimeString(),
    ]);

    return $uuid;
}

function snapshotReady(): void
{
    Artisan::call('schedule:list');
    MonitoredTasks::writeSnapshot();
}

function adminScreen(): array
{
    $admin = staffUser();
    confirmAdminPassword();

    return [$admin, Livewire::actingAs($admin)->test(Monitoring::class)];
}

it('opens for the Admin only, and the menu item shows for the Admin only', function () {
    $admin = staffUser();
    $this->actingAs($admin)->get(route('admin.monitoring'))->assertOk()->assertSee(__('monitoring.title'));
    expect(Menu::find(Menu::for($admin), 'monitoring'))->not->toBeNull();

    foreach ([Role::ContentManager, Role::SalesManager, Role::ServiceEngineer] as $role) {
        $user = staffUser($role);
        $this->actingAs($user)->get(route('admin.monitoring'))->assertForbidden();
        expect(Menu::find(Menu::for($user), 'monitoring'))->toBeNull();
    }
});

it('re-checks monitoring.view on every request, so a poll or an action by a non-Admin is forbidden', function () {
    $admin = staffUser();
    $component = Livewire::actingAs($admin)->test(Monitoring::class);

    $sales = staffUser(Role::SalesManager);
    $this->actingAs($sales);
    $component->call('$refresh')->assertForbidden();
    Livewire::actingAs($sales)->test(Monitoring::class)->assertForbidden();
});

it('shows the heartbeats, queue figures and the task table', function () {
    snapshotReady();
    Heartbeat::tick(Heartbeat::SCHEDULER);
    Heartbeat::tick(Heartbeat::WORKER, ['lag_ms' => 1500]);
    ScheduledTaskRun::factory()->failed('Boom')->create(['task' => 'quotes:expire']);

    [, $screen] = adminScreen();

    $screen->assertSee('quotes:expire')->assertSee('Boom')->assertSeeHtml('data-run-now')->assertSee('1 500');
});

it('offers Run now for runnable tasks only', function () {
    snapshotReady();

    [, $screen] = adminScreen();

    $html = $screen->html();
    preg_match_all('/data-task="([^"]+)"[^>]*>(.*?)<\/tr>/s', $html, $rows, PREG_SET_ORDER);
    $runnable = collect($rows)->filter(fn ($row) => str_contains($row[2], 'data-run-now'))->map(fn ($row) => $row[1])->values()->all();

    expect($runnable)->toEqualCanonicalizing(config('monitoring.manual_tasks'));
});

it('keeps payload data out of the page, the details and the audit', function () {
    $uuid = failedJob();
    [$admin, $screen] = adminScreen();

    $screen->assertSee('SendTelegramMessage')->assertSee('Telegram API error')
        ->call('showFailed', $uuid)->assertSeeHtml('data-failed-detail')->assertSee('~/app/Jobs/SendTelegramMessage.php');

    expect($screen->html())->not->toContain(PAYLOAD_SECRET_PHONE)->not->toContain(PAYLOAD_SECRET_TOKEN)->not->toContain(base_path());
});

it('warns about failed jobs older than 24 hours', function () {
    failedJob(failedAt: now()->subDays(2)->toDateTimeString());
    [, $screen] = adminScreen();

    $screen->assertSeeHtml('data-stale-job');
});

it('stays within 8 queries per render', function () {
    snapshotReady();
    foreach (range(1, 30) as $_) {
        failedJob();
    }
    ScheduledTaskRun::factory()->count(5)->create();
    [$admin, $screen] = adminScreen();

    $queries = countQueries(fn () => $screen->call('$refresh'));

    expect($queries)->toBeLessThanOrEqual(8);
});

it('paginates failed jobs 25 per page', function () {
    foreach (range(1, 30) as $_) {
        failedJob();
    }
    [, $screen] = adminScreen();

    expect(substr_count($screen->html(), 'data-failed-job='))->toBe(25);
});

it('needs a recent password for every write and redirects to the confirm page', function () {
    snapshotReady();
    $uuid = failedJob();
    $admin = staffUser();
    session()->forget('auth.password_confirmed_at');
    Queue::fake();

    Livewire::actingAs($admin)->test(Monitoring::class)
        ->call('runNow', 'quotes:expire')->assertRedirect(route('admin.confirm-password'))
        ->call('forgetOne', $uuid)->assertRedirect(route('admin.confirm-password'));

    Queue::assertNothingPushed();
    expect(DB::table('failed_jobs')->count())->toBe(1)->and(MonitoringAction::query()->count())->toBe(0);
});

it('refuses writes to a user without monitoring.manage', function () {
    snapshotReady();
    $sales = staffUser(Role::SalesManager);
    confirmAdminPassword();

    expect(fn () => app(RunTaskNow::class)->handle($sales, 'quotes:expire'))->toThrow(AuthorizationException::class);
    expect(fn () => app(ForgetFailedJobs::class)->handle($sales, [(string) Str::uuid()]))->toThrow(AuthorizationException::class);
    expect(fn () => app(RetryFailedJobs::class)->handle($sales, [(string) Str::uuid()]))->toThrow(AuthorizationException::class);
});

it('queues an allow-listed task on the monitoring queue and audits it', function () {
    snapshotReady();
    Queue::fake();
    [$admin, $screen] = adminScreen();

    $screen->call('runNow', 'quotes:expire');

    Queue::assertPushedOn('monitoring', RunScheduledTask::class, fn (RunScheduledTask $job) => $job->key === 'quotes:expire' && $job->userId === $admin->id);
    $audit = MonitoringAction::query()->sole();
    expect($audit->target)->toBe('["quotes:expire"]')->and($audit->count)->toBe(1)->and($audit->user_id)->toBe($admin->id);
});

it('rejects tampered and blocked task keys', function (string $key) {
    snapshotReady();
    Queue::fake();
    [, $screen] = adminScreen();

    $screen->call('runNow', $key);

    Queue::assertNothingPushed();
    expect(MonitoringAction::query()->count())->toBe(0);
})->with([
    'payment sync' => ['uzqr:sync'],
    'extra option' => ['quotes:expire --x'],
    'shell trick' => ['quotes:expire;id'],
    'prune' => ['model:prune --model=App\\Models\\McpCall'],
    'blocked idle revoke' => ['mcp:revoke-idle'],
    'unknown' => ['nope:nothing'],
    'empty' => [''],
]);

it('rejects a key that is allow-listed but missing from the schedule', function () {
    snapshotReady();
    config(['monitoring.manual_tasks' => [...config('monitoring.manual_tasks'), 'ghost:task']]);
    Queue::fake();
    [, $screen] = adminScreen();

    $screen->call('runNow', 'ghost:task');

    Queue::assertNothingPushed();
});

it('applies a cooldown per task and a rate limit per user', function () {
    snapshotReady();
    Queue::fake();
    [$admin] = adminScreen();
    $action = app(RunTaskNow::class);

    $action->handle($admin, 'quotes:expire');
    expect(fn () => $action->handle($admin, 'quotes:expire'))->toThrow(MonitoringActionException::class);
    Queue::assertPushed(RunScheduledTask::class, 1);

    foreach (['mcp:sweep', 'analytics:aggregate', 'analytics:verify-bots', 'analytics:refresh-bot-ranges'] as $key) {
        $action->handle($admin, $key);
    }
    Queue::assertPushed(RunScheduledTask::class, 5);

    Cache::forget('monitoring:run:quotes:expire');
    $thrown = null;
    try {
        $action->handle($admin, 'quotes:expire');
    } catch (MonitoringActionException $e) {
        $thrown = $e->reason;
    }

    expect($thrown)->toBe('rate');
    Queue::assertPushed(RunScheduledTask::class, 5);
});

it('refuses a run while the task still has a running row', function () {
    snapshotReady();
    Queue::fake();
    ScheduledTaskRun::factory()->running()->create(['task' => 'quotes:expire']);
    [, $screen] = adminScreen();

    $screen->call('runNow', 'quotes:expire');

    Queue::assertNothingPushed();
});

it('retries allow-listed failed jobs with the id array only and audits the uuids', function () {
    $a = failedJob();
    $b = failedJob('App\Listeners\SendLeadToTelegram');
    [, $screen] = adminScreen();

    $screen->set('selected', [$a, $b])->call('retrySelected');

    expect(DB::table('failed_jobs')->count())->toBe(0)->and(DB::table('jobs')->count())->toBe(2);
    $audit = MonitoringAction::query()->sole();
    expect(json_decode($audit->target, true))->toEqualCanonicalizing([$a, $b])->and($audit->count)->toBe(2);
});

it('retries nothing for all, a range, an unknown uuid or a mixed list', function (array $ids) {
    $real = failedJob();
    [$admin] = adminScreen();

    $ids = array_map(fn ($id) => $id === 'REAL' ? $real : $id, $ids);

    expect(fn () => app(RetryFailedJobs::class)->handle($admin, $ids))->toThrow(MonitoringActionException::class);
    expect(DB::table('failed_jobs')->count())->toBe(1)->and(DB::table('jobs')->count())->toBe(0)->and(MonitoringAction::query()->count())->toBe(0);
})->with([
    'all' => [['all']],
    'range' => [['--range=1-9999']],
    'range string' => [['1-100']],
    'unknown uuid' => [['2d9d4a3e-8f1b-4a4e-9a52-111111111111']],
    'real plus unknown' => [['REAL', '2d9d4a3e-8f1b-4a4e-9a52-111111111111']],
    'empty' => [[]],
    'nested' => [[['x']]],
]);

it('refuses to retry a class that is not allow-listed but lets it be forgotten', function () {
    $uuid = failedJob('App\Jobs\ProcessPayment');
    [$admin, $screen] = adminScreen();

    $screen->set('selected', [$uuid])->call('retrySelected');
    expect(DB::table('failed_jobs')->count())->toBe(1)->and(DB::table('jobs')->count())->toBe(0);

    $screen->call('forgetOne', $uuid);
    expect(DB::table('failed_jobs')->count())->toBe(0);
    expect(MonitoringAction::query()->sole()->action->value)->toBe('forget_job');
});

it('refuses more than 50 ids', function () {
    [$admin] = adminScreen();
    $ids = array_map(fn () => (string) Str::uuid(), range(1, 51));

    expect(fn () => app(ForgetFailedJobs::class)->handle($admin, $ids))->toThrow(MonitoringActionException::class);
    expect(MonitoringAction::query()->count())->toBe(0);
});

it('limits retry and forget calls to 10 per window per user', function () {
    [$admin] = adminScreen();
    RateLimiter::clear('monitoring:bulk:'.$admin->id);

    foreach (range(1, 10) as $_) {
        app(ForgetFailedJobs::class)->handle($admin, [failedJob()]);
    }

    expect(fn () => app(ForgetFailedJobs::class)->handle($admin, [failedJob()]))->toThrow(MonitoringActionException::class)
        ->and(DB::table('failed_jobs')->count())->toBe(1);
});

it('shows a toast, not an error, for a nested selection', function () {
    failedJob();
    [, $screen] = adminScreen();

    $screen->set('selected', [['x']])->call('retrySelected')->assertOk()->assertDispatched('toast');
    $screen->call('forgetSelected')->assertOk()->assertDispatched('toast');

    expect(DB::table('failed_jobs')->count())->toBe(1);
});

it('says already queued and writes no audit when a run is still queued', function () {
    snapshotReady();
    Queue::fake();
    [, $screen] = adminScreen();

    $screen->call('runNow', 'quotes:expire');
    Cache::forget('monitoring:run:quotes:expire');
    $screen->call('runNow', 'quotes:expire')->assertDispatched('toast', type: 'error');

    Queue::assertPushed(RunScheduledTask::class, 1);
    expect(MonitoringAction::query()->count())->toBe(1);
});

it('releases the queued mark when the job finishes', function () {
    config(['monitoring.manual_tasks' => ['test:manual']]);
    Schedule::call(fn () => null)->name('test:manual');
    Cache::put(RunTaskNow::QUEUED_KEY.md5('test:manual'), 1, 600);

    (new RunScheduledTask('test:manual', null))->handle();

    expect(Cache::has(RunTaskNow::QUEUED_KEY.md5('test:manual')))->toBeFalse();
});

it('warns when the queue retry_after is not above the run-now timeout', function () {
    config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 90]);
    [, $screen] = adminScreen();
    $screen->assertSeeHtml('data-retry-after-warning');

    config(['queue.connections.database.retry_after' => 660]);
    Livewire::actingAs(staffUser(Role::Admin))->test(Monitoring::class)->assertDontSeeHtml('data-retry-after-warning');
});

it('writes the audit row even when the retry call fails afterwards', function () {
    $uuid = failedJob();
    [$admin] = adminScreen();
    Artisan::shouldReceive('call')->andThrow(new RuntimeException('boom'));

    expect(fn () => app(RetryFailedJobs::class)->handle($admin, [$uuid]))->toThrow(RuntimeException::class);
    expect(MonitoringAction::query()->count())->toBe(1);
});
