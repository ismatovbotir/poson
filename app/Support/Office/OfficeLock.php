<?php

namespace App\Support\Office;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * The per-chat lock "office:analyse:{chat}" (plan 41, VO-11, VO-12): held by
 * a running analysis and by every action that removes chat content (unmark,
 * wipe), so an analysis can never save or sync a lead for a chat that was
 * unmarked meanwhile.
 */
final class OfficeLock
{
    public const SECONDS = 150;

    /**
     * A group run (router + up to 3 agents) outlives a chat run: above office.group_job_timeout.
     */
    public const GROUP_SECONDS = 270;

    public static function forChat(int $chatId): Lock
    {
        return Cache::lock('office:analyse:'.$chatId, self::SECONDS);
    }

    /**
     * Plan 44 (M2): the per-group lock "office:analyse-group:{group}", held by a running group
     * analysis and by Forget, so an analysis never saves text from messages forgotten meanwhile.
     */
    public static function forGroup(int $groupId): Lock
    {
        return Cache::lock('office:analyse-group:'.$groupId, self::GROUP_SECONDS);
    }
}
