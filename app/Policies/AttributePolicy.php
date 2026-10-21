<?php

namespace App\Policies;

use App\Models\Attribute;
use App\Models\User;

/**
 * Attribute access by permission only (attributes.*); no role checks here.
 * Admins pass through Gate::before. update/delete also accept the class
 * (can('update', Category::class)) for list-level buttons.
 *
 * Attribute options have no policy of their own: creating, editing or
 * deleting an option is an update of its attribute (attributes.update).
 */
class AttributePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('attributes.viewAny');
    }

    public function view(User $user, Attribute $attribute): bool
    {
        return $user->can('attributes.view');
    }

    public function create(User $user): bool
    {
        return $user->can('attributes.create');
    }

    public function update(User $user, ?Attribute $attribute = null): bool
    {
        return $user->can('attributes.update');
    }

    public function delete(User $user, ?Attribute $attribute = null): bool
    {
        return $user->can('attributes.delete');
    }
}
