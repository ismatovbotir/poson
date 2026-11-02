<?php

use App\Enums\TaskRunStatus;
use App\Enums\TaskRunTrigger;
use App\Jobs\RecordWorkerHeartbeat;
use App\Models\ScheduledTaskRun;
use App\Support\Monitoring\MonitoredTasks;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

function recorderEvent(string $command = 'quotes:expire'): Event
{
    return (new Schedule)->command($command);
}

it('writes a running row on start and a success row on finish', function () {
    $event = recorderEvent();

    event(new ScheduledTaskStarting($event));

    $run = ScheduledTaskRun::query()->sole();
    expect($run->task)->toBe('quotes:expire')
        ->and($run->status)->toBe(TaskRunStatus::Running)
        ->and($run->trigger)->toBe(TaskRunTrigger::Schedule)
        ->and($run->finished_at)->toBeNull();

    $event->exitCode = 0;
    event(new ScheduledTaskFinished($event, 1.5));

    $run->refresh();
    expect($run->status)->toBe(TaskRunStatus::Success)
        ->and($run->duration_ms)->toBe(1500)
        ->and($run->exit_code)->toBe(0)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->output)->toBeNull();
});

it('records a non-zero exit as failed with redacted output of at most 2 KB', function () {
    $path = tempnam(sys_get_temp_dir(), 'sched');
    $event = recorderEvent();
    $event->output = $path;
    $event->exitCode = 2;
    file_put_contents($path, str_repeat('noise line '.PHP_EOL, 600)."connect failed for ops@poson.test token=FAKE-NOT-A-REAL-KEY-1234\n");

    event(new ScheduledTaskStarting($event));
    event(new ScheduledTaskFinished($event, 0.25));
    event(new ScheduledTaskFailed($event, new Exception('Scheduled command [x] failed with exit code [2].')));

    $run = ScheduledTaskRun::query()->sole();
    expect($run->status)->toBe(TaskRunStatus::Failed)
        ->and($run->exit_code)->toBe(2)
        ->and($run->duration_ms)->toBe(250)
        ->and(strlen($run->output))->toBeLessThanOrEqual(2048)
        ->and($run->output)->toContain('[email]', 'token=[redacted]')
        ->and($run->output)->not->toContain('ops@poson.test')
        ->and($run->output)->not->toContain('FAKE-NOT-A-REAL-KEY')
        ->and($run->error)->not->toBeEmpty();

    unlink($path);
});

it('records an exception from a task as failed with a redacted one-line error', function () {
    $event = recorderEvent();

    event(new ScheduledTaskStarting($event));
    event(new ScheduledTaskFailed($event, new RuntimeException("Could not reach ops@poson.test\n#0 stack frame")));

    $run = ScheduledTaskRun::query()->sole();
    expect($run->status)->toBe(TaskRunStatus::Failed)
        ->and($run->exit_code)->toBe(1)
        ->and($run->error)->toBe('Could not reach [email]');
});

it('records a skipped task', function () {
    event(new ScheduledTaskSkipped(recorderEvent('uzqr:sync')));

    $run = ScheduledTaskRun::query()->sole();
    expect($run->task)->toBe('uzqr:sync')
        ->and($run->status)->toBe(TaskRunStatus::Skipped);
});

it('does not record monitoring:check or the worker heartbeat job', function () {
    $schedule = new Schedule;
    $check = $schedule->command('monitoring:check');
    $job = $schedule->job(new RecordWorkerHeartbeat);

    foreach ([$check, $job] as $event) {
        event(new ScheduledTaskStarting($event));
        event(new ScheduledTaskFinished($event, 0.1));
        event(new ScheduledTaskSkipped($event));
    }

    expect(ScheduledTaskRun::query()->count())->toBe(0);
});

it('keeps two overlapping events apart by object id', function () {
    $a = recorderEvent('quotes:expire');
    $b = recorderEvent('mcp:sweep');

    event(new ScheduledTaskStarting($a));
    event(new ScheduledTaskStarting($b));
    event(new ScheduledTaskFinished($b, 0.2));

    expect(ScheduledTaskRun::query()->where('task', 'mcp:sweep')->value('status'))->toBe(TaskRunStatus::Success)
        ->and(ScheduledTaskRun::query()->where('task', 'quotes:expire')->value('status'))->toBe(TaskRunStatus::Running);
});

it('derives stable keys from the real schedule', function () {
    Artisan::call('schedule:list');
    $keys = MonitoredTasks::events()->keys()->all();

    expect($keys)->toContain('quotes:expire', 'uzqr:sync', 'mcp:sweep')
        ->and($keys)->toContain('model:prune --model=App\Models\McpCall')
        ->and($keys)->not->toContain('monitoring:check')
        ->and($keys)->not->toContain(RecordWorkerHeartbeat::class);
});

it('has no scheduled event that uses runInBackground', function () {
    Artisan::call('schedule:list');

    foreach (app(Schedule::class)->events() as $event) {
        expect($event->runInBackground)->toBeFalse(MonitoredTasks::key($event).' must not run in background (RunRecorder needs one process)');
    }
});

it('lists the monitoring lines in schedule:list', function () {
    Artisan::call('schedule:list');
    $output = Artisan::output();

    expect($output)->toContain(RecordWorkerHeartbeat::class)
        ->and($output)->toContain('model:prune')
        ->and($output)->toContain('ScheduledTaskRun')
        ->and($output)->toContain('MonitoringAction');
});

it('stores command output for events without output (overwrite only)', function () {
    Artisan::call('schedule:list');

    $event = MonitoredTasks::find('quotes:expire');

    expect($event->output)->toContain('storage')->toContain('schedule-')
        ->and($event->shouldAppendOutput)->toBeFalse();
});
