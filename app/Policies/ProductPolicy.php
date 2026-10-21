<?php

namespace App\Policies;

use App\Enums\ProductType;
use App\Models\Product;
use App\Models\User;

/**
 * Product access by permission only (products.*); no role checks here.
 * Admins pass through Gate::before.
 *
 * Record rule (decisions 2026-10-20): a write is allowed with the general
 * permission (products.create / update / delete), OR with
 * products.manage-services when the product type is software or service
 * (ProductType::isServiceManaged()). Without a record (class-level checks
 * for list buttons) manage-services counts as "may write some products";
 * the record is checked again before every write.
 *
 * forceDelete (permanent delete from the trash) needs products.force-delete.
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('products.viewAny');
    }

    public function view(User $user, Product $product): bool
    {
        return $user->can('products.view');
    }

    public function create(User $user, ?ProductType $type = null): bool
    {
        return $this->allows($user, 'products.create', $type);
    }

    public function update(User $user, ?Product $product = null): bool
    {
        return $this->allows($user, 'products.update', $product?->type);
    }

    public function delete(User $user, ?Product $product = null): bool
    {
        return $this->allows($user, 'products.delete', $product?->type);
    }

    /**
     * Taking a product out of the trash: whoever may delete it.
     */
    public function restore(User $user, ?Product $product = null): bool
    {
        return $this->delete($user, $product);
    }

    public function forceDelete(User $user, ?Product $product = null): bool
    {
        return $user->can('products.force-delete');
    }

    /**
     * Whether the user may give a product this type (create, or change the
     * type on edit): a service engineer only software and service.
     */
    public function useType(User $user, ProductType $type): bool
    {
        return $user->can('products.create')
            || $user->can('products.update')
            || ($user->can('products.manage-services') && $type->isServiceManaged());
    }

    private function allows(User $user, string $permission, ?ProductType $type): bool
    {
        if ($user->can($permission)) {
            return true;
        }

        return $user->can('products.manage-services') && ($type === null || $type->isServiceManaged());
    }
}
