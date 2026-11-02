<?php

namespace App\Support\Monitoring;

use Closure;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * Notices a dead cron when no monitoring:check runs (plan 17, MON-S7). Hooked
 * into the end of every web request and every queue worker loop; evaluates
 * cron_dead only.
 *
 * Cost control: an in-process timestamp gate lets one call per minute reach
 * Cache::add('monitoring:watchdog'), which in turn lets one call per minute
 * across processes reach the database. On web requests the check runs in the
 * terminating callback (after the response) with a 3 s Telegram timeout.
 * Off in tests unless $enabled is set, and while the bot / management chat
 * aren't configured.
 */
final class CronWatchdog
{
    public const CACHE_KEY = 'monitoring:watchdog';

    public static bool $enabled = false;

    private static int $lastAttempt = 0;

    public static function register(): void
    {
        app()->terminating(fn () => self::maybeCheck());
        Event::listen(Looping::class, fn () => self::maybeCheck());
    }

    public static function reset(): void
    {
        self::$lastAttempt = 0;
    }

    /**
     * Web requests are sampled: under PHP-FPM every request is a fresh process,
     * so the static gate below cannot throttle anything there. One request in
     * WEB_SAMPLE reaches Cache::add (itself one write per minute), which keeps
     * the cost per request near zero. Queue workers are long-lived and use the
     * static gate. Replaceable in tests.
     *
     * @var (Closure(): bool)|null
     */
    public static ?Closure $sampler = null;

    public const WEB_SAMPLE = 20;

    /**
     * Test seam: pretend to be a web request (false) or a worker (true).
     */
    public static ?bool $console = null;

    public static function maybeCheck(): void
    {
        if (app()->runningUnitTests() && ! self::$enabled) {
            return;
        }

        if (! self::console() && ! (self::$sampler ? (self::$sampler)() : random_int(1, self::WEB_SAMPLE) === 1)) {
            return;
        }

        $now = now()->getTimestamp();

        if ($now - self::$lastAttempt < 60) {
            return;
        }

        self::$lastAttempt = $now;

        try {
            if (! MonitoringAlerts::isConfigured() || ! Cache::add(self::CACHE_KEY, 1, 60)) {
                return;
            }

            ProblemMonitor::using(self::console() ? 5 : 3)->evaluateCronDead();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private static function console(): bool
    {
        return self::$console ?? app()->runningInConsole();
    }
}
