<?php

namespace App\Actions\Catalog\Ai;

use App\Enums\ProductType;
use App\Models\AiProductDraft;
use App\Models\Product;
use App\Models\User;
use App\Support\Chat\AiSwitch;
use Illuminate\Support\Facades\Gate;

/**
 * Who may run the AI product wizard (plan 32 §5, PW-6, PW-7): an active
 * staff user with ai.generate, the wizard switch on (with a key), and
 * create → "create" on Product (with the step 1 type, useType);
 * improve → "update" on the FRESH product + useType of its type.
 * Used by the component on every method and by the job before the call and
 * again before storing (Gate::forUser, the draft's own user).
 */
final class AiWizardGate
{
    public static function allowsUser(User $user): bool
    {
        return $user->isStaff() && Gate::forUser($user)->allows('ai.generate') && AiSwitch::adminEnabled();
    }

    public static function allowsCreate(User $user, ?ProductType $type = null): bool
    {
        $gate = Gate::forUser($user);

        return self::allowsUser($user)
            && $gate->allows('create', Product::class)
            && ($type === null || $gate->allows('useType', [Product::class, $type]));
    }

    public static function allowsImprove(User $user, ?Product $product): bool
    {
        return self::allowsUser($user) && self::allowsProduct($user, $product);
    }

    /**
     * The product part of allowsImprove() (update + useType), for lists that
     * checked allowsUser() once (CR nit: Products\Index).
     */
    public static function allowsProduct(User $user, ?Product $product): bool
    {
        if ($product === null || $product->trashed()) {
            return false;
        }

        $gate = Gate::forUser($user);

        return $gate->allows('update', $product) && $gate->allows('useType', [Product::class, $product->type]);
    }

    /**
     * The draft's user may still run it (job re-check, PW-7).
     */
    public static function allowsDraft(AiProductDraft $draft): bool
    {
        $user = User::query()->find($draft->user_id);

        if ($user === null) {
            return false;
        }

        if ($draft->isImprove()) {
            return self::allowsImprove($user, $draft->product_id !== null ? Product::query()->find($draft->product_id) : null);
        }

        return self::allowsCreate($user, ProductType::tryFrom((string) ($draft->input['info']['type'] ?? '')));
    }
}
