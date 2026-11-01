<?php

use App\Models\McpCall;
use App\Models\PriceSyncLog;
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

/*
|--------------------------------------------------------------------------
| Price API (plan 09)
|--------------------------------------------------------------------------
| Sync log rows older than PriceSyncLog::KEEP_DAYS (180) days are deleted.
*/

Schedule::command('model:prune', ['--model' => [PriceSyncLog::class]])->dailyAt('01:50')->timezone('Asia/Tashkent')->withoutOverlapping(30)->onOneServer();

/*
|--------------------------------------------------------------------------
| Cart (plan 11a)
|--------------------------------------------------------------------------
| Empty carts after 1 day, converted ones 1 day after the order, the rest
| after 30 days untouched.
*/

Schedule::command('carts:prune')->dailyAt('02:10')->timezone('Asia/Tashkent')->withoutOverlapping(30)->onOneServer();

/*
|--------------------------------------------------------------------------
| Orders (plan 11b)
|--------------------------------------------------------------------------
| Orders left "pending" after their Payme / Click hold ended (Payme state 1
| older than 12 h, Click prepare older than 60 min) go back to unpaid.
*/

Schedule::command('orders:release-holds')->everyTenMinutes()->withoutOverlapping(10)->onOneServer();

/*
|--------------------------------------------------------------------------
| Dynamic UzQR (plan 11e)
|--------------------------------------------------------------------------
| The bank has no callbacks: due invoices are checked every minute (backoff
| by age, expiry handling, stale "creating" rows closed). ≤ 100 per run.
*/

Schedule::command('uzqr:sync')->everyMinute()->withoutOverlapping(5)->onOneServer();

/*
|--------------------------------------------------------------------------
| MCP (plan 13), Tashkent time
|--------------------------------------------------------------------------
| Daily: revoke MCP tokens unused for their idle days (default 45) or never
| used within 7 days, and remind about tokens older than 180 days. Hourly:
| revoke access of users who are no longer active Admins (role events are
| off). mcp_calls rows older than 365 days are pruned.
*/

Schedule::command('mcp:revoke-idle')->dailyAt('03:10')->timezone('Asia/Tashkent')->withoutOverlapping(30)->onOneServer();
Schedule::command('mcp:sweep')->hourly()->withoutOverlapping(10)->onOneServer();
Schedule::command('model:prune', ['--model' => [McpCall::class]])->dailyAt('02:30')->timezone('Asia/Tashkent')->withoutOverlapping(30)->onOneServer();
