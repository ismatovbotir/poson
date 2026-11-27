<?php

namespace App\Actions\Office;

use App\Models\TelegramGroup;
use App\Models\TelegramGroupAnalysis;
use App\Models\TelegramGroupMember;
use App\Models\TelegramGroupMessage;
use App\Models\TelegramGroupThread;
use Illuminate\Database\Eloquent\Model;

/**
 * Chunked deletion of everything a group stores (plan 44, OG-12): phones are
 * nulled first, then messages, threads, analyses and members go, CHUNK rows
 * at a time (by primary key, never the whole group in memory). The group row
 * and the leads are not touched. The caller holds the group's OfficeLock.
 * Used by Untrack, the Wipe-all job and retention.
 */
class PurgeGroupData
{
    public const CHUNK = 500;

    /**
     * @return array{messages: int, threads: int, analyses: int, members: int}
     */
    public function handle(TelegramGroup $group): array
    {
        TelegramGroupMember::query()->where('telegram_group_id', $group->id)->whereNotNull('phone')->update(['phone' => null]);
        ForgetDraftFeedback::forGroup($group->id);

        return [
            'messages' => self::deleteChunked(TelegramGroupMessage::class, $group->id),
            'threads' => self::deleteChunked(TelegramGroupThread::class, $group->id),
            'analyses' => self::deleteChunked(TelegramGroupAnalysis::class, $group->id),
            'members' => self::deleteChunked(TelegramGroupMember::class, $group->id),
        ];
    }

    /**
     * @param  class-string<Model>  $model
     */
    public static function deleteChunked(string $model, int $groupId): int
    {
        $total = 0;

        do {
            $ids = $model::query()->where('telegram_group_id', $groupId)->orderBy('id')->limit(self::CHUNK)->pluck('id')->all();

            if ($ids !== []) {
                $total += $model::query()->whereKey($ids)->delete();
            }
        } while (count($ids) === self::CHUNK);

        return $total;
    }
}
