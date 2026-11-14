<?php

namespace App\Policies;

use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Models\User;

/**
 * Company settings access by permission only (plan 04 §6); no role checks.
 * settings.company opens Settings → Company; the Legal and Tracking tabs
 * (and Integrations) also need settings.integrations. Both are admin-only
 * in the seeder; admins pass through Gate::before anyway.
 *
 * Usage: $this->authorize('manageCompany', Setting::class),
 * $this->authorize('manageArea', [Setting::class, SettingsArea::Legal]).
 */
class SettingPolicy
{
    public function manageCompany(User $user): bool
    {
        return $user->can('settings.company');
    }

    public function manageIntegrations(User $user): bool
    {
        return $user->can('settings.integrations');
    }

    /**
     * Settings → Checkout (plan 11b): delivery fees, caps, IKPU, UzQR.
     */
    public function manageCheckout(User $user): bool
    {
        return $user->can('settings.checkout');
    }

    /**
     * Careers kill switches (plan 31, CR-3 / CR-11): Admin only.
     */
    public function manageCareers(User $user): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public function manageArea(User $user, SettingsArea $area): bool
    {
        if ($area === SettingsArea::Integrations) {
            return $this->manageIntegrations($user);
        }

        if ($area === SettingsArea::Checkout) {
            return $this->manageCheckout($user);
        }

        if ($area === SettingsArea::Careers) {
            return $this->manageCareers($user);
        }

        return $this->manageCompany($user) && (! $area->isRestricted() || $this->manageIntegrations($user));
    }
}
