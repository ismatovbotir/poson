<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Traffic analytics (plan 04 4c), times in the analytics timezone
|--------------------------------------------------------------------------
| Today is re-aggregated every 15 minutes. Nightly: verify the last 3 days
| of crawler hits (downloading stale IP ranges first) and re-aggregate
| them, then prune. Weekly: refresh the published crawler IP ranges.
| Deploy step: `php artisan analytics:refresh-bot-ranges` once.
*/

$analyticsTimezone = config('analytics.timezone', 'Asia/Tashkent');

Schedule::command('analytics:aggregate')->everyFifteenMinutes()->withoutOverlapping(10)->onOneServer();
Schedule::command('analytics:verify-bots')->dailyAt('00:20')->timezone($analyticsTimezone)->withoutOverlapping(10)->onOneServer();
Schedule::command('analytics:prune')->dailyAt('01:10')->timezone($analyticsTimezone)->withoutOverlapping(10)->onOneServer();
Schedule::command('analytics:refresh-bot-ranges')->weeklyOn(1, '03:30')->timezone($analyticsTimezone)->withoutOverlapping(10)->onOneServer();

/*
|--------------------------------------------------------------------------
| Quotes (plan 06), Tashkent time
|--------------------------------------------------------------------------
| Sent quotes past valid_until become expired, with a history entry.
*/

Schedule::command('quotes:expire')->dailyAt('00:40')->timezone('Asia/Tashkent')->withoutOverlapping(30)->onOneServer();
