<?php

namespace App\Actions\Repairs;

use App\Actions\Repairs\Concerns\PreparesRepairInput;
use App\Data\Repairs\RepairData;
use App\Enums\ActivityType;
use App\Enums\RepairCoverage;
use App\Enums\RepairStatus;
use App\Models\Lead;
use App\Models\Repair;
use App\Models\Sequence;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Take a device in (plan 24 B1). Callers authorize repairs.create first. In
 * one transaction: number R-YYYY-NNNN from the sequences table (Tashkent
 * year), status "received", the optional assignee (the actor may only pick
 * themselves unless they have repairs.assign), a "created" activity and, for
 * a warranty repair, the coverage activity with its reference.
 *
 * Amounts are stored only when the actor has repairs.costs and the data says
 * costsIncluded. No personal data is logged.
 */
class CreateRepair
{
    use PreparesRepairInput;

    /**
     * @throws ValidationException
     */
    public function handle(RepairData $data, User $actor): Repair
    {
        $attributes = $this->attributesFrom($data, $actor);
        $attributes['lead_id'] = $this->resolveLead($data, $actor);
        $assignee = $this->assignee($data, $actor);

        // Up to 3 attempts: a deadlock on the sequence row is retried.
        return DB::transaction(function () use ($attributes, $data, $actor, $assignee): Repair {
            $year = now(Lead::TIMEZONE)->year;

            $repair = Repair::query()->create([
                ...$attributes,
                'number' => static::formatNumber($year, Sequence::next("repair-{$year}")),
                'status' => RepairStatus::Received,
                'assigned_to_id' => $assignee?->id,
                'created_by_id' => $actor->id,
                'received_at' => now(),
                'status_changed_at' => now(),
            ]);

            $repair->activities()->create([
                'user_id' => $actor->id,
                'type' => ActivityType::Created,
                'meta' => array_filter([
                    'device_type' => $data->deviceType->value,
                    'coverage' => $data->coverage->value,
                    'customer_id' => $repair->customer_id,
                    'lead_id' => $repair->lead_id,
                    'assigned_to_id' => $repair->assigned_to_id,
                ], fn (mixed $value): bool => $value !== null),
                'created_at' => now(),
            ]);

            if ($data->coverage === RepairCoverage::Warranty) {
                $repair->activities()->create([
                    'user_id' => $actor->id,
                    'type' => ActivityType::Repair,
                    'body' => $repair->warranty_reference,
                    'meta' => ['event' => 'coverage_changed', 'from' => null, 'to' => RepairCoverage::Warranty->value],
                    'created_at' => now(),
                ]);
            }

            return $repair;
        }, 3);
    }

    public static function formatNumber(int $year, int $sequence): string
    {
        return sprintf('R-%d-%04d', $year, $sequence);
    }

    /**
     * @throws ValidationException
     */
    private function assignee(RepairData $data, User $actor): ?User
    {
        if ($data->assigneeId === null) {
            return null;
        }

        $assignee = AssignRepair::eligibleUser($data->assigneeId);

        if (! $assignee instanceof User || ($assignee->id !== $actor->id && ! $actor->can('repairs.assign'))) {
            throw ValidationException::withMessages(['assigned_to_id' => __('repairs.errors.assignee_invalid')]);
        }

        return $assignee;
    }
}
