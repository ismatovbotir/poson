<?php

namespace App\Actions\Careers;

use App\Data\Careers\JobApplicationData;
use App\Enums\ActivityType;
use App\Enums\JobApplicationCvStatus;
use App\Enums\JobApplicationStatus;
use App\Jobs\ForwardJobApplicationCv;
use App\Jobs\NotifyJobApplication;
use App\Models\Activity;
use App\Models\JobApplication;
use App\Models\Vacancy;
use App\Support\Careers\CareersAlerts;
use App\Support\Careers\CareersCaps;
use App\Support\Careers\CareersCv;
use App\Support\Careers\CvFile;
use App\Support\Careers\PendingCv;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Store a job application (plan 31 §5, CR-3 / CR-5 / CR-6), in one
 * transaction:
 *  - a vacancy application locks the vacancy row (DeleteVacancy locks the
 *    same row) and copies its department; an open application takes the
 *    department from the route;
 *  - with an inspected CV, CareersCv available and every site-wide cap
 *    holding: the CV is written as an encrypted pending file,
 *    cv_status = pending, and ForwardJobApplicationCv is queued once the
 *    transaction committed; otherwise the CV is dropped (cv_status = none, a cap → one
 *    counts-only alert) and the id-only NotifyJobApplication is queued, except over the
 * hourly application cap: the row is saved, no per-application message, one cap alert.
 * A failure after the pending file was written deletes the file again.
 *
 * @throws ModelNotFoundException when the vacancy is gone
 */
class SubmitJobApplication
{
    public function handle(JobApplicationData $data, ?CvFile $cv = null): JobApplication
    {
        $withinCaps = CareersCaps::applicationAllowed();
        $keepCv = false;

        if (! $withinCaps) {
            CareersAlerts::once('cap');
        } elseif ($cv !== null && CareersCv::isAvailable()) {
            $keepCv = CareersCaps::cvAllowed();

            if (! $keepCv) {
                CareersAlerts::once('cap');
            }
        }

        $pendingPath = null;

        try {
            $application = DB::transaction(function () use ($data, $cv, $keepCv, &$pendingPath): JobApplication {
                $department = $data->department;

                if ($data->vacancyId !== null) {
                    $vacancy = Vacancy::query()->whereKey($data->vacancyId)->lockForUpdate()->firstOrFail();
                    $department = $vacancy->department;
                }

                if ($keepCv && $cv !== null) {
                    $pendingPath = PendingCv::write($cv->bytes);
                }

                $application = JobApplication::query()->create([
                    'vacancy_id' => $data->vacancyId,
                    'department' => $department,
                    'status' => JobApplicationStatus::New,
                    'name' => $data->name,
                    'phone' => $data->phone,
                    'email' => $data->email,
                    'message' => $data->message,
                    'locale' => $data->locale,
                    'cv_status' => $pendingPath !== null ? JobApplicationCvStatus::Pending : JobApplicationCvStatus::None,
                    'cv_pending_path' => $pendingPath,
                    'cv_original_name' => $pendingPath !== null ? $cv?->originalName : null,
                    'cv_mime' => $pendingPath !== null ? $cv?->mime : null,
                    'cv_size' => $pendingPath !== null ? $cv?->size : null,
                ]);

                Activity::query()->create([
                    'subject_type' => $application->getMorphClass(),
                    'subject_id' => $application->id,
                    'type' => ActivityType::Created,
                    'created_at' => now(),
                ]);

                return $application;
            });
        } catch (Throwable $exception) {
            if ($pendingPath !== null) {
                PendingCv::delete($pendingPath);
            }

            throw $exception;
        }

        // Outside the try: a failing (sync) job must never delete the file of a committed row.
        // Both jobs are ShouldQueueAfterCommit, so an outer transaction still defers them.
        if ($pendingPath !== null) {
            ForwardJobApplicationCv::dispatch($application->id);
        } elseif ($withinCaps) {
            // Over the hourly application cap the row is saved without a per-application message (CR-3).
            NotifyJobApplication::dispatch($application->id);
        }

        return $application;
    }
}
