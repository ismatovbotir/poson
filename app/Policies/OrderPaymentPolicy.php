<?php

namespace App\Policies;

use App\Models\OrderPayment;
use App\Models\User;

/**
 * Payments list (plan 11 v2 §8): payments.viewAny (Admin, sales manager);
 * the "reconciled" tick is Admin only (payments.reconcile, condition 21).
 * TEST payments are visible only with payments.test.
 */
class OrderPaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('payments.viewAny');
    }

    public function view(User $user, OrderPayment $payment): bool
    {
        return $user->can('payments.view') && (! $payment->is_test || $user->can('payments.test'));
    }

    public function reconcile(User $user, OrderPayment $payment): bool
    {
        return $this->view($user, $payment) && $user->can('payments.reconcile');
    }
}
