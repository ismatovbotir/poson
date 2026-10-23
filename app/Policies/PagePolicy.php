<?php

namespace App\Policies;

use App\Models\Page;
use App\Models\User;

/**
 * Page access by permission only (pages.*: Content manager and Admin, plan 07).
 * System pages are never deletable. Admins pass through Gate::before, so
 * DeletePage enforces the system-page rule again for everyone.
 */
class PagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('pages.viewAny');
    }

    public function view(User $user, Page $page): bool
    {
        return $user->can('pages.view');
    }

    public function create(User $user): bool
    {
        return $user->can('pages.create');
    }

    public function update(User $user, ?Page $page = null): bool
    {
        return $user->can('pages.update');
    }

    public function delete(User $user, ?Page $page = null): bool
    {
        return $user->can('pages.delete') && ! $page?->is_system;
    }
}
