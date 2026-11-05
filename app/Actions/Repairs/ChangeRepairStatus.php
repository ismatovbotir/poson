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
 * Move a repair along RepairStatus::canTransitionTo() (callers authorize
 * RepairPolicy::update first). Guards, checked on the row under a lock:
 *
 *  - a paid repair asks for approval only with an estimate;
 *  - a paid repair enters "in_repair" only with an estimate and the
 *    customer's recorded approval (warranty repairs skip both);
 *  - a paid repair becomes "ready" only with a final amount;
 *  - "cancelled" needs a note.
 *
 * Sets status_changed_at, closed_at on delivered / cancelled, and writes a
 * "status_change" activity (from, to; the note as the body).
 */
class ChangeRepairStatus
{
    public const NOTE_MAX = 2000;

    /**
     * @throws ValidationException
     */
    public function handle(Repair $repair, RepairStatus $to, User $actor, ?string $note = null): Repair
    {
        $note = filled($note) ? trim((string) $note) : null;

        if ($note !== null && mb_strlen($note) > self::NOTE_MAX) {
            throw ValidationException::withMessages(['note' => __('validation.max.string', ['attribute' => __('repairs.fields.note'), 'max' => self::NOTE_MAX])]);
        }

        if ($to === RepairStatus::Cancelled && $note === null) {
            throw ValidationException::withMessages(['note' => __('repairs.errors.cancel_note_required')]);
        }

        return DB::transaction(function () use ($repair, $to, $actor, $note): Repair {
            // Re-read under a row lock: a concurrent change must not be overwritten.
            $current = Repair::query()->whereKey($repair->id)->lockForUpdate()->firstOrFail();
            $from = $current->status;

            if (! $from->canTransitionTo($to, $current->coverage)) {
                throw ValidationException::withMessages(['status' => __('repairs.errors.transition_invalid', ['from' => $from->label(), 'to' => $to->label()])]);
            }

            $paid = $current->coverage === RepairCoverage::Paid;

            if ($to === RepairStatus::AwaitingApproval && $paid && $current->estimate_amount === null) {
                throw ValidationException::withMessages(['status' => __('repairs.errors.estimate_missing')]);
            }

            if ($to === RepairStatus::InRepair && $paid && ($current->estimate_amount === null || $current->estimate_approved_at === null)) {
                throw ValidationException::withMessages(['status' => __('repairs.errors.approval_required')]);
            }

            if ($to === RepairStatus::Ready && $paid && $current->final_amount === null) {
                throw ValidationException::withMessages(['status' => __('repairs.errors.final_amount_required')]);
            }

            $current->fill([
                'status' => $to,
                'status_changed_at' => now(),
                'closed_at' => $to->isClosed() ? now() : null,
            ])->save();

            $current->activities()->create([
                'user_id' => $actor->id,
                'type' => ActivityType::StatusChange,
                'body' => $note,
                'meta' => ['from' => $from->value, 'to' => $to->value],
                'created_at' => now(),
            ]);

            $repair->refresh();

            return $repair;
        });
    }
}
