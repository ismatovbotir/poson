<?php

namespace App\Actions\Repairs;

use App\Actions\Leads\ChangeLeadStatus;
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
 * With a lead (plan 36): the lead is locked and re-checked (LeadPolicy::createRepair),
 * an open one becomes Converted, and it gets a "repair_created" activity.
 *
 * Amounts are stored only when the actor has repairs.costs and the data says
 * costsIncluded. No personal data is logged.
 */
class CreateRepair
{
    use PreparesRepairInput;

    public function __construct(protected EnsureRepairStatusToken $ensureToken, protected ChangeLeadStatus $changeLeadStatus) {}

    /**
     * @throws ValidationException
     */
    public function handle(RepairData $data, User $actor): Repair
    {
        $attributes = $this->attributesFrom($data, $actor);
        $leadId = $this->resolveLead($data, $actor);
        $attributes['lead_id'] = $leadId;
        $assignee = $this->assignee($data, $actor);

        // Up to 3 attempts: a deadlock on the sequence row is retried.
        return DB::transaction(function () use ($attributes, $data, $actor, $assignee, $leadId): Repair {
            $lead = null;

            if ($leadId !== null) {
                // Repeats resolveLead under a row lock: a change since that read must refuse the repair.
                $lead = Lead::query()->whereKey($leadId)->lockForUpdate()->first();

                if (! $lead instanceof Lead || ! $lead->acceptsRepair() || ! $actor->can('createRepair', $lead)) {
                    throw ValidationException::withMessages(['lead_id' => __('repairs.errors.lead_invalid')]);
                }
            }

            $year = now(Lead::TIMEZONE)->year;

            $repair = new Repair;
            $repair->fill($attributes);
            $repair->forceFill([
                'number' => static::formatNumber($year, Sequence::next("repair-{$year}")),
                'status' => RepairStatus::Received,
                'assigned_to_id' => $assignee?->id,
                'created_by_id' => $actor->id,
                'received_at' => now(),
                'status_changed_at' => now(),
            ])->save();

            $this->ensureToken->handle($repair);

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

            if ($lead instanceof Lead) {
                if ($lead->status->isOpen()) {
                    $this->changeLeadStatus->markConverted($lead, $actor);
                }

                $lead->activities()->create([
                    'user_id' => $actor->id,
                    'type' => ActivityType::Repair,
                    'meta' => ['event' => 'repair_created', 'repair_id' => $repair->id, 'number' => $repair->number],
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
