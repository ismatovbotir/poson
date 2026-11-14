<?php

namespace App\Actions\Careers;

use App\Models\Vacancy;
use Illuminate\Validation\ValidationException;

/**
 * Publish or unpublish a vacancy (plan 31). Publishing is refused unless the
 * vacancy is complete in every locale (title, slug, summary, description).
 * published_at is set on the first publication only (JSON-LD datePosted).
 */
class PublishVacancy
{
    /**
     * @throws ValidationException
     */
    public function handle(Vacancy $vacancy, bool $publish): Vacancy
    {
        if ($publish && ! $vacancy->isComplete()) {
            throw ValidationException::withMessages(['is_published' => __('careers.admin.errors.incomplete')]);
        }

        $vacancy->is_published = $publish;

        if ($publish && $vacancy->published_at === null) {
            $vacancy->published_at = now();
        }

        $vacancy->save();

        return $vacancy;
    }
}
