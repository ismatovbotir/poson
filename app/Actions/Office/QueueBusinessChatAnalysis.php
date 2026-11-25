<?php

namespace App\Actions\Office;

use App\Actions\Chat\RecordAiUsage;
use App\Enums\AiUsageChannel;
use App\Enums\AnalysisStatus;
use App\Jobs\AnalyseBusinessChat;
use App\Models\BusinessChat;
use App\Models\User;
use App\Support\Office\OfficeSettings;
use Illuminate\Support\Carbon;

/**
 * Queue one analysis of a locked client chat (plan 41 §6.1), shared by the
 * scheduler (DispatchDueAnalyses) and "Analyse now" (RequestBusinessChatAnalysis).
 * Call inside a transaction with the chat row locked.
 *
 *  - the per-chat daily cap (office.per_chat_daily, Tashkent day): reached →
 *    the chat is due again tomorrow, nothing queued;
 *  - the router channel's cap already reached → ONE capped row and the
 *    chat is due again tomorrow (no row every minute);
 *  - else a queued row, the due time cleared, the counter up, and
 *    AnalyseBusinessChat dispatched after the commit.
 */
class QueueBusinessChatAnalysis
{
    public const QUEUED = 'queued';

    public const CHAT_CAP = 'chat_cap';

    public const CAPPED = 'capped';

    public function __construct(protected RecordAiUsage $usage) {}

    /**
     * @return self::QUEUED|self::CHAT_CAP|self::CAPPED
     */
    public function handle(BusinessChat $chat, string $trigger, ?User $requestedBy = null): string
    {
        $today = now('Asia/Tashkent')->toDateString();

        if ($chat->analyses_day?->toDateString() !== $today) {
            $chat->analyses_day = Carbon::parse($today);
            $chat->analyses_today = 0;
        }

        if ($chat->analyses_today >= OfficeSettings::perChatDaily()) {
            $chat->analysis_due_at = self::tomorrow();
            $chat->save();

            return self::CHAT_CAP;
        }

        if (! $this->usage->withinCaps(AiUsageChannel::OfficeRoute)) {
            $chat->analyses()->create(['status' => AnalysisStatus::Capped, 'trigger' => $trigger, 'requested_by_id' => $requestedBy?->id])
                ->forceFill(['error' => 'capped', 'finished_at' => now()])->save();
            $chat->analysis_due_at = self::tomorrow();
            $chat->save();

            return self::CAPPED;
        }

        $analysis = $chat->analyses()->create([
            'status' => AnalysisStatus::Queued,
            'trigger' => $trigger,
            'requested_by_id' => $requestedBy?->id,
        ]);

        $chat->analysis_due_at = null;
        $chat->analyses_today++;
        $chat->save();

        dispatch(new AnalyseBusinessChat($analysis->id))->afterCommit();

        return self::QUEUED;
    }

    /**
     * The start of the next Tashkent day, in the app timezone.
     */
    public static function tomorrow(): Carbon
    {
        return now('Asia/Tashkent')->addDay()->startOfDay()->setTimezone((string) config('app.timezone'));
    }
}
