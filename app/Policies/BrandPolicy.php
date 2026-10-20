<?php

namespace App\Policies;

use App\Models\Brand;
use App\Models\User;

/**
 * Brand access by permission only (brands.*); no role checks here.
 * Admins pass through Gate::before.
 */
class BrandPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('brands.viewAny');
    }

    public function view(User $user, Brand $brand): bool
    {
        return $user->can('brands.view');
    }

    public function create(User $user): bool
    {
        return $user->can('brands.create');
    }

    public function update(User $user, Brand $brand): bool
    {
        return $user->can('brands.update');
    }

    public function delete(User $user, Brand $brand): bool
    {
        return $user->can('brands.delete');
    }
}
