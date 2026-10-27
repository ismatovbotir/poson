<?php

namespace App\Policies;

use App\Models\RollType;
use App\Models\User;

/**
 * Roll type access by permission only (roll-types.*, plan 08); Admin and
 * Content manager manage them, Sales manager and Service engineer view.
 * Admins pass through Gate::before. update/delete also accept the class
 * for list-level buttons. Attaching roll types to a product is a product
 * edit (products.update, ProductPolicy).
 */
class RollTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('roll-types.viewAny');
    }

    public function view(User $user, RollType $rollType): bool
    {
        return $user->can('roll-types.view');
    }

    public function create(User $user): bool
    {
        return $user->can('roll-types.create');
    }

    public function update(User $user, ?RollType $rollType = null): bool
    {
        return $user->can('roll-types.update');
    }

    public function delete(User $user, ?RollType $rollType = null): bool
    {
        return $user->can('roll-types.delete');
    }
}
