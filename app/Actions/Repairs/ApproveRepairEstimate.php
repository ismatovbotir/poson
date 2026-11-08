<?php

namespace App\Actions\Repairs;

use App\Enums\ActivityType;
use App\Enums\RepairCoverage;
use App\Enums\RepairStatus;
use App\Enums\Role;
use App\Models\Repair;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Record that the customer approved the written estimate. The timestamp and
 * the approved amount are set here, on the server, never taken from input
 * (P24-20). Callers authorize RepairPolicy::approveEstimate first (costs
 * rights; the repair assigned to the actor, Admin exempt); the action
 * re-checks the assignment. Rules:
 *
 *  - a short note on how the customer approved (paper number, phone call) is
 *    required and becomes the activity body;
 *  - the caller passes the version() it saw: if the estimate changed since
 *    (a stale tab), nothing is approved;
 *  - possible while the repair is open and not yet ready, for a paid repair
 *    with an estimate.
 *
 * version() is an HMAC of the repair and its estimate, so the page can carry
 * it without exposing the amount.
 */
class ApproveRepairEstimate
{
    public const NOTE_MAX = 500;

    /**
     * @throws ValidationException
     */
    public function handle(Repair $repair, User $actor, string $note, string $version): Repair
    {
        $note = trim($note);

        if ($note === '') {
            throw ValidationException::withMessages(['note' => __('validation.required', ['attribute' => __('repairs.fields.approval_note')])]);
        }

        if (mb_strlen($note) > self::NOTE_MAX) {
            throw ValidationException::withMessages(['note' => __('validation.max.string', ['attribute' => __('repairs.fields.approval_note'), 'max' => self::NOTE_MAX])]);
        }

        return DB::transaction(function () use ($repair, $actor, $note, $version): Repair {
            $current = Repair::query()->whereKey($repair->id)->lockForUpdate()->firstOrFail();

            if (! $actor->hasRole(Role::Admin) && $current->assigned_to_id !== $actor->id) {
                throw ValidationException::withMessages(['estimate' => __('repairs.errors.approve_not_assigned')]);
            }

            if ($current->isOpen()) {
                Gate::forUser($actor)->authorize('approveEstimate', $current);
            }

            if ($current->coverage !== RepairCoverage::Paid || $current->status->isClosed() || $current->status === RepairStatus::Ready) {
                throw ValidationException::withMessages(['estimate' => __('repairs.errors.approve_not_possible')]);
            }

            if ($current->estimate_amount === null) {
                throw ValidationException::withMessages(['estimate' => __('repairs.errors.estimate_missing')]);
            }

            if (! hash_equals(static::version($current), $version)) {
                throw ValidationException::withMessages(['estimate' => __('repairs.errors.estimate_version')]);
            }

            if ($current->estimate_approved_at !== null) {
                throw ValidationException::withMessages(['estimate' => __('repairs.errors.already_approved')]);
            }

            $current->estimate_approved_at = now();
            $current->estimate_approved_amount = $current->estimate_amount;
            $current->save();

            $current->activities()->create([
                'user_id' => $actor->id,
                'type' => ActivityType::Repair,
                'body' => $note,
                'meta' => ['event' => 'estimate_approved'],
                'created_at' => now(),
            ]);

            $repair->refresh();

            return $repair;
        });
    }

    /**
     * Opaque marker of the estimate a page was rendered with.
     */
    public static function version(Repair $repair): string
    {
        return hash_hmac('sha256', $repair->id.'|'.($repair->estimate_amount ?? 'none'), (string) config('app.key'));
    }
}
