<?php

use App\Enums\TaskRunStatus;
use App\Enums\TaskRunTrigger;
use App\Jobs\RunScheduledTask;
use App\Models\ScheduledTaskRun;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Exceptions;

beforeEach(function () {
    $this->ran = 0;
    config(['monitoring.manual_tasks' => ['test:manual']]);
    app(Schedule::class)->call(function () {
        $this->ran++;
    })->name('test:manual')->withoutOverlapping(5);
});

it('is a unique single-try job on the monitoring queue with a 600 s timeout', function () {
    $job = new RunScheduledTask('test:manual', 1);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->queue)->toBe('monitoring')
        ->and($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(600)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->uniqueId())->toBe(md5('test:manual'));
});

it('runs the scheduled event and records a manual run with the user', function () {
    $user = User::factory()->create();

    (new RunScheduledTask('test:manual', $user->id))->handle();

    $run = ScheduledTaskRun::query()->sole();
    expect($this->ran)->toBe(1)
        ->and($run->status)->toBe(TaskRunStatus::Success)
        ->and($run->trigger)->toBe(TaskRunTrigger::Manual)
        ->and($run->user_id)->toBe($user->id)
        ->and($run->duration_ms)->not->toBeNull();
});

it('records skipped and does not run when the overlap mutex is held', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'test:manual');
    $event->mutex->create($event);

    (new RunScheduledTask('test:manual', null))->handle();

    expect($this->ran)->toBe(0)
        ->and(ScheduledTaskRun::query()->sole()->status)->toBe(TaskRunStatus::Skipped);
});

it('re-validates the key in handle()', function (string $key) {
    app(Schedule::class)->call(function () {
        $this->ran++;
    })->name('test:other');

    (new RunScheduledTask($key, null))->handle();

    expect($this->ran)->toBe(0)->and(ScheduledTaskRun::query()->count())->toBe(0);
})->with(['not allow-listed' => ['test:other'], 'tampered' => ['test:manual --x'], 'unknown' => ['nope']]);

it('records a throwing task as failed with a redacted error', function () {
    config(['monitoring.manual_tasks' => ['test:manual', 'test:boom']]);
    app(Schedule::class)->call(fn () => throw new RuntimeException('failed for ops@poson.test'))->name('test:boom');

    (new RunScheduledTask('test:boom', null))->handle();

    $run = ScheduledTaskRun::query()->sole();
    expect($run->status)->toBe(TaskRunStatus::Failed)->and($run->error)->toBe('failed for [email]');
});

it('records skipped when it loses the mutex race, even with a stale exit code', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'test:manual');
    $event->mutex = new class implements EventMutex
    {
        public function create(Event $event): bool
        {
            return false;
        }

        public function exists(Event $event): bool
        {
            return false;
        }

        public function forget(Event $event): void {}
    };
    $event->exitCode = 1;

    (new RunScheduledTask('test:manual', null))->handle();

    $run = ScheduledTaskRun::query()->sole();
    expect($this->ran)->toBe(0)
        ->and($run->status)->toBe(TaskRunStatus::Skipped)
        ->and($run->output)->toBeNull()
        ->and($run->error)->toBeNull();
});

it('records skipped with not_scheduled and reports when the task left the schedule', function () {
    config(['monitoring.manual_tasks' => ['test:manual', 'test:gone']]);
    Exceptions::fake();

    (new RunScheduledTask('test:gone', null))->handle();

    $run = ScheduledTaskRun::query()->sole();
    expect($run->status)->toBe(TaskRunStatus::Skipped)->and($run->error)->toBe('not_scheduled');
    Exceptions::assertReported(RuntimeException::class);
});
