<?php

use App\Support\Monitoring\CronWatchdog;
use App\Support\Monitoring\Heartbeat;
use App\Support\Monitoring\ProblemMonitor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    CronWatchdog::reset();
    CronWatchdog::$enabled = true;
});

afterEach(function () {
    CronWatchdog::$enabled = false;
    CronWatchdog::reset();
});

it('touches the cache at most once per process per minute', function () {
    freshSecrets(['services.telegram.bot_token' => '123456789:'.str_repeat('M', 35), 'services.telegram.chats.management' => '-1009999999999']);
    Cache::shouldReceive('add')->once()->with('monitoring:watchdog', 1, 60)->andReturn(false);

    foreach (range(1, 100) as $_) {
        CronWatchdog::maybeCheck();
    }
});

it('samples web requests so PHP-FPM processes rarely reach the cache', function () {
    freshSecrets(['services.telegram.bot_token' => '123456789:'.str_repeat('M', 35), 'services.telegram.chats.management' => '-1009999999999']);
    $calls = 0;
    CronWatchdog::$sampler = function () use (&$calls): bool {
        return ++$calls % 20 === 0;
    };
    // Simulate web requests: not the console, a fresh process (gate reset) every time.
    CronWatchdog::$console = false;
    Cache::shouldReceive('add')->times(5)->andReturn(false);

    foreach (range(1, 100) as $_) {
        CronWatchdog::reset();
        CronWatchdog::maybeCheck();
    }

    CronWatchdog::$sampler = null;
    CronWatchdog::$console = null;
});

it('does not touch the cache when Telegram is not configured', function () {
    freshSecrets();
    Cache::shouldReceive('add')->never();

    CronWatchdog::maybeCheck();
});

it('does nothing in tests unless enabled', function () {
    CronWatchdog::$enabled = false;
    Cache::shouldReceive('add')->never();

    CronWatchdog::maybeCheck();
});

it('alerts about a dead scheduler once', function () {
    freshSecrets(['services.telegram.bot_token' => '123456789:'.str_repeat('M', 35), 'services.telegram.chats.management' => '-1009999999999']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    Heartbeat::tick(Heartbeat::SCHEDULER);
    $this->travel(10)->minutes();

    CronWatchdog::maybeCheck();
    $this->travel(2)->minutes();
    CronWatchdog::maybeCheck();

    expect(Http::recorded())->toHaveCount(1);
});

it('stays quiet when Telegram is not configured', function () {
    freshSecrets();
    Http::fake();
    Heartbeat::tick(Heartbeat::SCHEDULER);
    $this->travel(10)->minutes();

    CronWatchdog::maybeCheck();

    Http::assertNothingSent();
});

it('treats a scheduler that never ticked as dead only after the grace period', function () {
    freshSecrets(['services.telegram.bot_token' => '123456789:'.str_repeat('M', 35), 'services.telegram.chats.management' => '-1009999999999']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

    $monitor = new ProblemMonitor;
    $monitor->evaluateCronDead();
    $this->travel(9)->minutes();
    $monitor->evaluateCronDead();
    Http::assertNothingSent();

    $this->travel(2)->minutes();
    $monitor->evaluateCronDead();
    $monitor->evaluateCronDead();

    expect(Http::recorded())->toHaveCount(1);
});

it('never raises the never-ticked alarm in local development', function () {
    freshSecrets(['services.telegram.bot_token' => '123456789:'.str_repeat('M', 35), 'services.telegram.chats.management' => '-1009999999999']);
    Http::fake();
    $this->app['env'] = 'local';

    (new ProblemMonitor)->evaluateCronDead();
    $this->travel(60)->minutes();
    (new ProblemMonitor)->evaluateCronDead();

    Http::assertNothingSent();
});
