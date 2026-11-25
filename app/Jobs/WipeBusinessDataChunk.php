<?php

namespace App\Jobs;

use App\Enums\ActivityType;
use App\Enums\LeadSource;
use App\Models\Activity;
use App\Models\BusinessChat;
use App\Models\BusinessConnection;
use App\Models\Lead;
use App\Models\TelegramUser;
use App\Support\Office\OfficeLock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One chunk of "Delete all business data" (plan 41, VO-12): up to CHUNK
 * business chats with an id up to $cutoffId (the newest chat when the wipe
 * started, so it always ends) are deleted, each under its analysis lock (a
 * running analysis can no longer save); their activities go with them. The
 * job queues itself again while such chats remain (at most MAX_ROUNDS
 * rounds), then removes the connections, every remaining business-chat
 * activity, the bot users' party marks and the AiSummary meta of Telegram
 * Business leads. Counts-only log; never sends anything.
 */
class WipeBusinessDataChunk implements ShouldQueue
{
    use Queueable;

    public const CHUNK = 200;

    public const MAX_ROUNDS = 500;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $cutoffId = 0, public int $round = 1)
    {
        $this->onQueue('default');
    }

    public static function forNow(): self
    {
        return new self((int) BusinessChat::query()->max('id'));
    }

    public function handle(): void
    {
        $deleted = 0;

        foreach (BusinessChat::query()->where('id', '<=', $this->cutoffId)->orderBy('id')->limit(self::CHUNK)->get() as $chat) {
            $lock = OfficeLock::forChat($chat->id);

            if (! $lock->get()) {
                continue;
            }

            try {
                $chat->delete();
                $deleted++;
            } finally {
                $lock->release();
            }
        }

        if ($this->round < self::MAX_ROUNDS && BusinessChat::query()->where('id', '<=', $this->cutoffId)->exists()) {
            // A chat whose analysis is still running is retried in the next round (its lock is free by then).
            dispatch(new self($this->cutoffId, $this->round + 1))->delay(now()->addSeconds($deleted === 0 ? 30 : 1));

            Log::info('office.wipe', ['chats' => $deleted, 'more' => true]);

            return;
        }

        BusinessConnection::query()->whereDoesntHave('chats')->delete();
        Activity::query()->where('subject_type', (new BusinessChat)->getMorphClass())->delete();
        TelegramUser::query()->whereNotNull('party')->orWhereNotNull('staff_user_id')->update(['party' => null, 'staff_user_id' => null]);

        Activity::query()
            ->where('type', ActivityType::AiSummary->value)
            ->where('subject_type', (new Lead)->getMorphClass())
            ->whereIn('subject_id', Lead::query()->where('source', LeadSource::TelegramBusiness->value)->select('id'))
            ->update(['meta' => null, 'body' => null]);

        Log::info('office.wipe', ['chats' => $deleted, 'more' => false]);
    }
}
