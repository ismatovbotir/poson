<?php

namespace App\Actions\Repairs;

use App\Models\Repair;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Give a repair its public status token if it has none (plan 35, P35-5).
 * Idempotent: under a row lock the row is re-read and the token is set only
 * when still null. Writes only the token column (no events, no updated_at, no
 * status_changed_at) and retries on a unique collision.
 */
class EnsureRepairStatusToken
{
    public const LENGTH = 40;

    public function handle(Repair $repair): string
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($repair): string {
                    $current = Repair::query()->whereKey($repair->id)->lockForUpdate()->firstOrFail();

                    if ($current->public_token === null) {
                        DB::table('repairs')->where('id', $repair->id)->update(['public_token' => Str::random(self::LENGTH)]);
                        $current->refresh();
                    }

                    $repair->setRawAttributes(array_merge($repair->getAttributes(), [
                        'public_token' => $current->public_token,
                    ]), true);

                    return (string) $current->public_token;
                });
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 3) {
                    throw $e;
                }
            }
        }
    }
}
