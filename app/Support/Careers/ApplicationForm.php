<?php

namespace App\Support\Careers;

use App\Enums\VacancyDepartment;
use App\Models\Vacancy;

/**
 * View data of the careers application form (plan 31): whether it is open
 * (kill switches), the POST URL (route() from the model / enum), a fresh
 * bound FormToken and whether the CV field is shown (CareersCv::isAvailable()).
 *
 * @phpstan-type FormData array{enabled: bool, action: string, token: string, cv: bool}
 */
final class ApplicationForm
{
    /**
     * @return array{enabled: bool, action: string, token: string, cv: bool}
     */
    public static function forVacancy(Vacancy $vacancy): array
    {
        $locale = app()->getLocale();

        return [
            'enabled' => CareersSwitch::applicationsEnabled(),
            'action' => route('site.careers.apply', ['locale' => $locale, 'slug' => $vacancy->slugFor($locale) ?? $vacancy->slug_uz]),
            'token' => FormToken::issue(['vacancy_id' => $vacancy->id]),
            'cv' => CareersCv::isAvailable(),
        ];
    }

    /**
     * @return array{enabled: bool, action: string, token: string, cv: bool}
     */
    public static function forDepartment(VacancyDepartment $department): array
    {
        return [
            'enabled' => CareersSwitch::openApplicationsEnabled(),
            'action' => route('site.careers.open.apply', ['locale' => app()->getLocale(), 'department' => $department->value]),
            'token' => FormToken::issue(['department' => $department->value]),
            'cv' => CareersCv::isAvailable(),
        ];
    }
}
