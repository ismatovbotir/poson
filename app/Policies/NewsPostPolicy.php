<?php

namespace App\Policies;

use App\Models\NewsPost;
use App\Models\User;

/**
 * News access by permission only (news.*: Content manager and Admin, plan 07;
 * Sales manager and Service engineer have none). Admins pass Gate::before.
 */
class NewsPostPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('news.viewAny');
    }

    public function view(User $user, NewsPost $newsPost): bool
    {
        return $user->can('news.view');
    }

    public function create(User $user): bool
    {
        return $user->can('news.create');
    }

    public function update(User $user, ?NewsPost $newsPost = null): bool
    {
        return $user->can('news.update');
    }

    public function delete(User $user, ?NewsPost $newsPost = null): bool
    {
        return $user->can('news.delete');
    }
}
