<?php

namespace App\Actions\Repairs;

use App\Enums\ActivityType;
use App\Models\Repair;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Audit row "receipt printed" (plan 35). One row per user per repair per 10
 * minutes (a cache key), so reloading the PDF tab does not flood the
 * timeline. Never holds the token or the URL.
 */
class LogRepairReceiptPrinted
{
    public const DEDUPE_MINUTES = 10;

    public function handle(Repair $repair, User $user, string $locale): void
    {
        if (! Cache::add("repair-receipt-printed:{$repair->id}:{$user->id}", true, now()->addMinutes(self::DEDUPE_MINUTES))) {
            return;
        }

        $repair->activities()->create([
            'user_id' => $user->id,
            'type' => ActivityType::Repair,
            'meta' => ['action' => 'receipt_printed', 'locale' => $locale],
            'created_at' => now(),
        ]);
    }
}
