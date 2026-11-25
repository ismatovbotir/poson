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

    public static function forChat(int $chatId): Lock
    {
        return Cache::lock('office:analyse:'.$chatId, self::SECONDS);
    }
}
