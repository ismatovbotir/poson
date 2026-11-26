<?php

namespace App\Jobs;

use App\Actions\Office\PurgeGroupData;
use App\Enums\ActivityType;
use App\Enums\GroupStatus;
use App\Enums\LeadSource;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\TelegramGroup;
use App\Support\Monitoring\MonitoringAlerts;
use App\Support\Office\OfficeLock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One chunk of "Delete all business data" for Telegram groups (plan 44,
 * OG-12): up to CHUNK groups with an id up to $cutoffId are emptied, each
 * under its group lock (a running analysis can no longer save): phones
 * nulled, messages, threads, analyses and members deleted in chunks, then
 * the row is reduced to a stub (untracked, or still ignored; title,
 * username, assignee, customer and tracking audit cleared, counters kept).
 * Stubs are kept, not deleted: the counters and the ignored mark survive,
 * and retention removes untracked stubs after 30 days. Leads are never
 * touched. The job queues itself again while groups remain (MAX_ROUNDS),
 * a locked group is retried in the next round. Counts-only log.
 */
class WipeGroupDataChunk implements ShouldQueue
{
    use Queueable;

    public const CHUNK = 10;

    /** Seconds after which a round stops taking new groups (below the timeout). */
    public const BUDGET = 80;

    public const MAX_ROUNDS = 500;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public int $cutoffId = 0, public int $round = 1)
    {
        $this->onQueue('default');
    }

    public static function forNow(): self
    {
        return new self((int) TelegramGroup::query()->max('id'));
    }

    /**
     * Groups that still hold anything beyond a bare stub.
     *
     * @return Builder<TelegramGroup>
     */
    public static function remaining(int $cutoffId): Builder
    {
        return TelegramGroup::query()->where('id', '<=', $cutoffId)->where(function (Builder $query): void {
            $query->where('status', GroupStatus::Tracked->value)
                ->orWhereNotNull('title')->orWhereNotNull('username')
                ->orWhereNotNull('assignee_id')->orWhereNotNull('customer_id')
                ->orWhereHas('members')->orWhereHas('messages')->orWhereHas('analyses')->orWhereHas('threads');
        });
    }

    public function handle(PurgeGroupData $purge): void
    {
        $emptied = 0;
        $started = microtime(true);

        try {
            foreach (self::remaining($this->cutoffId)->orderBy('id')->limit(self::CHUNK)->get() as $group) {
                if (microtime(true) - $started > self::BUDGET) {
                    break;
                }

                $lock = OfficeLock::forGroup($group->id);

                if (! $lock->get()) {
                    continue;
                }

                try {
                    $purge->handle($group);

                    $group->forceFill([
                        'status' => $group->status === GroupStatus::Ignored ? GroupStatus::Ignored : GroupStatus::Untracked,
                        'title' => null,
                        'username' => null,
                        'assignee_id' => null,
                        'customer_id' => null,
                        'tracked_by_id' => null,
                        'tracked_at' => null,
                        'analysis_due_at' => null,
                        'last_analysed_message_id' => null,
                    ])->save();
                    $emptied++;
                } catch (Throwable $error) {
                    report($error);
                } finally {
                    $lock->release();
                }
            }
        } finally {
            $this->continueOrFinish($emptied);
        }
    }

    /**
     * Queue the next round while groups remain (also after an error), else finish the lead meta.
     */
    private function continueOrFinish(int $emptied): void
    {
        $remaining = self::remaining($this->cutoffId)->count();

        if ($remaining > 0 && $this->round < self::MAX_ROUNDS) {
            dispatch(new self($this->cutoffId, $this->round + 1))->delay(now()->addSeconds($emptied === 0 ? 30 : 1));

            Log::info('office.wipe.groups', ['groups' => $emptied, 'more' => true]);

            return;
        }

        if ($remaining > 0) {
            Log::warning('office.wipe.groups', ['groups' => $emptied, 'remaining' => $remaining, 'rounds_exhausted' => true]);

            return;
        }

        // VO-18 parity: the topic meta that SyncLeadFromGroupThread writes on the leads.
        Activity::query()
            ->where('type', ActivityType::AiSummary->value)
            ->where('subject_type', (new Lead)->getMorphClass())
            ->whereIn('subject_id', Lead::query()->where('source', LeadSource::TelegramBusiness->value)->select('id'))
            ->update(['meta' => null, 'body' => null]);

        Log::info('office.wipe.groups', ['groups' => $emptied, 'more' => false]);
    }

    /**
     * After the last try: one more chain from the start (the cutoff is kept) and an Admin alert, counts only.
     */
    public function failed(?Throwable $error): void
    {
        if ($this->round < self::MAX_ROUNDS - 10) {
            // Once: the retry starts near the end of the round budget, so a failing chain cannot loop.
            dispatch(new self($this->cutoffId, self::MAX_ROUNDS - 10))->delay(now()->addMinutes(5));
        }

        MonitoringAlerts::problem('task_failed', ['reason' => 'office wipe groups, round '.$this->round]);
    }
}
