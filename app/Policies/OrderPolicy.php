<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Order;
use App\Models\User;

/**
 * Order access by permission (orders.*, payments.*; plan 11 v2 §8) plus the
 * record rule in Order::isVisibleTo(), which Order::visibleTo() mirrors:
 * every real order for orders.viewAny + view, TEST orders only with
 * payments.test.
 *
 * Admins pass through Gate::before, so the state rules (what can change
 * when) live in the order actions, which check them for every user.
 */
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('orders.viewAny');
    }

    public function view(User $user, Order $order): bool
    {
        return $order->isVisibleTo($user);
    }

    /**
     * Create a manual order in admin.
     */
    public function create(User $user): bool
    {
        return $user->can('orders.create');
    }

    /**
     * Create TEST orders (Admin, plan 11 v2 §1.8).
     */
    public function createTest(User $user): bool
    {
        return $user->can('orders.create') && $user->can('payments.test');
    }

    /**
     * Edit details, delivery, lines, payment method, status; add / cancel
     * delivery charges; reissue the invoice; renew the public link.
     */
    public function update(User $user, Order $order): bool
    {
        return $this->view($user, $order) && $user->can('orders.update');
    }

    /**
     * Payment panel: rows, references, UzQR, invoice (not the service engineer, condition 30).
     */
    public function viewPayments(User $user, Order $order): bool
    {
        return $this->view($user, $order) && $user->can('payments.view');
    }

    /**
     * Mark a manual payment (goods or delivery charge) paid: Sales + Admin.
     */
    public function markPaid(User $user, Order $order): bool
    {
        return $this->viewPayments($user, $order) && $user->can('orders.mark-paid');
    }

    /**
     * Record a refund (non-Payme), Admin only.
     */
    public function refund(User $user, Order $order): bool
    {
        return $this->viewPayments($user, $order) && $user->can('orders.refund');
    }

    /**
     * Void a manual payment (condition 22): Admin only.
     */
    public function voidPayment(User $user, Order $order): bool
    {
        return $this->viewPayments($user, $order) && ($user->can('orders.refund') || $user->can('payments.void'));
    }

    /**
     * Dynamic UzQR "Check status now" (condition 45): payment viewers (not the service engineer).
     */
    public function checkUzqr(User $user, Order $order): bool
    {
        return $this->viewPayments($user, $order);
    }

    /**
     * Cancel an open UzQR invoice at the bank (condition 39): staff who edit orders.
     */
    public function cancelUzqr(User $user, Order $order): bool
    {
        return $this->update($user, $order) && $this->viewPayments($user, $order);
    }

    /**
     * Resolve an unmatched UzQR payment / confirm its fiscal receipt (conditions 47, 50): Admin only.
     */
    public function resolveUzqr(User $user, Order $order): bool
    {
        return $this->viewPayments($user, $order) && $user->can('payments.reconcile');
    }

    /**
     * Release the hold of an expired UzQR invoice the bank never confirmed
     * (condition 58): the Admin role explicitly, never a permission grant.
     */
    public function releaseUzqrHold(User $user, Order $order): bool
    {
        return $user->hasRole(Role::Admin) && $this->viewPayments($user, $order);
    }

    /**
     * Show a test UzQR for a TEST order (condition 45): payments.test.
     */
    public function testPayUzqr(User $user, Order $order): bool
    {
        return $order->is_test && $this->view($user, $order) && $user->can('payments.test');
    }

    /**
     * Only TEST orders are ever deleted (DeleteTestOrder re-checks).
     */
    public function delete(User $user, Order $order): bool
    {
        return $order->is_test && $this->view($user, $order) && $user->can('payments.test');
    }
}
