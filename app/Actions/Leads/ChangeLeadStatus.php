<?php

namespace App\Actions\Leads;

use App\Enums\ActivityType;
use App\Enums\LeadLostReason;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Move a lead through the pipeline (LeadStatus::canTransitionTo()).
 *
 * - Lost needs a reason (LeadLostReason) and, for "other", a note.
 * - Sets status_changed_at; closed_at when it closes (converted / lost),
 *   cleared on reopen; the lost reason / note are cleared when not lost.
 * - Writes a "status_change" activity (from, to, reason; the note as the
 *   body), marked "reopened" when a closed lead is reopened.
 */
class ChangeLeadStatus
{
    public const NOTE_MAX = 500;

    /**
     * @throws ValidationException
     */
    public function handle(Lead $lead, LeadStatus $to, User $actor, ?LeadLostReason $reason = null, ?string $note = null): Lead
    {
        $note = filled($note) ? trim((string) $note) : null;

        if ($to === LeadStatus::Lost) {
            if ($reason === null) {
                throw ValidationException::withMessages(['lost_reason' => __('crm.leads.errors.lost_reason_required')]);
            }

            if ($reason->requiresNote() && $note === null) {
                throw ValidationException::withMessages(['lost_note' => __('crm.leads.errors.lost_note_required')]);
            }
        }

        if ($note !== null && mb_strlen($note) > self::NOTE_MAX) {
            throw ValidationException::withMessages(['lost_note' => __('validation.max.string', ['attribute' => __('crm.leads.fields.lost_note'), 'max' => self::NOTE_MAX])]);
        }

        return DB::transaction(function () use ($lead, $to, $actor, $reason, $note): Lead {
            // Re-read under a row lock: a concurrent change must not be overwritten.
            $from = Lead::query()->whereKey($lead->id)->lockForUpdate()->value('status');
            $from = $from instanceof LeadStatus ? $from : LeadStatus::from((string) $from);

            if (! $from->canTransitionTo($to)) {
                throw ValidationException::withMessages(['status' => __('crm.leads.errors.transition_invalid', ['from' => $from->label(), 'to' => $to->label()])]);
            }

            $lost = $to === LeadStatus::Lost;

            $lead->fill([
                'status' => $to,
                'status_changed_at' => now(),
                'closed_at' => $to->isClosed() ? now() : null,
                'lost_reason' => $lost ? $reason : null,
                'lost_note' => $lost ? $note : null,
            ])->save();

            $lead->activities()->create([
                'user_id' => $actor->id,
                'type' => ActivityType::StatusChange,
                'body' => $lost ? $note : null,
                'meta' => array_filter([
                    'from' => $from->value,
                    'to' => $to->value,
                    'reason' => $lost ? $reason?->value : null,
                    'reopened' => $from->isClosed() ? true : null,
                ], fn (mixed $value): bool => $value !== null),
                'created_at' => now(),
            ]);

            return $lead;
        });
    }
}
