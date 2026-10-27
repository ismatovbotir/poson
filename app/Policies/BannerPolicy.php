<?php

namespace App\Policies;

use App\Models\Banner;
use App\Models\User;

/**
 * Banner access by permission only (banners.*: Content manager and Admin,
 * plan 07; Sales manager and Service engineer have none). Admins pass Gate::before.
 */
class BannerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('banners.viewAny');
    }

    public function view(User $user, Banner $banner): bool
    {
        return $user->can('banners.view');
    }

    public function create(User $user): bool
    {
        return $user->can('banners.create');
    }

    public function update(User $user, ?Banner $banner = null): bool
    {
        return $user->can('banners.update');
    }

    public function delete(User $user, ?Banner $banner = null): bool
    {
        return $user->can('banners.delete');
    }
}
