<?php

namespace App\Actions\Office;

use App\Actions\Chat\RecordAiUsage;
use App\Enums\AiUsageChannel;
use App\Enums\AnalysisStatus;
use App\Jobs\AnalyseTelegramGroup;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupAnalysis;
use App\Models\User;
use App\Support\Office\OfficeSettings;
use Illuminate\Support\Carbon;

/**
 * Queue one analysis of a locked tracked group (plan 44 §2, OG-4, OG-11),
 * shared by the scheduler (DispatchDueAnalyses) and "Analyse now"
 * (RequestGroupAnalysis), the group twin of QueueBusinessChatAnalysis.
 * Call inside a transaction with the group row locked.
 *
 *  - gates (isAnalysable): groups and ingest on, office AI on, tracked,
 *    assigned, the bot not left / kicked; else nothing and the due time
 *    is cleared (GATED);
 *  - a group run counts toward the per-chat daily cap (office.per_chat_daily,
 *    Tashkent day): reached → due again tomorrow, nothing queued;
 *  - the router channel's cap already reached → ONE capped row, due tomorrow;
 *  - else a queued row, the due time cleared, the counter up, and
 *    AnalyseTelegramGroup dispatched after the commit.
 */
class QueueGroupAnalysis
{
    public const QUEUED = 'queued';

    public const CHAT_CAP = 'chat_cap';

    public const CAPPED = 'capped';

    public const GATED = 'gated';

    public function __construct(protected RecordAiUsage $usage) {}

    /**
     * Plan 44 OG-4: everything a group analysis needs, checked again by the run and before saving.
     */
    public static function isAnalysable(TelegramGroup $group): bool
    {
        return OfficeSettings::groupsEnabled() && OfficeSettings::aiEnabled() && $group->isTracked()
            && $group->assignee_id !== null && ! in_array($group->bot_status, ['left', 'kicked'], true);
    }

    /**
     * @return self::QUEUED|self::CHAT_CAP|self::CAPPED|self::GATED
     */
    public function handle(TelegramGroup $group, string $trigger, ?User $requestedBy = null): string
    {
        if (! self::isAnalysable($group)) {
            $group->forceFill(['analysis_due_at' => null])->save();

            return self::GATED;
        }

        $today = now('Asia/Tashkent')->toDateString();

        if ($group->analyses_day?->toDateString() !== $today) {
            $group->forceFill(['analyses_day' => Carbon::parse($today), 'analyses_today' => 0]);
        }

        if ($group->analyses_today >= OfficeSettings::perChatDaily()) {
            $group->forceFill(['analysis_due_at' => QueueBusinessChatAnalysis::tomorrow()])->save();

            return self::CHAT_CAP;
        }

        if (! $this->usage->withinCaps(AiUsageChannel::OfficeRoute)) {
            $group->analyses()->create(['status' => AnalysisStatus::Capped, 'trigger' => $trigger, 'requested_by_id' => $requestedBy?->id])
                ->forceFill(['error' => 'capped', 'finished_at' => now()])->save();
            $group->forceFill(['analysis_due_at' => QueueBusinessChatAnalysis::tomorrow()])->save();

            return self::CAPPED;
        }

        $analysis = $group->analyses()->create([
            'status' => AnalysisStatus::Queued,
            'trigger' => $trigger,
            'requested_by_id' => $requestedBy?->id,
        ]);

        $group->forceFill(['analysis_due_at' => null, 'analyses_today' => $group->analyses_today + 1])->save();

        dispatch(new AnalyseTelegramGroup($analysis->id))->afterCommit();

        return self::QUEUED;
    }

    public static function hasPending(TelegramGroup $group): bool
    {
        return TelegramGroupAnalysis::query()->where('telegram_group_id', $group->id)->whereIn('status', AnalysisStatus::pendingValues())->exists();
    }
}
