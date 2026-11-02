<?php

use App\Enums\TaskRunStatus;
use App\Models\MonitoringState;
use App\Models\ScheduledTaskRun;
use App\Support\Monitoring\Heartbeat;
use App\Support\Monitoring\InterruptedRuns;
use App\Support\Monitoring\MonitoredTasks;
use App\Support\Monitoring\ProblemMonitor;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const MON_CHAT = '-1009999999999';

function monitoringTelegram(bool $configured = true): void
{
    freshSecrets($configured ? [
        'services.telegram.bot_token' => '123456789:'.str_repeat('M', 35),
        'services.telegram.chats.management' => MON_CHAT,
    ] : []);

    monitoringResponses([]);
    Http::fake(['api.telegram.org/*' => fn () => monitoringResponses() ?? Http::response(['ok' => true, 'result' => []])]);
}

/**
 * Queue of canned Telegram responses (the first Http::fake() wins, so tests queue instead of re-faking).
 *
 * @param  list<PromiseInterface>|null  $queue
 */
function monitoringResponses(?array $queue = null): mixed
{
    static $responses = [];

    if ($queue !== null) {
        $responses = $queue;

        return null;
    }

    return array_shift($responses);
}

/**
 * @return list<string>
 */
function monitoringTexts(): array
{
    return Http::recorded()
        ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), 'api.telegram.org'))
        ->map(fn (array $pair): string => (string) ($pair[0]->data()['text'] ?? ''))
        ->values()->all();
}

function freshHeartbeats(): void
{
    Heartbeat::tick(Heartbeat::SCHEDULER);
    Heartbeat::tick(Heartbeat::WORKER);
}

function addJob(int $availableAgo, ?int $reservedAgo = null): void
{
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => $reservedAgo === null ? null : now()->subSeconds($reservedAgo)->getTimestamp(),
        'available_at' => now()->subSeconds($availableAgo)->getTimestamp(),
        'created_at' => now()->subSeconds($availableAgo)->getTimestamp(),
    ]);
}

beforeEach(fn () => monitoringTelegram());

it('alerts once for a failed task, reminds after an hour and sends recovered', function () {
    freshHeartbeats();
    ScheduledTaskRun::factory()->failed('Boom ops@poson.test')->create(['task' => 'quotes:expire']);

    $monitor = new ProblemMonitor;
    $monitor->evaluate();
    $monitor->evaluate();

    expect(monitoringTexts())->toHaveCount(1)->and(monitoringTexts()[0])->toContain('quotes:expire')->not->toContain('ops@poson.test');

    $this->travel(61)->minutes();
    freshHeartbeats();
    $monitor->evaluate();

    expect(monitoringTexts())->toHaveCount(2)->and(monitoringTexts()[1])->toContain('quotes:expire');

    ScheduledTaskRun::factory()->create(['task' => 'quotes:expire', 'started_at' => now()]);
    $monitor->evaluate();
    $monitor->evaluate();

    expect(monitoringTexts())->toHaveCount(3);
    expect(MonitoringState::query()->where('key', 'problem:task:'.md5('quotes:expire'))->first())
        ->problem_since->toBeNull()->alerted_at->toBeNull();
});

it('does not alert for a task whose latest run succeeded', function () {
    freshHeartbeats();
    ScheduledTaskRun::factory()->failed()->create(['task' => 'mcp:sweep', 'started_at' => now()->subHour()]);
    ScheduledTaskRun::factory()->create(['task' => 'mcp:sweep', 'started_at' => now()]);

    (new ProblemMonitor)->evaluate();

    expect(monitoringTexts())->toBeEmpty();
});

it('alerts once for a dead scheduler, reminds and recovers', function () {
    Heartbeat::tick(Heartbeat::SCHEDULER);
    $this->travel(6)->minutes();

    $monitor = new ProblemMonitor;
    $monitor->evaluateCronDead();
    $monitor->evaluateCronDead();
    expect(monitoringTexts())->toHaveCount(1);

    $this->travel(61)->minutes();
    $monitor->evaluateCronDead();
    expect(monitoringTexts())->toHaveCount(2);

    Heartbeat::tick(Heartbeat::SCHEDULER);
    $monitor->evaluateCronDead();
    $monitor->evaluateCronDead();
    expect(monitoringTexts())->toHaveCount(3);
});

it('alerts for a stuck queue but not for delayed or reserved jobs', function () {
    freshHeartbeats();
    addJob(availableAgo: -3600);
    addJob(availableAgo: 3600, reservedAgo: 100);

    $monitor = new ProblemMonitor;
    $monitor->evaluateQueue();
    expect(monitoringTexts())->toBeEmpty();

    addJob(availableAgo: 601);
    $monitor->evaluateQueue();
    $monitor->evaluateQueue();
    expect(monitoringTexts())->toHaveCount(1);

    DB::table('jobs')->delete();
    $monitor->evaluateQueue();
    expect(monitoringTexts())->toHaveCount(2);
});

it('alerts when the worker is silent while the scheduler is alive', function () {
    Heartbeat::tick(Heartbeat::WORKER);
    $this->travel(6)->minutes();
    Heartbeat::tick(Heartbeat::SCHEDULER);

    (new ProblemMonitor)->evaluate();
    (new ProblemMonitor)->evaluate();

    expect(monitoringTexts())->toHaveCount(1);

    Heartbeat::tick(Heartbeat::WORKER);
    (new ProblemMonitor)->evaluate();
    expect(monitoringTexts())->toHaveCount(2);
});

it('suppresses worker_silent while cron_dead is open', function () {
    Heartbeat::tick(Heartbeat::SCHEDULER);
    Heartbeat::tick(Heartbeat::WORKER);
    $this->travel(10)->minutes();

    (new ProblemMonitor)->evaluate();

    expect(monitoringTexts())->toHaveCount(1)
        ->and(MonitoringState::query()->whereKey('problem:worker_silent')->exists())->toBeFalse();
});

it('sends nothing without a bot or a management chat', function () {
    monitoringTelegram(configured: false);
    Heartbeat::tick(Heartbeat::SCHEDULER);
    $this->travel(10)->minutes();

    (new ProblemMonitor)->evaluate();

    Http::assertNothingSent();
});

it('retries a failed send at most every 5 minutes', function () {
    monitoringResponses([Http::response(['ok' => false], 500)]);
    Heartbeat::tick(Heartbeat::SCHEDULER);
    $this->travel(6)->minutes();

    $monitor = new ProblemMonitor;
    $monitor->evaluateCronDead();
    $monitor->evaluateCronDead();
    expect(Http::recorded())->toHaveCount(1);

    $this->travel(2)->minutes();
    $monitor->evaluateCronDead();
    expect(Http::recorded())->toHaveCount(1);

    $this->travel(4)->minutes();
    $monitor->evaluateCronDead();

    expect(Http::recorded())->toHaveCount(2)
        ->and(MonitoringState::query()->find('problem:cron_dead')->alerted_at)->not->toBeNull();
});

it('redacts a fake secret out of the alert body and escapes HTML', function () {
    freshHeartbeats();
    ScheduledTaskRun::factory()->failed('<b>x</b> password=FAKE-SECRET-123 token 123456789:'.str_repeat('Z', 35))->create(['task' => 'mcp:sweep']);

    (new ProblemMonitor)->evaluate();

    $text = monitoringTexts()[0];
    expect($text)->not->toContain('FAKE-SECRET-123')->not->toContain(str_repeat('Z', 35))->not->toContain('<b>x</b>')->toContain('&lt;b&gt;');
});

it('writes the schedule snapshot', function () {
    Artisan::call('schedule:list');
    MonitoredTasks::writeSnapshot();

    $tasks = MonitoringState::query()->findOrFail(MonitoredTasks::SNAPSHOT_KEY)->meta['tasks'];

    expect($tasks)->toHaveKeys(['quotes:expire', 'uzqr:sync'])->not->toHaveKey('monitoring:check')
        ->and($tasks['quotes:expire'])->toMatchArray(['expression' => '40 0 * * *', 'overlap' => true, 'overlap_minutes' => 30, 'runnable' => true])
        ->and($tasks['quotes:expire']['next_run'])->not->toBeNull()
        ->and($tasks['uzqr:sync']['runnable'])->toBeFalse();
});

it('marks old running rows as interrupted using twice the overlap', function () {
    Artisan::call('schedule:list');
    MonitoredTasks::writeSnapshot();

    $stale = ScheduledTaskRun::factory()->running()->create(['task' => 'quotes:expire', 'started_at' => now()->subMinutes(61)]);
    $long = ScheduledTaskRun::factory()->running()->create(['task' => 'quotes:expire', 'started_at' => now()->subMinutes(59)]);
    $fresh = ScheduledTaskRun::factory()->running()->create(['task' => 'quotes:expire', 'started_at' => now()->subMinutes(5)]);

    expect(InterruptedRuns::sweep())->toBe(1)
        ->and($stale->refresh()->status)->toBe(TaskRunStatus::Failed)
        ->and($stale->error)->toBe('interrupted')
        ->and($long->refresh()->status)->toBe(TaskRunStatus::Running)
        ->and($fresh->refresh()->status)->toBe(TaskRunStatus::Running);
});

it('runs monitoring:check end to end without recording itself', function () {
    Http::fake(['hc-ping.com/*' => Http::response('OK'), 'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    freshSecrets(['services.monitoring.ping_url' => 'https://hc-ping.com/11111111-2222-3333-4444-555555555555']);

    $this->artisan('monitoring:check')->assertSuccessful();

    expect(Heartbeat::age(Heartbeat::SCHEDULER))->toBeLessThan(5)
        ->and(MonitoringState::query()->whereKey(MonitoredTasks::SNAPSHOT_KEY)->exists())->toBeTrue()
        ->and(Heartbeat::lastTick(Heartbeat::WORKER))->not->toBeNull()
        ->and(ScheduledTaskRun::query()->count())->toBe(0);
    Http::assertSent(fn ($request) => $request->url() === 'https://hc-ping.com/11111111-2222-3333-4444-555555555555');
});

it('applies the retry interval when Telegram is not configured', function () {
    monitoringTelegram(configured: false);
    Heartbeat::tick(Heartbeat::SCHEDULER);
    $this->travel(6)->minutes();

    $monitor = new ProblemMonitor;
    $monitor->evaluateCronDead();
    $first = MonitoringState::query()->find('problem:cron_dead')->meta['skipped_at'];

    $this->travel(2)->minutes();
    $monitor->evaluateCronDead();
    expect(MonitoringState::query()->find('problem:cron_dead')->meta['skipped_at'])->toBe($first);

    monitoringTelegram();
    $this->travel(4)->minutes();
    $monitor->evaluateCronDead();

    expect(monitoringTexts())->toHaveCount(1);
});

it('gives a recurrence a fresh first alert even when the recovered send failed', function () {
    Heartbeat::tick(Heartbeat::SCHEDULER);
    $this->travel(6)->minutes();
    $monitor = new ProblemMonitor;
    $monitor->evaluateCronDead();
    expect(monitoringTexts())->toHaveCount(1);

    monitoringResponses([Http::response(['ok' => false], 500)]);
    Heartbeat::tick(Heartbeat::SCHEDULER);
    $monitor->evaluateCronDead();
    expect(monitoringTexts())->toHaveCount(2)
        ->and(MonitoringState::query()->find('problem:cron_dead')->problem_since)->toBeNull();

    $this->travel(6)->minutes();
    $monitor->evaluateCronDead();

    expect(monitoringTexts())->toHaveCount(3);
});
