<?php

namespace App\Actions\Repairs;

use App\Enums\ActivityType;
use App\Models\Lead;
use App\Models\Repair;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Renew the public status link (plan 35, P35-3/P35-4): policy rotateStatusLink,
 * at most 5 per repair and Tashkent day. The old QR stops working at once.
 * closed_at is untouched, so the 30-day liveness window never moves. The
 * activity carries only {action}, never the token or URL.
 */
class RotateRepairStatusToken
{
    public const DAILY_LIMIT = 5;

    /**
     * @throws ValidationException
     */
    public function handle(Repair $repair, User $actor): void
    {
        Gate::forUser($actor)->authorize('rotateStatusLink', $repair);

        for ($attempt = 1; ; $attempt++) {
            try {
                $this->rotate($repair, $actor);

                return;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 3) {
                    throw $e;
                }
            }
        }
    }

    private function rotate(Repair $repair, User $actor): void
    {
        DB::transaction(function () use ($repair, $actor): void {
            $locked = Repair::query()->whereKey($repair->id)->lockForUpdate()->firstOrFail();

            $today = $locked->activities()
                ->where('type', ActivityType::Repair->value)
                ->where('meta->action', 'status_link_rotated')
                ->where('created_at', '>=', now(Lead::TIMEZONE)->startOfDay()->utc())
                ->count();

            if ($today >= self::DAILY_LIMIT) {
                throw ValidationException::withMessages([
                    'link' => __('repairs.errors.status_link_rotate_limit', ['max' => self::DAILY_LIMIT]),
                ]);
            }

            $token = Str::random(EnsureRepairStatusToken::LENGTH);
            $rotatedAt = now();

            DB::table('repairs')->where('id', $locked->id)->update([
                'public_token' => $token,
                'public_token_rotated_at' => $rotatedAt,
            ]);

            $locked->activities()->create([
                'user_id' => $actor->id,
                'type' => ActivityType::Repair,
                'meta' => ['action' => 'status_link_rotated'],
                'created_at' => now(),
            ]);

            $repair->setRawAttributes(array_merge($repair->getAttributes(), [
                'public_token' => $token,
                'public_token_rotated_at' => $rotatedAt->toDateTimeString(),
            ]), true);
        });
    }
}
