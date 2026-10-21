<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

/**
 * Category access by permission only (categories.*); no role checks here.
 * Admins pass through Gate::before. update/delete also accept the class
 * (can('update', Category::class)) for list-level buttons.
 */
class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('categories.viewAny');
    }

    public function view(User $user, Category $category): bool
    {
        return $user->can('categories.view');
    }

    public function create(User $user): bool
    {
        return $user->can('categories.create');
    }

    public function update(User $user, ?Category $category = null): bool
    {
        return $user->can('categories.update');
    }

    public function delete(User $user, ?Category $category = null): bool
    {
        return $user->can('categories.delete');
    }
}
