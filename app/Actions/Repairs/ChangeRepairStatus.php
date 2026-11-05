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
 *  - a written diagnosis is needed for "awaiting_approval" and for the
 *    warranty shortcut diagnosing → in_repair;
 *  - a paid repair asks for approval only with an estimate;
 *  - a paid repair enters in_repair, ready and delivered only with an
 *    estimate and its recorded approval of that amount (warranty repairs skip it);
 *  - ready and delivered also need a final amount, not above the estimate;
 *  - going back to "diagnosing" clears the approval;
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

            $this->checkGuards($current, $from, $to);

            $clearsApproval = $to === RepairStatus::Diagnosing;

            if ($clearsApproval) {
                $current->estimate_approved_at = null;
                $current->estimate_approved_amount = null;
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
                'meta' => array_filter(['from' => $from->value, 'to' => $to->value, 'approval_cleared' => $clearsApproval && $current->wasChanged('estimate_approved_at') ? true : null]),
                'created_at' => now(),
            ]);

            $repair->refresh();

            return $repair;
        });
    }

    /**
     * The business guards of a move (see the class comment).
     *
     * @throws ValidationException
     */
    private function checkGuards(Repair $repair, RepairStatus $from, RepairStatus $to): void
    {
        $paid = $repair->coverage === RepairCoverage::Paid;

        // Diagnostics first: a written diagnosis before asking for approval or skipping the estimate.
        if (($to === RepairStatus::AwaitingApproval || ($from === RepairStatus::Diagnosing && $to === RepairStatus::InRepair)) && blank($repair->diagnosis)) {
            throw ValidationException::withMessages(['status' => __('repairs.errors.diagnosis_required')]);
        }

        if (! $paid) {
            return;
        }

        if ($to === RepairStatus::AwaitingApproval && $repair->estimate_amount === null) {
            throw ValidationException::withMessages(['status' => __('repairs.errors.estimate_missing')]);
        }

        if (in_array($to, [RepairStatus::InRepair, RepairStatus::Ready, RepairStatus::Delivered], true)
            && ($repair->estimate_amount === null || $repair->estimate_approved_at === null || $repair->estimate_approved_amount !== $repair->estimate_amount)) {
            throw ValidationException::withMessages(['status' => __('repairs.errors.approval_required')]);
        }

        if (in_array($to, [RepairStatus::Ready, RepairStatus::Delivered], true)) {
            if ($repair->final_amount === null) {
                throw ValidationException::withMessages(['status' => __('repairs.errors.final_amount_required')]);
            }

            if ($repair->final_amount > $repair->estimate_amount) {
                throw ValidationException::withMessages(['status' => __('repairs.errors.final_above_estimate')]);
            }
        }
    }
}
