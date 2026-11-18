<?php

namespace App\Policies;

use App\Enums\LeadType;
use App\Models\Lead;
use App\Models\User;

/**
 * Lead access by permission (leads.*) plus the record rule in
 * Lead::isVisibleTo() / isEditableBy(), which the list scopes visibleTo /
 * editableBy mirror (index = direct URL). No role checks here; admins
 * pass through Gate::before.
 *
 * Assignment (decisions 2026-10-22): everyone who may edit a lead can take
 * an unassigned one and release their own. "assign" (pick a person) needs
 * leads.assign (anyone eligible) or leads.reassign (sales leads, to another
 * sales user; AssignLead::candidates()).
 */
class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('leads.viewAny');
    }

    public function view(User $user, Lead $lead): bool
    {
        return $user->can('leads.view') && $lead->isVisibleTo($user);
    }

    /**
     * Without a type: may create leads of some type (list buttons).
     */
    public function create(User $user, ?LeadType $type = null): bool
    {
        if (! $user->can('leads.create')) {
            return false;
        }

        if ($type !== null) {
            return $this->useType($user, $type);
        }

        return $user->can('leads.sales') || $user->can('leads.service');
    }

    /**
     * Edit, change the status, add notes and calls.
     */
    public function update(User $user, Lead $lead): bool
    {
        return $lead->isEditableBy($user);
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $user->can('leads.delete');
    }

    public function take(User $user, Lead $lead): bool
    {
        return $lead->manager_id === null && $this->update($user, $lead);
    }

    public function release(User $user, Lead $lead): bool
    {
        return $lead->isAssignedTo($user) && $this->update($user, $lead);
    }

    /**
     * Pick a manager for the lead (not just take / release).
     */
    public function assign(User $user, Lead $lead): bool
    {
        if (! $this->update($user, $lead)) {
            return false;
        }

        return $user->can('leads.assign') || ($user->can('leads.reassign') && $lead->type->isSales());
    }

    /**
     * Convert an open lead to a customer (link an existing one). Creating a
     * new customer also needs CustomerPolicy::create.
     *
     * A lead converted by a repair (plan 36) is no longer open, so a customer
     * can no longer be linked to it: the repair carries the customer (owner
     * decision Q4, MED-8).
     */
    public function convert(User $user, Lead $lead): bool
    {
        return $lead->isOpen() && $this->update($user, $lead) && $user->can('customers.viewAny');
    }

    /**
     * Take a device in from a service request (plan 36): a service-type lead
     * that is not lost, edited by the user (engineer: own or unassigned;
     * Admin through Gate::before). A converted lead still qualifies (several
     * repairs per lead).
     */
    public function createRepair(User $user, Lead $lead): bool
    {
        return $lead->acceptsRepair()
            && $user->can('repairs.create')
            && $this->update($user, $lead);
    }

    /**
     * Whether the user may give a lead this type (create, or change it on edit).
     */
    public function useType(User $user, LeadType $type): bool
    {
        return $user->can($type->workPermission());
    }

    /**
     * The "Service requests" menu entry and redirect.
     */
    public function viewServiceRequests(User $user): bool
    {
        return $user->can('leads.viewAny') && ($user->can('leads.service') || $user->can('leads.service-view'));
    }
}
