<?php

namespace App\Actions\Repairs;

use App\Enums\ActivityType;
use App\Enums\RepairCoverage;
use App\Enums\RepairStatus;
use App\Models\Repair;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Record that the customer approved the written estimate. The timestamp is
 * set here, on the server, never taken from input (P24-20); the callers
 * authorize repairs.costs + update first. Possible while the repair is open
 * and not yet ready, for a paid repair with an estimate. Writes an activity
 * row (no amount).
 */
class ApproveRepairEstimate
{
    /**
     * @throws ValidationException
     */
    public function handle(Repair $repair, User $actor): Repair
    {
        return DB::transaction(function () use ($repair, $actor): Repair {
            $current = Repair::query()->whereKey($repair->id)->lockForUpdate()->firstOrFail();

            if ($current->coverage !== RepairCoverage::Paid || $current->status->isClosed() || $current->status === RepairStatus::Ready) {
                throw ValidationException::withMessages(['estimate' => __('repairs.errors.approve_not_possible')]);
            }

            if ($current->estimate_amount === null) {
                throw ValidationException::withMessages(['estimate' => __('repairs.errors.estimate_missing')]);
            }

            if ($current->estimate_approved_at !== null) {
                throw ValidationException::withMessages(['estimate' => __('repairs.errors.already_approved')]);
            }

            $current->estimate_approved_at = now();
            $current->save();

            $current->activities()->create([
                'user_id' => $actor->id,
                'type' => ActivityType::Repair,
                'meta' => ['event' => 'estimate_approved'],
                'created_at' => now(),
            ]);

            $repair->refresh();

            return $repair;
        });
    }
}
