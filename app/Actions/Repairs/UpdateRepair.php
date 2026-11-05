<?php

namespace App\Actions\Repairs;

use App\Actions\Repairs\Concerns\PreparesRepairInput;
use App\Data\Repairs\RepairData;
use App\Enums\ActivityType;
use App\Enums\RepairCoverage;
use App\Models\Repair;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edit an open repair (callers authorize RepairPolicy::update first). Status
 * and assignee change only through ChangeRepairStatus / AssignRepair.
 *
 * Rules (P24-20):
 *  - amounts are applied only with repairs.costs and costsIncluded; raising
 *    the estimate (or clearing it) clears the customer's approval, with an
 *    activity row;
 *  - moving to warranty needs a reference (shared with create) and writes a
 *    "coverage changed" activity carrying that reference;
 *  - estimate_approved_at is never set here (ApproveRepairEstimate does).
 *
 * Activity rows never hold amounts.
 */
class UpdateRepair
{
    use PreparesRepairInput;

    /**
     * @throws ValidationException
     */
    public function handle(Repair $repair, RepairData $data, User $actor): Repair
    {
        $attributes = $this->attributesFrom($data, $actor);

        return DB::transaction(function () use ($repair, $attributes, $actor): Repair {
            $current = Repair::query()->whereKey($repair->id)->lockForUpdate()->firstOrFail();

            if ($current->status->isClosed()) {
                throw ValidationException::withMessages(['status' => __('repairs.errors.closed')]);
            }

            $oldCoverage = $current->coverage;
            $oldEstimate = $current->estimate_amount;
            $approvedBefore = $current->estimate_approved_at !== null;

            $current->fill($attributes);

            $estimateChanged = array_key_exists('estimate_amount', $attributes) && $current->estimate_amount !== $oldEstimate;
            $raised = $estimateChanged && ($current->estimate_amount === null || ($oldEstimate !== null && $current->estimate_amount > $oldEstimate));
            $approvalCleared = $raised && $approvedBefore;

            if ($approvalCleared) {
                $current->estimate_approved_at = null;
            }

            $current->save();

            if ($estimateChanged) {
                $this->log($current, $actor, ['event' => 'estimate_changed', 'approval_cleared' => $approvalCleared ? true : null]);
            }

            if ($current->coverage !== $oldCoverage) {
                $this->log($current, $actor, ['event' => 'coverage_changed', 'from' => $oldCoverage->value, 'to' => $current->coverage->value], $current->coverage === RepairCoverage::Warranty ? $current->warranty_reference : null);
            }

            $repair->refresh();

            return $repair;
        });
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function log(Repair $repair, User $actor, array $meta, ?string $body = null): void
    {
        $repair->activities()->create([
            'user_id' => $actor->id,
            'type' => ActivityType::Repair,
            'body' => $body,
            'meta' => array_filter($meta, fn (mixed $value): bool => $value !== null),
            'created_at' => now(),
        ]);
    }
}
