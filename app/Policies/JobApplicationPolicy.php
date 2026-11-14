<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\JobApplication;
use App\Models\User;

/**
 * Job application access (plan 31, CR-11): careers.applications for the
 * list, details, status and delete; downloadCv needs careers.cv AND the
 * Admin role (a permission handed to another role by mistake is not enough).
 */
class JobApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('careers.applications');
    }

    public function view(User $user, JobApplication $jobApplication): bool
    {
        return $user->can('careers.applications');
    }

    public function update(User $user, ?JobApplication $jobApplication = null): bool
    {
        return $user->can('careers.applications');
    }

    public function delete(User $user, ?JobApplication $jobApplication = null): bool
    {
        return $user->can('careers.applications');
    }

    public function downloadCv(User $user, ?JobApplication $jobApplication = null): bool
    {
        return $user->can('careers.cv') && $user->hasRole(Role::Admin->value);
    }
}
