<?php

namespace App\Policies;

use App\Enums\QuoteStatus;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\User;

/**
 * Quote access by permission (quotes.*) plus the record rule in
 * Quote::isVisibleTo() / isEditableBy(), which the list scopes mirror.
 * Admins pass through Gate::before.
 */
class QuotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('quotes.viewAny');
    }

    public function view(User $user, Quote $quote): bool
    {
        return $quote->isVisibleTo($user);
    }

    /**
     * Create a quote (for a customer the user can see).
     */
    public function create(User $user, ?Customer $customer = null): bool
    {
        return $user->can('quotes.create') && ($customer === null || $user->can('view', $customer));
    }

    /**
     * Edit items, validity and notes: only while draft or sent.
     */
    public function update(User $user, Quote $quote): bool
    {
        return $quote->status->isEditable() && $quote->isEditableBy($user);
    }

    /**
     * Mark sent / accepted / rejected / expired.
     */
    public function changeStatus(User $user, Quote $quote): bool
    {
        return $quote->isEditableBy($user);
    }

    public function duplicate(User $user, Quote $quote): bool
    {
        return $this->view($user, $quote) && $user->can('quotes.create');
    }

    /**
     * Only drafts are deleted; sent quotes are kept as history.
     */
    public function delete(User $user, Quote $quote): bool
    {
        return $user->can('quotes.delete') && $quote->status === QuoteStatus::Draft && $quote->isEditableBy($user);
    }
}
