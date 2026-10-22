<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

/**
 * Customer access by permission (customers.*), plan 05 §5; the screens come
 * in 5b. Every staff role with customers.viewAny sees all customers; editing
 * is limited to the user's own and unassigned customers unless they have
 * customers.update-any. The price level is Admin only (customers.price-level).
 * Admins pass through Gate::before.
 */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('customers.viewAny');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->can('customers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('customers.create');
    }

    public function update(User $user, Customer $customer): bool
    {
        if ($user->can('customers.update-any')) {
            return true;
        }

        return $user->can('customers.update')
            && ($customer->manager_id === null || $customer->manager_id === $user->id);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->can('customers.delete');
    }

    public function changePriceLevel(User $user, ?Customer $customer = null): bool
    {
        return $user->can('customers.price-level');
    }
}
