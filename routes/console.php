<?php

use App\Jobs\RecordWorkerHeartbeat;
use App\Models\ChatUnmatched;
use App\Models\DiskSnapshot;
use App\Models\McpCall;
use App\Models\MonitoringAction;
use App\Models\PriceSyncLog;
use App\Models\ScheduledTaskRun;
use App\Models\TelegramUpdate;
use App\Models\TelegramUser;
use App\Support\Monitoring\WorkerQueues;
use Illuminate\Console\Scheduling\CallbackEvent;
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
// Plan 40 (YD-8): Yandex click ids on leads / orders are cleared after 12 months.
Schedule::command('analytics:prune-click-ids')->dailyAt('01:15')->timezone($analyticsTimezone)->withoutOverlapping(10)->onOneServer();
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
| Chat bot (plan 28, CB-10)
|--------------------------------------------------------------------------
| Open "unmatched" phrases not seen for ChatUnmatched::KEEP_DAYS (180) days are deleted.
*/

Schedule::command('model:prune', ['--model' => [ChatUnmatched::class]])->dailyAt('02:00')->timezone('Asia/Tashkent')->withoutOverlapping(30)->onOneServer();

// Plan 30 (AI-21, AI-B12): AI conversation turns older than 30 days are deleted.
Schedule::command('chat:prune-ai')->dailyAt('03:25')->timezone('Asia/Tashkent')->withoutOverlapping(30)->onOneServer();

/*
|--------------------------------------------------------------------------
| Careers (plan 31, CR-5)
|--------------------------------------------------------------------------
| Hourly: CVs waiting > 24 h for the Telegram forward expire (file deleted,
| text-only message), stuck "sending" rows are queued again, orphan pending
| files are deleted. Applications themselves are never pruned (§17.4).
*/
Schedule::command('careers:expire-pending-cvs')->hourly()->withoutOverlapping(30)->onOneServer();

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
| Media clean-up (fix batch media-cleanup)
|--------------------------------------------------------------------------
| catalog:purge-trash: products in the trash for more than 30 days are
| deleted for good, with their files. uploads:purge-tmp: Livewire temporary
| uploads older than 24 h. media:clean-temp: stale media-library temp folders
| older than 24 h. Task keys: "catalog:purge-trash --force", "uploads:purge-tmp"
| and "media:clean-temp".
*/

Schedule::command('catalog:purge-trash', ['--force'])->dailyAt('02:15')->timezone('Asia/Tashkent')->withoutOverlapping(30)->onOneServer();
Schedule::command('uploads:purge-tmp')->dailyAt('02:20')->timezone('Asia/Tashkent')->withoutOverlapping(30)->onOneServer();
Schedule::command('media:clean-temp')->dailyAt('02:25')->timezone('Asia/Tashkent')->withoutOverlapping(30)->onOneServer();

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

/*
|--------------------------------------------------------------------------
| Monitoring (plan 17)
|--------------------------------------------------------------------------
| Worker heartbeat every minute (a queue worker must pick it up). Run
| history (14 days) and the admin audit (365 days) are pruned daily.
| No event may use runInBackground(): RunRecorder needs the start and end
| events in the same process (guarded by a test).
*/

Schedule::command('monitoring:check')->everyMinute()->withoutOverlapping(5)->onOneServer();
Schedule::job(new RecordWorkerHeartbeat)->everyMinute();
Schedule::command('model:prune', ['--model' => [ScheduledTaskRun::class, MonitoringAction::class]])->dailyAt('02:50')->timezone('Asia/Tashkent')->withoutOverlapping(30)->onOneServer();

/*
| Plan 37: project size snapshots older than 400 days are pruned (own line, DM-18).
| The measurement itself has NO schedule: an Admin starts it on Admin → Monitoring
| ("Measure now"). To run it weekly later, add
| Schedule::job(new MeasureProjectSize)->weeklyOn(0, '04:30')->timezone('Asia/Tashkent');
| (the job claims the run slot itself; it is not in monitoring.manual_tasks).
*/
Schedule::command('model:prune', ['--model' => [DiskSnapshot::class]])->dailyAt('02:55')->timezone('Asia/Tashkent')->withoutOverlapping(30)->onOneServer();

/*
|--------------------------------------------------------------------------
| IndexNow (plan 18)
|--------------------------------------------------------------------------
| Every 5 minutes: sitemap diff + changed records go to Bing / Yandex as one
| queued batch. Does nothing unless IndexNow is active (production, https,
| enabled, key, indexing open). No runInBackground() (RunRecorder rule).
*/

Schedule::command('indexnow:flush')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();

/*
|--------------------------------------------------------------------------
| Telegram bot (plan 14 v2, TG-38)
|--------------------------------------------------------------------------
| Every 15 minutes: webhook health (URL, delivery errors, backlog, token)
| with alerts to the management chat. Short HTTP timeout inside (5 s).
*/

Schedule::command('telegram:check-webhook')->everyFifteenMinutes()->withoutOverlapping(5)->onOneServer();

/*
|--------------------------------------------------------------------------
| Chat bot AI website knowledge (plan 33 K-10)
|--------------------------------------------------------------------------
| Hourly incremental catch-up of ai_knowledge_chunks with the public pages,
| news, brands and services (saves are indexed by jobs on the "knowledge"
| queue). Foreground, about 20 s budget, counts-only logs. No runInBackground().
*/

Schedule::command('chat:index-knowledge')->hourly()->withoutOverlapping(10)->onOneServer();

/*
|--------------------------------------------------------------------------
| Telegram retention (plan 21 B3, TG-27 amended), Tashkent time
|--------------------------------------------------------------------------
| Daily: model:prune for TelegramUser (unregistered visitors after 30 days,
| threads silent for 12 months; threads with a lead-linked message and their
| customer rows are kept; activities go with the thread) and TelegramUpdate
| (dedupe rows after 3 days). telegram:scrub-banned strips banned users past
| retention (T21-34). No Telegram call: copies in the staff chat
| are not removed by retention. queue:prune-failed --hours=720 keeps failed
| jobs 30 days (their payloads can hold customer excerpts; provisional owner
| decision). Task keys: "model:prune --model=App\Models\TelegramUser --model=App\Models\TelegramUpdate"
| and "queue:prune-failed --hours=720".
*/

Schedule::command('model:prune', ['--model' => [TelegramUser::class, TelegramUpdate::class]])->dailyAt('03:00')->timezone('Asia/Tashkent')->withoutOverlapping(30)->onOneServer();
Schedule::command('chat:prune')->dailyAt('03:15')->timezone('Asia/Tashkent')->withoutOverlapping(30)->onOneServer();
Schedule::command('telegram:scrub-banned')->dailyAt('03:10')->timezone('Asia/Tashkent')->withoutOverlapping(30)->onOneServer();
Schedule::command('queue:prune-failed', ['--hours' => 720])->dailyAt('03:20')->timezone('Asia/Tashkent')->withoutOverlapping(30)->onOneServer();

/*
|--------------------------------------------------------------------------
| AI product wizard (plan 32, PW-28)
|--------------------------------------------------------------------------
| Hourly: expire drafts past their TTL, delete vision copies after an hour,
| folders of expired drafts and orphans, rows after 7 days.
*/

Schedule::command('ai:prune-drafts')->hourly()->withoutOverlapping(30)->onOneServer();

/*
|--------------------------------------------------------------------------
| Home page cache (plan 34, HC-11)
|--------------------------------------------------------------------------
| The database cache store never deletes expired rows (versioned home page
| keys pile up), so they are pruned daily. Deploy step: `php artisan site:home-cache-clear`.
*/

Schedule::command('cache:prune-expired')->dailyAt('04:05')->timezone('Asia/Tashkent')->withoutOverlapping(30)->onOneServer();

/*
|--------------------------------------------------------------------------
| Virtual office (plan 41), Tashkent time
|--------------------------------------------------------------------------
| Every minute: queue the due analyses of Telegram Business client chats
| (nothing while the office AI is off; at most 3 pending at once). Nightly:
| retention (unmarked stubs 14 days, client chats 12 months, 5 analyses per
| chat, drafts 30 days, strangers' connections 30 days), counts-only log.
*/

Schedule::command('office:analyse-due')->everyMinute()->withoutOverlapping(5)->onOneServer();
Schedule::command('office:prune')->dailyAt('03:30')->timezone('Asia/Tashkent')->withoutOverlapping(30)->onOneServer();

/*
|--------------------------------------------------------------------------
| Mini App catalog cache (plan 45 B)
|--------------------------------------------------------------------------
| Every 6 hours: the unfiltered filter facets of every visible category ×
| locale (a few seconds). Shares a lock with the admin "Rebuild" button.
| Must stay before the queue worker (MA-53).
*/

Schedule::command('tma:warm-catalog')->everySixHours()->withoutOverlapping(10)->onOneServer();

/*
| Queue worker from the scheduler (shared hosting: one cron line, `schedule:run`).
| Runs about 40 s each minute in the foreground (no runInBackground(): RunRecorder
| rule), no --stop-when-empty. Must stay the LAST Schedule:: line: it blocks the
| events after it for those 40 s. Not recorded in scheduled_task_runs
| (monitoring.unrecorded_tasks). The queue list is built in WorkerQueues.
*/
Schedule::command('queue:work', ['database', '--queue' => WorkerQueues::list(), '--max-time' => 40, '--sleep' => 2, '--tries' => 3, '--timeout' => 60, '--memory' => 128])
    ->everyMinute()->withoutOverlapping(2)->onOneServer()->name('queue-worker');

/*
| Keep the last output of every command (overwritten each run, never
| appended; stays in storage/logs) so RunRecorder can store the redacted
| tail of a failed run (MON-S11). Must stay the last statement here.
*/

foreach (Schedule::events() as $scheduledEvent) {
    if (! $scheduledEvent instanceof CallbackEvent && in_array($scheduledEvent->output, ['/dev/null', 'NUL'], true)) {
        $scheduledEvent->storeOutput();
    }
}
