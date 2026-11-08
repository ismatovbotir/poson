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
     * Edit, change the status, approve. A closed repair accepts no changes
     * (notes aside, see note()).
     */
    public function update(User $user, Repair $repair): bool
    {
        return $repair->isOpen() && $repair->isEditableBy($user);
    }

    /**
     * A note on the timeline: on an open repair whoever may update it; on a
     * closed one only the assigned engineer and those who assign (Admin).
     */
    public function note(User $user, Repair $repair): bool
    {
        if ($repair->isOpen()) {
            return $this->update($user, $repair);
        }

        return $repair->isEditableBy($user) && ($repair->isAssignedTo($user) || $user->can('repairs.assign'));
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

    /**
     * Record the customer's approval of the estimate: needs the costs rights
     * and the repair assigned to the user (Admin passes through Gate::before).
     */
    public function approveEstimate(User $user, Repair $repair): bool
    {
        return $this->editCosts($user, $repair) && $repair->isAssignedTo($user);
    }

    public function editCosts(User $user, Repair $repair): bool
    {
        return $user->can('repairs.costs') && $this->update($user, $repair);
    }
}
