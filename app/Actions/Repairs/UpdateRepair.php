<?php

namespace App\Actions\Repairs;

use App\Actions\Repairs\Concerns\PreparesRepairInput;
use App\Data\Repairs\RepairData;
use App\Enums\ActivityType;
use App\Enums\RepairCoverage;
use App\Enums\RepairStatus;
use App\Enums\Role;
use App\Models\Repair;
use App\Models\User;
use BackedEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Edit an open repair (callers authorize RepairPolicy::update first). Status
 * and assignee change only through ChangeRepairStatus / AssignRepair; the
 * lead link is written on create only.
 *
 * Money rules (P24-20, fix batch):
 *  - amounts are applied only with repairs.costs and costsIncluded;
 *  - raising or clearing the estimate clears the customer's approval; while
 *    the paid repair is in repair / awaiting parts it also goes back to
 *    "awaiting approval", and at "ready" the change is refused;
 *  - lowering keeps the approval (the approved amount follows);
 *  - at "ready" the final amount can't be cleared nor exceed the estimate;
 *  - a coverage change clears the approval, is refused once work started
 *    (in repair, awaiting parts, ready) unless the actor is Admin, and
 *    going to warranty needs a reference;
 *  - estimate_approved_at is never set here (ApproveRepairEstimate does).
 *
 * Lost updates (security review): the edit form carries version() of the row
 * it loaded; under the row lock a different version means someone else
 * changed the repair meanwhile and nothing is saved. The lock also re-checks
 * that the actor may still update this repair (reassigned meanwhile).
 *
 * Every sensitive change writes an activity row; rows never hold amounts.
 */
class UpdateRepair
{
    use PreparesRepairInput;

    /**
     * @throws ValidationException
     */
    public function handle(Repair $repair, RepairData $data, User $actor, ?string $version = null): Repair
    {
        $attributes = $this->attributesFrom($data, $actor);

        return DB::transaction(function () use ($repair, $attributes, $actor, $version): Repair {
            $current = Repair::query()->whereKey($repair->id)->lockForUpdate()->firstOrFail();

            if ($current->status->isClosed()) {
                throw ValidationException::withMessages(['status' => __('repairs.errors.closed')]);
            }

            Gate::forUser($actor)->authorize('update', $current);

            if ($version !== null && ! hash_equals(static::version($current), $version)) {
                throw ValidationException::withMessages(['version' => __('repairs.errors.changed_meanwhile')]);
            }

            $oldCoverage = $current->coverage;
            $oldReference = $current->warranty_reference;
            $oldEstimate = $current->estimate_amount;
            $oldFinal = $current->final_amount;
            $workStarted = in_array($current->status, [RepairStatus::InRepair, RepairStatus::AwaitingParts, RepairStatus::Ready], true);
            $coverageChanged = $attributes['coverage'] !== $oldCoverage;

            if ($coverageChanged && $workStarted && ! $actor->hasRole(Role::Admin)) {
                throw ValidationException::withMessages(['coverage' => __('repairs.errors.coverage_locked')]);
            }

            $current->fill($attributes);
            $approved = $current->estimate_approved_at !== null;
            $backToApproval = false;

            if ($coverageChanged) {
                $this->clearApproval($current);
                $this->log($current, $actor, ['event' => 'coverage_changed', 'from' => $oldCoverage->value, 'to' => $current->coverage->value, 'approval_cleared' => $approved ? true : null], $current->coverage === RepairCoverage::Warranty ? $current->warranty_reference : null);
                $backToApproval = $current->coverage === RepairCoverage::Paid;
            } elseif ($current->coverage === RepairCoverage::Warranty && $current->warranty_reference !== $oldReference) {
                $this->log($current, $actor, ['event' => 'warranty_reference_changed'], $current->warranty_reference);
            }

            if (array_key_exists('estimate_amount', $attributes) && $current->estimate_amount !== $oldEstimate) {
                $raisedOrCleared = $current->estimate_amount === null || ($oldEstimate !== null && $current->estimate_amount > $oldEstimate);

                if ($raisedOrCleared && $current->status === RepairStatus::Ready && $current->coverage === RepairCoverage::Paid) {
                    throw ValidationException::withMessages(['estimate_amount' => __('repairs.errors.estimate_locked_ready')]);
                }

                $cleared = $raisedOrCleared && $current->estimate_approved_at !== null;

                if ($cleared) {
                    $this->clearApproval($current);
                    $backToApproval = true;
                } elseif (! $raisedOrCleared && $current->estimate_approved_at !== null) {
                    $current->estimate_approved_amount = $current->estimate_amount;
                }

                $this->log($current, $actor, ['event' => 'estimate_changed', 'approval_cleared' => $cleared ? true : null]);
            }

            if (array_key_exists('final_amount', $attributes) && $current->final_amount !== $oldFinal) {
                if ($current->final_amount === null && $current->status === RepairStatus::Ready && $current->coverage === RepairCoverage::Paid) {
                    throw ValidationException::withMessages(['final_amount' => __('repairs.errors.final_locked_ready')]);
                }

                $this->log($current, $actor, ['event' => 'final_changed']);
            }

            if ($current->status === RepairStatus::Ready && $current->coverage === RepairCoverage::Paid && $current->final_amount !== null && $current->estimate_amount !== null && $current->final_amount > $current->estimate_amount) {
                throw ValidationException::withMessages(['final_amount' => __('repairs.errors.final_above_estimate')]);
            }

            if ($backToApproval && $current->coverage === RepairCoverage::Paid && in_array($current->status, [RepairStatus::InRepair, RepairStatus::AwaitingParts], true)) {
                $from = $current->status;
                $current->forceFill(['status' => RepairStatus::AwaitingApproval, 'status_changed_at' => now()]);

                $current->activities()->create([
                    'user_id' => $actor->id,
                    'type' => ActivityType::StatusChange,
                    'meta' => ['from' => $from->value, 'to' => RepairStatus::AwaitingApproval->value, 'auto' => true],
                    'created_at' => now(),
                ]);
            }

            $current->save();
            $repair->refresh();

            return $repair;
        });
    }

    /**
     * Columns the edit form reads or writes plus the ones other actions change
     * (status, assignee, approval). Timestamps are left out on purpose: a note
     * or touch must not invalidate an open form.
     *
     * @var list<string>
     */
    private const VERSION_COLUMNS = [
        'contact_name', 'contact_phone', 'company', 'customer_id', 'contact_id', 'locale',
        'device_type', 'device_brand', 'device_model', 'serial_number', 'accessories',
        'intake_condition', 'problem', 'diagnosis', 'work_done', 'parts_used',
        'coverage', 'warranty_reference', 'estimate_amount', 'final_amount',
        'status', 'assigned_to_id', 'estimate_approved_at', 'estimate_approved_amount',
    ];

    /**
     * Opaque marker of the row an edit form was loaded from (an HMAC over the
     * VERSION_COLUMNS, so it exposes no amounts).
     */
    public static function version(Repair $repair): string
    {
        $values = [];

        foreach (self::VERSION_COLUMNS as $column) {
            $value = $repair->getAttributes()[$column] ?? null;
            $values[$column] = $value === null ? null : (string) ($value instanceof BackedEnum ? $value->value : $value);
        }

        return hash_hmac('sha256', $repair->id.'|'.json_encode($values, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function clearApproval(Repair $repair): void
    {
        $repair->estimate_approved_at = null;
        $repair->estimate_approved_amount = null;
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
