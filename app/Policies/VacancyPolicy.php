<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vacancy;

/**
 * Vacancy access by one permission, careers.vacancies (plan 31, Admin only
 * for now). Deleting is also blocked by DeleteVacancy while applications exist.
 */
class VacancyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('careers.vacancies');
    }

    public function view(User $user, Vacancy $vacancy): bool
    {
        return $user->can('careers.vacancies');
    }

    public function create(User $user): bool
    {
        return $user->can('careers.vacancies');
    }

    public function update(User $user, ?Vacancy $vacancy = null): bool
    {
        return $user->can('careers.vacancies');
    }

    public function delete(User $user, ?Vacancy $vacancy = null): bool
    {
        return $user->can('careers.vacancies');
    }
}
