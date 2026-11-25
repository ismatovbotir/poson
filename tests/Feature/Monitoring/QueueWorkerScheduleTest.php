<?php

use App\Enums\Role;
use App\Jobs\MeasureProjectSize;
use App\Jobs\RecordWorkerHeartbeat;
use App\Jobs\RunScheduledTask;
use App\Livewire\Admin\System\Monitoring;
use App\Models\MonitoringState;
use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Support\Monitoring\Heartbeat;
use App\Support\Monitoring\MonitoredTasks;
use App\Support\Monitoring\WorkerQueues;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Livewire\Livewire;

function queueWorkerEvent(): Event
{
    $event = collect(app(Schedule::class)->events())->first(fn (Event $e): bool => $e->description === 'queue-worker');
    expect($event)->not->toBeNull();

    return $event;
}

it('schedules the queue worker with the agreed options (QW-2)', function () {
    $event = queueWorkerEvent();

    expect($event->command)->toContain('queue:work', 'database', '--max-time=40', '--sleep=2', '--tries=3', '--timeout=60', '--memory=128')
        ->and($event->command)->not->toContain('--stop-when-empty')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(2)
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->runInBackground)->toBeFalse()
        ->and($event->expression)->toBe('* * * * *');
});

it('puts the queue worker after the heartbeat, monitor and uzqr events (QW-1)', function () {
    $events = collect(app(Schedule::class)->events())->values();
    $position = fn (callable $match): int => $events->search($match);

    $worker = $position(fn (Event $e): bool => $e->description === 'queue-worker');
    $heartbeat = $position(fn (Event $e): bool => str_contains((string) $e->description, RecordWorkerHeartbeat::class));
    $monitor = $position(fn (Event $e): bool => str_contains((string) $e->command, 'monitoring:check'));
    $uzqr = $position(fn (Event $e): bool => str_contains((string) $e->command, 'uzqr:sync'));

    expect($worker)->toBeGreaterThan($heartbeat)->toBeGreaterThan($monitor)->toBeGreaterThan($uzqr)
        ->and($worker)->toBe($events->count() - 1);
});

it('builds the worker queue list from config with knowledge last (QW-3)', function () {
    expect(WorkerQueues::list())->toBe('default,monitoring,ai-admin,knowledge');

    config(['monitoring.queue' => 'mon-x', 'chatbot.ai.product.queue' => 'ai-x', 'chatbot.ai.knowledge.queue' => 'kn-x']);

    expect(WorkerQueues::list())->toBe('default,mon-x,ai-x,kn-x');
    expect(queueWorkerEvent()->command)->toContain("--queue='default,monitoring,ai-admin,knowledge'");
});

it('keeps retry_after well above every job and worker timeout (QW-4)', function () {
    $largest = max(
        RunScheduledTask::TIMEOUT,
        MeasureProjectSize::TIMEOUT,
        (int) config('chatbot.ai.product.vision.job_timeout'),
        (int) config('chatbot.ai.product.write.job_timeout'),
        (int) config('chatbot.ai.knowledge.job_timeout'),
        60,
    );

    expect(config('queue.connections.database.retry_after'))->toBeGreaterThanOrEqual($largest + 60)->toBe(660);
});

it('does not record the queue worker in run history and does not make it manual (QW-5)', function () {
    $event = queueWorkerEvent();

    expect(MonitoredTasks::isRecorded($event))->toBeFalse()
        ->and(config('monitoring.manual_tasks'))->not->toContain('queue-worker');

    event(new ScheduledTaskStarting($event));
    event(new ScheduledTaskFinished($event, 1.0));

    expect(ScheduledTaskRun::query()->count())->toBe(0);
});

it('puts the pcntl flag into the worker heartbeat tick (QW-6)', function () {
    (new RecordWorkerHeartbeat)->handle();

    $meta = MonitoringState::query()->whereKey(Heartbeat::WORKER)->firstOrFail()->meta;

    expect($meta)->toHaveKey('pcntl')->and($meta['pcntl'])->toBe(extension_loaded('pcntl'));
});

it('shows the pcntl warning on Monitoring only when pcntl is missing (QW-6)', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin->value);
    $this->actingAs($admin);

    Heartbeat::tick(Heartbeat::WORKER, ['lag_ms' => 10, 'pcntl' => false]);
    Livewire::test(Monitoring::class)->assertSeeHtml('data-pcntl-warning');

    Heartbeat::tick(Heartbeat::WORKER, ['lag_ms' => 10, 'pcntl' => true]);
    Livewire::test(Monitoring::class)->assertDontSeeHtml('data-pcntl-warning')->assertSeeHtml('data-worker-hint');
});

it('has the worker hint keys in all locales (QW-7)', function (string $locale) {
    foreach (['monitoring.worker_hint', 'monitoring.pcntl_warning'] as $key) {
        expect(__($key, [], $locale))->not->toBe($key);
    }

    expect(__('monitoring.worker_hint', [], $locale))->toContain('queue:work', 'schedule:run', 'pcntl', '660');
})->with(['uz', 'ru', 'en']);
