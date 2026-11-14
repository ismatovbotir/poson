<?php

namespace App\Actions\Careers;

use App\Enums\ActivityType;
use App\Enums\JobApplicationStatus;
use App\Models\JobApplication;
use App\Models\User;
use App\Support\PlainText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Change an application's status and internal note (plan 31, Admin,
 * careers.applications). Any transition is allowed; a status change sets
 * status_changed_at and logs a status_change activity (from / to), a note
 * change logs a note activity without the text (the note itself is on the row).
 */
class UpdateJobApplication
{
    public const NOTE_MAX = 5000;

    /**
     * @throws ValidationException
     */
    public function handle(JobApplication $application, JobApplicationStatus $status, ?string $note, User $user): JobApplication
    {
        Gate::forUser($user)->authorize('update', $application);

        $note = PlainText::clean((string) $note, multiline: true);

        if (mb_strlen($note) > self::NOTE_MAX) {
            throw ValidationException::withMessages(['internalNote' => __('validation.max.string', ['attribute' => __('careers.admin.applications.note'), 'max' => self::NOTE_MAX])]);
        }

        return DB::transaction(function () use ($application, $status, $note, $user): JobApplication {
            $current = JobApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            $from = $current->status;
            $noteChanged = (string) $current->internal_note !== $note;

            $current->status = $status;
            $current->internal_note = $note !== '' ? $note : null;

            if ($from !== $status) {
                $current->status_changed_at = now();
            }

            $current->save();

            if ($from !== $status) {
                $current->activities()->create([
                    'user_id' => $user->id,
                    'type' => ActivityType::StatusChange,
                    'meta' => ['from' => $from->value, 'to' => $status->value],
                    'created_at' => now(),
                ]);
            }

            if ($noteChanged) {
                $current->activities()->create([
                    'user_id' => $user->id,
                    'type' => ActivityType::Note,
                    'meta' => ['internal_note' => 'updated'],
                    'created_at' => now(),
                ]);
            }

            return $current;
        });
    }
}
