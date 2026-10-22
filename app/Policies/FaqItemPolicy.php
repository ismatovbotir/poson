<?php

namespace App\Policies;

use App\Enums\SettingsArea;
use App\Models\Category;
use App\Models\FaqItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * FAQ access follows the owner (plan 04 T12), by permission only:
 * category FAQ = the category policy (categories.view / categories.update),
 * product FAQ = the product policy (products.view / products.update, or
 * products.manage-services for software and services), general FAQ =
 * Settings → Company (settings.company, Admin only).
 *
 * Usage: authorize('viewAny', [FaqItem::class, $owner]),
 * authorize('create', [FaqItem::class, $owner]), authorize('update', $item).
 */
class FaqItemPolicy
{
    public function viewAny(User $user, ?Model $owner = null): bool
    {
        return match (true) {
            $owner instanceof Category, $owner instanceof Product => $user->can('view', $owner),
            $owner === null => $this->manageGeneral($user),
            default => false,
        };
    }

    public function create(User $user, ?Model $owner = null): bool
    {
        return $this->manage($user, $owner);
    }

    public function update(User $user, FaqItem $faqItem): bool
    {
        return $this->manage($user, $this->ownerOf($faqItem));
    }

    public function delete(User $user, FaqItem $faqItem): bool
    {
        return $this->update($user, $faqItem);
    }

    /**
     * Whether the user may add, edit, reorder or delete the FAQ of an owner.
     */
    public function manage(User $user, ?Model $owner = null): bool
    {
        return match (true) {
            $owner instanceof Category, $owner instanceof Product => $user->can('update', $owner),
            $owner === null => $this->manageGeneral($user),
            default => false,
        };
    }

    private function manageGeneral(User $user): bool
    {
        return $user->can('manageArea', [Setting::class, SettingsArea::Faq]);
    }

    /**
     * The owner model (trashed products included), or null for a general item.
     */
    private function ownerOf(FaqItem $faqItem): ?Model
    {
        if ($faqItem->isGeneral()) {
            return null;
        }

        $owner = $faqItem->faqable_type === (new Product)->getMorphClass()
            ? Product::withTrashed()->find($faqItem->faqable_id)
            : $faqItem->faqable;

        // An item whose owner is gone can't be managed through it.
        return $owner ?? new FaqItem;
    }
}
