<?php

namespace App\Policies;

use App\Models\Repair;
use App\Models\User;

/**
 * Repair access by permission (repairs.*) plus the record rule in
 * Repair::isVisibleTo() / isEditableBy() (App\Support\Repairs\RepairAccess,
 * shared with the list scopes, so index = direct URL = receipt). No role
 * checks here; Admins pass through Gate::before.
 *
 * Engineers take an unassigned repair and release their own; picking a
 * person (assign) is repairs.assign (Admin). Costs: viewing needs
 * repairs.costs, editing them also needs the right to edit the repair.
 * Repairs are never deleted (cancel instead), so there is no delete ability.
 */
class RepairPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('repairs.viewAny');
    }

    public function view(User $user, Repair $repair): bool
    {
        return $repair->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $user->can('repairs.create');
    }

    /**
     * Edit, change the status, add notes.
     */
    public function update(User $user, Repair $repair): bool
    {
        return $repair->isEditableBy($user);
    }

    public function take(User $user, Repair $repair): bool
    {
        return $repair->assigned_to_id === null && $repair->isOpen() && $this->update($user, $repair);
    }

    public function release(User $user, Repair $repair): bool
    {
        return $repair->isAssignedTo($user) && $repair->isOpen() && $this->update($user, $repair);
    }

    /**
     * Pick an engineer (not just take / release).
     */
    public function assign(User $user, Repair $repair): bool
    {
        return $repair->isOpen() && $this->update($user, $repair) && $user->can('repairs.assign');
    }

    public function viewCosts(User $user, Repair $repair): bool
    {
        return $user->can('repairs.costs') && $this->view($user, $repair);
    }

    public function editCosts(User $user, Repair $repair): bool
    {
        return $user->can('repairs.costs') && $this->update($user, $repair);
    }
}
