<?php

use App\Actions\Monitoring\MonitoringActionException;
use App\Actions\Monitoring\StartDiskMeasurement;
use App\Enums\DiskMeasureStatus;
use App\Enums\DiskRunError;
use App\Enums\DiskRunStatus;
use App\Enums\DiskTarget;
use App\Enums\Role;
use App\Jobs\MeasureProjectSize;
use App\Models\DiskSnapshot;
use App\Models\MonitoringState;
use App\Models\User;
use App\Support\Monitoring\DiskMeasurement;
use App\Support\Monitoring\DiskRun;
use App\Support\Monitoring\FolderSizer;
use App\Support\Monitoring\ProblemMonitor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->scratch = realpath(sys_get_temp_dir()).'/poson-disk-'.bin2hex(random_bytes(6));
    mkdir($this->scratch.'/app/storage/logs', 0777, true);
    mkdir($this->scratch.'/app/vendor', 0777, true);
    file_put_contents($this->scratch.'/app/storage/logs/a.log', str_repeat('a', 100));
    file_put_contents($this->scratch.'/app/vendor/v.php', str_repeat('a', 400));
    file_put_contents($this->scratch.'/app/.env', str_repeat('a', 7));

    app()->bind(FolderSizer::class, fn () => new FolderSizer($this->scratch.'/app', $this->scratch.'/app/storage'));
});

afterEach(function () {
    File::deleteDirectory($this->scratch);
});

function diskAdmin(): User
{
    $admin = staffUser(Role::Admin);
    test()->actingAs($admin);

    return $admin;
}

function diskState(?array $meta): void
{
    MonitoringState::query()->updateOrCreate(['key' => DiskRun::KEY], ['meta' => $meta]);
}

function runJob(?string $runId = null): void
{
    app()->call([new MeasureProjectSize($runId), 'handle']);
}

it('is a unique single-try job on the monitoring queue and can be scheduled without arguments', function () {
    $job = new MeasureProjectSize;

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->queue)->toBe('monitoring')
        ->and($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(240)->and($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'))
        ->and($job->uniqueFor)->toBe(900)
        ->and(config('monitoring.manual_tasks'))->not->toContain(MeasureProjectSize::class);
});

it('has no schedule for the measurement and its own prune line at 02:55', function () {
    $events = collect(app(Schedule::class)->events());
    $prune = $events->first(fn ($event) => str_contains($event->command ?? '', 'model:prune') && str_contains($event->command, 'DiskSnapshot'));

    expect($prune)->not->toBeNull()
        ->and($prune->expression)->toBe('55 2 * * *')
        ->and($prune->command)->not->toContain('ScheduledTaskRun')
        ->and($events->filter(fn ($event) => str_contains((string) ($event->description ?? ''), 'MeasureProjectSize') || str_contains((string) ($event->command ?? ''), 'disk-snapshot'))->count())->toBe(0);
});

it('prunes snapshots older than 400 days only', function () {
    DiskSnapshot::factory()->create(['taken_on' => now('Asia/Tashkent')->subDays(401)->toDateString()]);
    DiskSnapshot::factory()->create(['taken_on' => now('Asia/Tashkent')->subDays(399)->toDateString()]);

    $this->artisan('model:prune', ['--model' => [DiskSnapshot::class]])->assertSuccessful();

    expect(DiskSnapshot::query()->count())->toBe(1);
});

it('lets only an Admin start a measurement', function (Role $role) {
    Queue::fake();
    $user = staffUser($role);

    expect(fn () => app(StartDiskMeasurement::class)->handle($user))->toThrow(AuthorizationException::class);
    Queue::assertNothingPushed();
})->with([Role::ContentManager, Role::SalesManager, Role::ServiceEngineer]);

it('queues one job for 10 rapid starts', function () {
    Queue::fake();
    $admin = staffUser(Role::Admin);
    $results = [];

    for ($i = 0; $i < 10; $i++) {
        $results[] = app(StartDiskMeasurement::class)->handle($admin);
    }

    Queue::assertPushed(MeasureProjectSize::class, 1);
    expect($results)->toBe([true, ...array_fill(0, 9, false)])
        ->and(DiskRun::current()->status)->toBe(DiskRunStatus::Queued)
        ->and(DiskRun::current()->runId)->toMatch('/^[a-z0-9]{32}$/');
});

it('allows only one claim of the run slot at a time', function () {
    $starter = app(StartDiskMeasurement::class);

    expect($starter->claim())->not->toBeNull()
        ->and($starter->claim())->toBeNull();
});

it('refuses a new start 15 minutes after a finished run and allows it afterwards', function () {
    Queue::fake();
    $admin = staffUser(Role::Admin);
    $this->travelTo(now()->startOfMinute());

    $starter = app(StartDiskMeasurement::class);
    $starter->handle($admin);
    runJob(DiskRun::current()->runId);
    expect(DiskRun::current()->status)->toBe(DiskRunStatus::Done);

    Queue::fake();
    $this->travel(14)->minutes();
    expect($starter->handle($admin))->toBeFalse();
    Queue::assertNothingPushed();

    $this->travel(2)->minutes();
    expect($starter->handle($admin))->toBeTrue();
    Queue::assertPushed(MeasureProjectSize::class, 1);
});

it('refuses a start for 5 minutes after a failed run', function () {
    Queue::fake();
    $admin = staffUser(Role::Admin);
    $starter = app(StartDiskMeasurement::class);

    $starter->handle($admin);
    DiskRun::fail(DiskRun::current()->runId, DiskRunError::Exception);

    expect($starter->handle($admin))->toBeFalse();

    $this->travel(6)->minutes();
    expect($starter->handle($admin))->toBeTrue();
});

it('limits starts to 5 per 10 minutes per user', function () {
    Queue::fake();
    $admin = staffUser(Role::Admin);

    for ($i = 0; $i < 5; $i++) {
        RateLimiter::hit('monitoring:disk-start:'.$admin->id, 600);
    }

    expect(fn () => app(StartDiskMeasurement::class)->handle($admin))->toThrow(MonitoringActionException::class);
    Queue::assertNothingPushed();
});

it('treats a running run with a heartbeat older than 60 s as failed and allows a new start', function () {
    Queue::fake();
    $admin = staffUser(Role::Admin);
    $old = DiskRun::newRunId();
    diskState(DiskRun::meta($old, DiskRunStatus::Running, now()->subSeconds(100)));
    DB::table('monitoring_states')->where('key', DiskRun::KEY)->update(['meta' => json_encode([...DiskRun::meta($old, DiskRunStatus::Running, now()->subSeconds(100)), 'heartbeat_at' => now()->subSeconds(61)->toIso8601String()])]);

    $stale = DiskRun::current();
    expect($stale->status)->toBe(DiskRunStatus::Failed)
        ->and($stale->stale)->toBeTrue()
        ->and($stale->error)->toBe(DiskRunError::WorkerLost);

    expect(app(StartDiskMeasurement::class)->handle($admin))->toBeTrue();
    Queue::assertPushed(MeasureProjectSize::class, 1);

    // The dead run's late writes change nothing (run_id conditional).
    $new = DiskRun::current()->runId;
    expect($new)->not->toBe($old)
        ->and(DiskRun::write($old, [DiskRunStatus::Running, DiskRunStatus::Queued], DiskRun::meta($old, DiskRunStatus::Done, now())))->toBeFalse()
        ->and(DiskRun::fail($old, DiskRunError::Exception))->toBeFalse()
        ->and(DiskRun::current()->runId)->toBe($new)
        ->and(DiskRun::current()->status)->toBe(DiskRunStatus::Queued);
});

it('treats a run past its maximum age as failed even with a fresh heartbeat', function () {
    diskState(DiskRun::meta(DiskRun::newRunId(), DiskRunStatus::Running, now()->subSeconds(361)));

    expect(DiskRun::current()->stale)->toBeTrue();
});

it('shows "worker not running" for a run queued over 15 minutes', function () {
    diskState(DiskRun::meta(DiskRun::newRunId(), DiskRunStatus::Queued, now()->subMinutes(16)));

    $run = DiskRun::current();

    expect($run->stale)->toBeTrue()
        ->and($run->error)->toBe(DiskRunError::WorkerMissing)
        ->and($run->mayStart())->toBeTrue();

    diskState(DiskRun::meta(DiskRun::newRunId(), DiskRunStatus::Queued, now()->subMinutes(5)));
    expect(DiskRun::current()->isActive())->toBeTrue();
});

it('ignores a tampered progress row: unknown bucket, status, run id and numbers', function () {
    diskState([
        'run_id' => '<script>/etc/passwd',
        'status' => 'running',
        'bucket' => '/var/www/secret',
        'files' => -5,
        'bytes' => 'abc',
        'started_at' => now()->toIso8601String(),
        'heartbeat_at' => now()->toIso8601String(),
        'error_code' => '../../x',
    ]);

    $run = DiskRun::current();

    expect($run->bucket)->toBeNull()
        ->and($run->error?->value)->not->toBe('../../x')
        ->and($run->files)->toBe(0)
        ->and($run->bytes)->toBe(0)
        ->and($run->stale)->toBeTrue();
});

it('measures, stores one snapshot row per target and finishes the run', function () {
    $this->travelTo(now('Asia/Tashkent')->setTime(12, 0));
    $runId = app(StartDiskMeasurement::class)->claim();

    runJob($runId);

    $run = DiskRun::current();
    $total = DiskSnapshot::query()->where('target', DiskTarget::ProjectTotal)->firstOrFail();

    expect($run->status)->toBe(DiskRunStatus::Done)
        ->and($run->bytes)->toBe(507)
        ->and($total->size_bytes)->toBe(507)
        ->and($total->status)->toBe(DiskMeasureStatus::Ok)
        ->and($total->taken_on->toDateString())->toBe(now('Asia/Tashkent')->toDateString())
        ->and($total->measured_at)->not->toBeNull()
        ->and(DiskSnapshot::query()->count())->toBe(count(DiskTarget::cases()))
        ->and(DiskSnapshot::query()->where('target', DiskTarget::Other)->value('size_bytes'))->toBe(7)
        ->and(DiskSnapshot::query()->where('target', DiskTarget::StorageLogs)->value('size_bytes'))->toBe(100);
});

it('upserts on a rerun the same day and never deletes anything', function () {
    $starter = app(StartDiskMeasurement::class);
    runJob($starter->claim());

    file_put_contents($this->scratch.'/app/vendor/more.php', str_repeat('a', 1000));
    $this->travel(20)->minutes();
    runJob($starter->claim());

    expect(DiskSnapshot::query()->count())->toBe(count(DiskTarget::cases()))
        ->and(DiskSnapshot::query()->where('target', DiskTarget::ProjectTotal)->value('size_bytes'))->toBe(1507)
        ->and(file_exists($this->scratch.'/app/vendor/v.php'))->toBeTrue();
});

it('does nothing for a run id that is no longer queued', function () {
    app(StartDiskMeasurement::class)->claim();

    runJob(DiskRun::newRunId());

    expect(DiskSnapshot::query()->count())->toBe(0)
        ->and(DiskRun::current()->status)->toBe(DiskRunStatus::Queued);
});

it('can claim its own run when scheduled without a run id', function () {
    runJob();

    expect(DiskRun::current()->status)->toBe(DiskRunStatus::Done)
        ->and(DiskSnapshot::query()->count())->toBe(count(DiskTarget::cases()));
});

it('writes progress at most about once a second', function () {
    for ($i = 0; $i < 1500; $i++) {
        file_put_contents($this->scratch."/app/vendor/f{$i}", 'a');
    }

    $runId = app(StartDiskMeasurement::class)->claim();
    $writes = 0;
    DB::listen(function ($query) use (&$writes) {
        if (str_starts_with($query->sql, 'update "monitoring_states"')) {
            $writes++;
        }
    });

    runJob($runId);

    expect($writes)->toBeLessThanOrEqual(4)->toBeGreaterThanOrEqual(2);
});

it('fails with a code and no path when the walk throws', function () {
    app()->bind(FolderSizer::class, fn () => new class extends FolderSizer
    {
        public function measure(?Closure $progress = null): DiskMeasurement
        {
            throw new RuntimeException('cannot open '.storage_path('app/private/payroll.xlsx').' in '.realpath(storage_path()).' on '.base_path());
        }
    });

    $runId = app(StartDiskMeasurement::class)->claim();
    runJob($runId);

    $row = DB::table('monitoring_states')->where('key', DiskRun::KEY)->value('meta');

    expect(DiskRun::current()->status)->toBe(DiskRunStatus::Failed)
        ->and(DiskRun::current()->error)->toBe(DiskRunError::Exception)
        ->and($row)->not->toContain(storage_path())->not->toContain(base_path())->not->toContain('payroll')
        ->and(DiskSnapshot::query()->count())->toBe(0);
});

it('marks the run failed when the queue reports the job as failed, only for its own run id', function () {
    $runId = app(StartDiskMeasurement::class)->claim();

    (new MeasureProjectSize(DiskRun::newRunId()))->failed(new RuntimeException('x'));
    expect(DiskRun::current()->status)->toBe(DiskRunStatus::Queued);

    (new MeasureProjectSize($runId))->failed(new RuntimeException('x'));
    expect(DiskRun::current()->status)->toBe(DiskRunStatus::Failed);
});

it('fails with unreadable_root when the project root cannot be resolved', function () {
    app()->bind(FolderSizer::class, fn () => new FolderSizer($this->scratch.'/missing', $this->scratch.'/missing/storage'));

    runJob(app(StartDiskMeasurement::class)->claim());

    expect(DiskRun::current()->error)->toBe(DiskRunError::UnreadableRoot)
        ->and(DiskSnapshot::query()->count())->toBe(0);
});

it('records a budget stop as done with partial statuses', function () {
    app()->bind(FolderSizer::class, fn () => new FolderSizer($this->scratch.'/app', $this->scratch.'/app/storage', 2));

    runJob(app(StartDiskMeasurement::class)->claim());

    expect(DiskRun::current()->status)->toBe(DiskRunStatus::Done)
        ->and(DiskRun::current()->error)->toBe(DiskRunError::Budget)
        ->and(DiskSnapshot::query()->where('target', DiskTarget::ProjectTotal)->value('status'))->toBe(DiskMeasureStatus::Partial);
});

it('does no disk evaluation in the minute check', function () {
    DiskSnapshot::factory()->create(['size_bytes' => 50 * 1024 ** 3]);

    (new ProblemMonitor)->evaluate();

    expect(MonitoringState::query()->where('key', 'problem:project_size')->exists())->toBeFalse();
});

it('ends a claimed run as worker_lost when the mutex is held, and allows a new start after 5 minutes', function () {
    $held = Cache::lock(MeasureProjectSize::MUTEX, 300);
    expect($held->get())->toBeTrue();

    $runId = app(StartDiskMeasurement::class)->claim();
    runJob($runId);

    expect(DiskRun::current()->status)->toBe(DiskRunStatus::Failed)
        ->and(DiskRun::current()->error)->toBe(DiskRunError::WorkerLost)
        ->and(DiskSnapshot::query()->count())->toBe(0)
        ->and(app(StartDiskMeasurement::class)->claim())->toBeNull();

    $held->release();
    $this->travel(6)->minutes();
    expect(app(StartDiskMeasurement::class)->claim())->not->toBeNull();
});

it('keeps a complete larger figure of the same day when a later run is partial', function () {
    $starter = app(StartDiskMeasurement::class);
    file_put_contents($this->scratch.'/app/vendor/big.php', str_repeat('a', 5000));
    runJob($starter->claim());
    $ok = DiskSnapshot::query()->where('target', DiskTarget::ProjectTotal)->value('size_bytes');

    app()->bind(FolderSizer::class, fn () => new FolderSizer($this->scratch.'/app', $this->scratch.'/app/storage', 2));
    $this->travel(20)->minutes();
    runJob($starter->claim());

    $total = DiskSnapshot::query()->where('target', DiskTarget::ProjectTotal)->first();

    expect($ok)->toBe(5507)
        ->and($total->size_bytes)->toBe(5507)
        ->and($total->status)->toBe(DiskMeasureStatus::Ok)
        ->and(DiskRun::current()->status)->toBe(DiskRunStatus::Done);
});

it('does not clear an open alert after a smaller partial run', function () {
    freshSecrets(['services.telegram.bot_token' => '123456789:'.str_repeat('M', 35), 'services.telegram.chats.management' => '-1009999999999']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    (new ProblemMonitor)->evaluateProjectSize(6 * 1024 ** 3);

    app()->bind(FolderSizer::class, fn () => new FolderSizer($this->scratch.'/app', $this->scratch.'/app/storage', 2));
    runJob(app(StartDiskMeasurement::class)->claim());

    expect(MonitoringState::query()->find('problem:project_size')->problem_since)->not->toBeNull();
});

it('ends a scheduled run as failed when failed() runs for a job without a run id', function () {
    $fake = Mockery::mock(Job::class);
    $fake->shouldReceive('uuid')->andReturn('job-uuid-1');

    $job = new MeasureProjectSize;
    $job->setJob($fake);
    app()->call([$job, 'handle']);
    expect(DiskRun::current()->status)->toBe(DiskRunStatus::Done);

    $this->travel(20)->minutes();
    $runId = app(StartDiskMeasurement::class)->claim();
    DiskRun::write($runId, [DiskRunStatus::Queued], DiskRun::meta($runId, DiskRunStatus::Running, now()));
    Cache::put(MeasureProjectSize::JOB_RUN_PREFIX.'job-uuid-1', $runId, 600);

    $fresh = new MeasureProjectSize;
    $fresh->setJob($fake);
    $fresh->failed(new RuntimeException('x'));

    expect(DiskRun::current()->status)->toBe(DiskRunStatus::Failed)->and(DiskRun::current()->runId)->toBe($runId);
});

it('refuses the writes of a superseded job and its release does not free the new job lock', function () {
    app()->bind(FolderSizer::class, fn () => new class($this->scratch.'/app', $this->scratch.'/app/storage') extends FolderSizer
    {
        public function measure(?Closure $progress = null): DiskMeasurement
        {
            $result = parent::measure($progress);

            // Meanwhile the run went stale and a new start took over: lock released, new run queued, new job holds the lock.
            Cache::lock(MeasureProjectSize::MUTEX)->forceRelease();
            DB::table('monitoring_states')->where('key', DiskRun::KEY)->update(['meta' => json_encode(DiskRun::meta(DiskRun::newRunId(), DiskRunStatus::Queued, now()))]);
            Cache::lock(MeasureProjectSize::MUTEX, 300)->get();

            return $result;
        }
    });

    runJob(app(StartDiskMeasurement::class)->claim());

    expect(DiskSnapshot::query()->count())->toBe(0)
        ->and(DiskRun::current()->status)->toBe(DiskRunStatus::Queued)
        ->and(Cache::lock(MeasureProjectSize::MUTEX, 300)->get())->toBeFalse();
});

it('reports cumulative files and bytes across buckets in the progress row', function () {
    for ($i = 0; $i < 1200; $i++) {
        file_put_contents($this->scratch."/app/vendor/f{$i}", 'a');
    }

    runJob(app(StartDiskMeasurement::class)->claim());

    expect(DiskRun::current()->files)->toBe(1203)->and(DiskRun::current()->bytes)->toBe(100 + 400 + 7 + 1200);
});
