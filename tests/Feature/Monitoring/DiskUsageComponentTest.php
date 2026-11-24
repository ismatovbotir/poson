<?php

use App\Enums\DiskMeasureStatus;
use App\Enums\DiskRunError;
use App\Enums\DiskRunStatus;
use App\Enums\DiskTarget;
use App\Enums\Role;
use App\Jobs\MeasureProjectSize;
use App\Livewire\Admin\System\DiskUsage;
use App\Models\DiskSnapshot;
use App\Models\MonitoringState;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\Monitoring\DiskRun;
use App\Support\Monitoring\DiskThresholds;
use App\Support\Monitoring\FolderSizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

function diskComponent(): Testable
{
    return Livewire::withoutLazyLoading()->test(DiskUsage::class);
}

function diskProgress(DiskRunStatus $status, array $override = []): void
{
    $meta = DiskRun::meta(DiskRun::newRunId(), $status, now(), DiskTarget::Vendor, 120, 4096, in_array($status, [DiskRunStatus::Done, DiskRunStatus::Failed], true) ? now() : null);

    MonitoringState::query()->updateOrCreate(['key' => DiskRun::KEY], ['meta' => [...$meta, ...$override]]);
}

function diskSnapshots(): void
{
    foreach ([30, 7, 1, 0] as $back) {
        foreach ([[DiskTarget::ProjectTotal, 3000], [DiskTarget::Storage, 1000], [DiskTarget::Vendor, 1500], [DiskTarget::StorageLogs, 200]] as [$target, $bytes]) {
            DiskSnapshot::factory()->create([
                'target' => $target,
                'taken_on' => now('Asia/Tashkent')->subDays($back)->toDateString(),
                'size_bytes' => $bytes * 1024 ** 2 - $back * 1024 ** 2,
                'file_count' => 10 + $back,
            ]);
        }
    }
}

it('is forbidden for everyone but an Admin, on the lazy load', function (Role $role) {
    $this->actingAs(staffUser($role));

    diskComponent()->assertForbidden();
})->with([Role::ContentManager, Role::SalesManager, Role::ServiceEngineer]);

it('renders for an Admin and is embedded lazily on the monitoring page', function () {
    $this->actingAs(staffUser(Role::Admin));

    diskComponent()->assertOk()->assertSee(__('monitoring.disk.title'));
    $this->get(route('admin.monitoring'))->assertOk()->assertSee('admin.system.disk-usage', false)->assertSee('x-intersect', false);
});

it('re-authorizes on every later request: refresh, poll and range after a revoked role', function (string $method, array $args) {
    $admin = staffUser(Role::Admin);
    $this->actingAs($admin);

    $component = diskComponent()->assertOk();

    $admin->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $component->call($method, ...$args)->assertForbidden();
})->with([
    'refresh' => ['refresh', []],
    'poll render' => ['$refresh', []],
    'range' => ['setRange', [90]],
    'check again' => ['checkAgain', []],
]);

it('lets only monitoring.manage start a measurement and queues exactly one job for 10 clicks', function () {
    Queue::fake();
    $viewer = User::factory()->create()->givePermissionTo('monitoring.view');
    $this->actingAs($viewer);

    diskComponent()->assertDontSee('data-disk-measure', false)->call('measure')->assertForbidden();
    Queue::assertNothingPushed();

    $this->actingAs(staffUser(Role::Admin));
    $component = diskComponent()->assertSee('data-disk-measure', false);

    for ($i = 0; $i < 10; $i++) {
        $component->call('measure');
    }

    Queue::assertPushed(MeasureProjectSize::class, 1);
    $component->assertSee(__('monitoring.disk.queued'))->assertSeeHtml('wire:poll.2s');
});

it('polls only while a run is queued or running', function (DiskRunStatus $status, bool $polls) {
    $this->actingAs(staffUser(Role::Admin));
    diskProgress($status);

    $html = diskComponent()->html();

    expect(str_contains($html, 'wire:poll.2s'))->toBe($polls);
})->with([
    'queued' => [DiskRunStatus::Queued, true],
    'running' => [DiskRunStatus::Running, true],
    'done' => [DiskRunStatus::Done, false],
    'failed' => [DiskRunStatus::Failed, false],
    'idle' => [DiskRunStatus::Idle, false],
]);

it('shows no poll when there is no progress row at all', function () {
    $this->actingAs(staffUser(Role::Admin));

    expect(diskComponent()->html())->not->toContain('wire:poll');
});

it('stops polling after 20 minutes and offers "Check again"', function () {
    $this->actingAs(staffUser(Role::Admin));
    diskProgress(DiskRunStatus::Queued);

    $component = diskComponent()->assertSeeHtml('wire:poll.2s');

    // A queued run is fresh again 14 minutes later, but the client has been polling for 21.
    $this->travel(14)->minutes();
    diskProgress(DiskRunStatus::Queued);
    $this->travel(7)->minutes();
    diskProgress(DiskRunStatus::Queued);

    $component->call('poll');
    expect($component->html())->not->toContain('wire:poll.2s');

    $component->assertSee(__('monitoring.disk.poll_stopped'))->call('checkAgain')->assertSeeHtml('wire:poll.2s');
});

it('shows the waiting text, then live progress with the bucket label, files and size', function () {
    $this->actingAs(staffUser(Role::Admin));

    diskProgress(DiskRunStatus::Queued);
    diskComponent()->assertSee(__('monitoring.disk.queued'));

    diskProgress(DiskRunStatus::Running, ['files' => 1234, 'bytes' => 5 * 1024 ** 2]);
    diskComponent()
        ->assertSee(DiskTarget::Vendor->label())
        ->assertSee('1 234')
        ->assertSee('5.0 MB');
});

it('renders nothing for a tampered bucket and no free text from the progress row', function () {
    $this->actingAs(staffUser(Role::Admin));
    diskProgress(DiskRunStatus::Running, ['bucket' => '/var/www/<script>alert(1)</script>', 'error_code' => '<b>boom</b>']);

    diskComponent()->assertDontSee('/var/www')->assertDontSee('alert(1)', false)->assertDontSee('<b>boom</b>', false);
});

it('never touches the filesystem when polling or rendering', function () {
    $this->actingAs(staffUser(Role::Admin));
    app()->bind(FolderSizer::class, fn () => throw new RuntimeException('walked'));
    diskProgress(DiskRunStatus::Running);

    diskComponent()->call('$refresh')->call('refresh')->assertOk();
});

it('shows the failure reasons for a failed or stale run', function () {
    $this->actingAs(staffUser(Role::Admin));

    diskProgress(DiskRunStatus::Failed, ['error_code' => DiskRunError::UnreadableRoot->value]);
    diskComponent()->assertSee(DiskRunError::UnreadableRoot->label());

    diskProgress(DiskRunStatus::Running, ['heartbeat_at' => now()->subSeconds(61)->toIso8601String()]);
    diskComponent()->assertSee(DiskRunError::WorkerLost->label())->assertDontSeeHtml('wire:poll.2s');

    diskProgress(DiskRunStatus::Queued, ['started_at' => now()->subMinutes(16)->toIso8601String()]);
    diskComponent()->assertSee(DiskRunError::WorkerMissing->label());
});

it('shows the total, changes, limits, the table with the storage detail and the alert note', function () {
    $this->actingAs(staffUser(Role::Admin));
    diskSnapshots();

    $component = diskComponent();

    $component->assertSee('data-disk-total', false)
        ->assertSee(__('monitoring.disk.alerts_note'))
        ->assertSee('5 GB')
        ->assertSee('8 GB')
        ->assertSee(DiskTarget::Vendor->label())
        ->assertSee(DiskTarget::StorageLogs->label())
        ->assertSeeHtml('data-disk-detail="storage_logs"')
        ->assertSeeHtml('<svg');
});

it('shows an empty state before the first measurement', function () {
    $this->actingAs(staffUser(Role::Admin));

    diskComponent()->assertSee('data-disk-empty', false);
});

it('normalises the range to 30 or 90 and never fails on a bad one', function (mixed $range, int $days) {
    $this->actingAs(staffUser(Role::Admin));
    diskSnapshots();

    $this->get(route('admin.monitoring', ['range' => $range]))->assertOk();

    Livewire::withoutLazyLoading()->withQueryParams(['range' => $range])->test(DiskUsage::class)->assertOk()->assertSet('range', $days);
})->with([
    'abc' => ['abc', 30],
    'huge' => [100000, 30],
    'ninety' => [90, 90],
    'thirty' => [30, 30],
    'negative' => [-90, 30],
    'array-ish' => ['90abc', 30],
]);

it('switches the range only between 30 and 90', function () {
    $this->actingAs(staffUser(Role::Admin));

    diskComponent()->call('setRange', 90)->assertSet('range', 90)->call('setRange', 7)->assertSet('range', 30)->call('setRange', '../etc')->assertSet('range', 30);
});

it('ignores snapshot rows with an unknown target or status and never prints their names', function () {
    $this->actingAs(staffUser(Role::Admin));
    diskSnapshots();
    DB::table('disk_snapshots')->insert([
        ['target' => 'payroll-secrets', 'taken_on' => now('Asia/Tashkent')->toDateString(), 'size_bytes' => 999, 'file_count' => 1, 'status' => 'ok', 'duration_ms' => 1, 'measured_at' => now()],
        ['target' => 'vendor_x', 'taken_on' => now('Asia/Tashkent')->toDateString(), 'size_bytes' => 999, 'file_count' => 1, 'status' => 'weird', 'duration_ms' => 1, 'measured_at' => now()],
    ]);

    diskComponent()->assertOk()->assertDontSee('payroll-secrets')->assertDontSee('vendor_x');
});

it('leaks no path, hostname or discovered name into the HTML', function () {
    $this->actingAs(staffUser(Role::Admin));
    diskSnapshots();
    diskProgress(DiskRunStatus::Running);

    $html = diskComponent()->html();
    $page = $this->get(route('admin.monitoring'))->assertOk()->getContent();

    foreach ([$html, $page] as $output) {
        expect($output)->not->toContain(storage_path())->not->toContain(base_path())->not->toContain(gethostname() ?: 'no-host-x')->not->toContain(realpath(base_path()));
    }
});

it('reads with at most 3 data queries', function () {
    $this->actingAs(staffUser(Role::Admin));
    diskSnapshots();
    $component = diskComponent();
    $tables = [];

    DB::listen(function ($query) use (&$tables) {
        if (preg_match('/from "(disk_snapshots|monitoring_states|settings)"/', $query->sql, $match)) {
            $tables[] = $match[1];
        }
    });

    $component->call('refresh');

    expect(count($tables))->toBeLessThanOrEqual(3)->and($tables)->toContain('disk_snapshots', 'monitoring_states');
});

it('keeps the parent page free of the disk queries', function () {
    $this->actingAs(staffUser(Role::Admin));
    diskSnapshots();
    $tables = [];

    DB::listen(function ($query) use (&$tables) {
        if (str_contains($query->sql, 'disk_snapshots')) {
            $tables[] = $query->sql;
        }
    });

    $this->get(route('admin.monitoring'))->assertOk();

    expect($tables)->toBe([]);
});

it('saves the limits through the form with a confirmed password, an audit row and validation', function () {
    $this->actingAs($admin = staffUser(Role::Admin));
    confirmAdminPassword();

    diskComponent()
        ->assertSet('form.warn', '5')
        ->set('form.warn', '10')
        ->set('form.critical', '10')
        ->call('saveLimits')
        ->assertHasErrors(['form.critical']);

    diskComponent()
        ->set('form.warn', '5.5')->set('form.critical', '9')->call('saveLimits')->assertHasErrors(['form.warn']);

    diskComponent()
        ->set('form.warn', '-1')->set('form.critical', '9')->call('saveLimits')->assertHasErrors(['form.warn']);

    diskComponent()
        ->set('form.warn', 'abc')->set('form.critical', '9')->call('saveLimits')->assertHasErrors(['form.warn']);

    expect(SettingsAudit::query()->count())->toBe(0);

    diskComponent()
        ->set('form.warn', '12')->set('form.critical', '20')->call('saveLimits')->assertHasNoErrors()->assertSet('form.warn', '12');

    expect(app(DiskThresholds::class)->gb())->toBe(['warn_gb' => 12, 'critical_gb' => 20])
        ->and(SettingsAudit::query()->where('user_id', $admin->id)->count())->toBe(1);
});

it('redirects to the password confirmation instead of saving without one', function () {
    $this->actingAs(staffUser(Role::Admin));

    diskComponent()->set('form.warn', '12')->set('form.critical', '20')->call('saveLimits')->assertRedirect();

    expect(SettingsAudit::query()->count())->toBe(0)->and(app(DiskThresholds::class)->gb()['warn_gb'])->toBe(5);
});

it('forbids saving limits without monitoring.manage', function () {
    $this->actingAs(User::factory()->create()->givePermissionTo('monitoring.view'));
    confirmAdminPassword();

    diskComponent()->assertDontSee('data-disk-limits-form', false)->call('saveLimits')->assertForbidden();
});

it('has every new key in uz, ru and en', function () {
    $keys = ['monitoring.disk.title', 'monitoring.disk.alerts_note', 'monitoring.disk.form.save', 'monitoring.disk.toasts.saved', 'monitoring.disk.col.folder'];

    foreach (DiskTarget::cases() as $case) {
        $keys[] = 'enums.disk_target.'.$case->value;
    }

    foreach (DiskMeasureStatus::cases() as $case) {
        $keys[] = 'enums.disk_measure_status.'.$case->value;
    }

    foreach (DiskRunStatus::cases() as $case) {
        $keys[] = 'enums.disk_run_status.'.$case->value;
    }

    foreach (DiskRunError::cases() as $case) {
        $keys[] = 'enums.disk_run_error.'.$case->value;
    }

    foreach (['uz', 'ru', 'en'] as $locale) {
        foreach ($keys as $key) {
            expect(__($key, [], $locale))->not->toBe($key);
        }
    }
});

it('does not fail on an array payload for the range or the limits form', function () {
    $this->actingAs(staffUser(Role::Admin));
    confirmAdminPassword();

    diskComponent()->call('setRange', ['90'])->assertSet('range', 30)->assertOk();
    diskComponent()->set('form.warn', ['x'])->set('form.critical', ['y'])->call('saveLimits')->assertHasErrors(['form.warn']);
    Livewire::withoutLazyLoading()->withQueryParams(['range' => ['1']])->test(DiskUsage::class)->assertOk();
});

it('limits all Measure now attempts to 30 a minute per user', function () {
    Queue::fake();
    $admin = staffUser(Role::Admin);
    $this->actingAs($admin);

    for ($i = 0; $i < 30; $i++) {
        RateLimiter::hit('monitoring:disk-click:'.$admin->id, 60);
    }

    diskComponent()->call('measure')->assertDispatched('toast');
    Queue::assertNothingPushed();
});

it('does not read the history while a run is active', function () {
    $this->actingAs(staffUser(Role::Admin));
    diskSnapshots();
    diskProgress(DiskRunStatus::Running);
    $component = diskComponent();
    $queries = [];

    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $component->call('poll');

    expect(collect($queries)->filter(fn ($sql) => str_contains($sql, 'disk_snapshots'))->count())->toBe(0);
});

it('shows the note that sizes can differ from the hosting quota', function () {
    $this->actingAs(staffUser(Role::Admin));

    diskComponent()->assertSee(__('monitoring.disk.quota_note'));
});
