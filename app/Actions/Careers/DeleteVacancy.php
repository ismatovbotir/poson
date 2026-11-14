<?php

namespace App\Actions\Careers;

use App\Models\Vacancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Delete a vacancy (plan 31). Blocked while it has applications: the
 * candidates' data is deleted by hand first (job_applications.vacancy_id is
 * restrictOnDelete); unpublish the vacancy instead. The check and the delete
 * run in one transaction with the vacancy row locked (SubmitJobApplication
 * locks the same row), and a foreign-key refusal from a racing insert is the
 * same validation error.
 */
class DeleteVacancy
{
    /**
     * @throws ValidationException
     */
    public function handle(Vacancy $vacancy): void
    {
        try {
            DB::transaction(function () use ($vacancy): void {
                $locked = Vacancy::query()->whereKey($vacancy->getKey())->lockForUpdate()->first();

                if ($locked === null) {
                    return;
                }

                if ($locked->applications()->exists()) {
                    throw ValidationException::withMessages(['vacancy' => __('careers.admin.errors.has_applications')]);
                }

                $locked->delete();
            });
        } catch (QueryException) {
            throw ValidationException::withMessages(['vacancy' => __('careers.admin.errors.has_applications')]);
        }
    }
}
