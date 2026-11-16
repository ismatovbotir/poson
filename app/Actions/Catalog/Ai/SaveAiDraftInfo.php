<?php

namespace App\Actions\Catalog\Ai;

use App\Enums\AiDraftStatus;
use App\Models\AiProductDraft;
use App\Models\Product;
use App\Models\User;
use App\Support\Content\AiProduct\AiWritePrompt;
use App\Support\Money;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Step 1 of the AI wizard (plan 32 §5): open the draft row on the first
 * "Next" (uuid, owner, mode, product, expiry; improve: the product's
 * existing images as server-side entries, the step 1 values and the field
 * snapshot taken when the PAGE opened (info_base, snapshot; L10, PW-21) —
 * refused with a conflict when the product's step 1 values changed since),
 * or store the edited main info and notes on an open draft. The caller has
 * validated the info with the product form rules and authorized the user.
 * Notes are cleaned (format characters stripped) and cut to notes_max (PW-18).
 * A draft whose stage is running or that is applied / expired is not changed.
 * At most chatbot.ai.product.open_drafts_user open drafts per user (L9),
 * counted and inserted under the user's start lock (N7).
 */
class SaveAiDraftInfo
{
    public function __construct(private readonly PrepareAiDraftImages $images) {}

    /**
     * @param  array<string, mixed>  $info
     * @param  array{info?: array<string, string>, snapshot?: array<string, string>}|null  $opened  improve: the baseline from page open
     *
     * @throws ValidationException
     */
    public function handle(User $user, ?AiProductDraft $draft, string $mode, ?Product $product, array $info, string $notes, ?array $opened = null): AiProductDraft
    {
        $info = AiDraftProposal::info($info);
        $notes = AiWritePrompt::notes($notes);

        if ($draft === null) {
            // The count and the insert as one step under the user's start lock (N7: two tabs never pass the limit together).
            try {
                return Cache::lock(StartAiDraftStage::userLockKey($user), 60)->block(5, fn (): AiProductDraft => $this->open($user, $mode, $product, $info, $notes, $opened));
            } catch (LockTimeoutException) {
                throw ValidationException::withMessages(['draft' => __('admin.ai_wizard.errors.busy')]);
            }
        }

        // Under the per-draft lock on a fresh row (M4): an image added meanwhile is never lost.
        try {
            $updated = AiDraftLock::run($draft->id, function () use ($draft, $user, $info, $notes): bool {
                $draft->refresh();

                return $draft->user_id === $user->id && AiDraftLock::writeInput($draft, [...$draft->input, 'info' => $info, 'notes' => $notes]);
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['draft' => __('admin.ai_wizard.errors.busy')]);
        }

        if (! $updated) {
            throw ValidationException::withMessages(['draft' => __('admin.ai_wizard.errors.state')]);
        }

        return $draft->refresh();
    }

    /**
     * Open a new draft (the caller holds the user's start lock).
     *
     * @param  array<string, string>  $info
     * @param  array{info?: array<string, string>, snapshot?: array<string, string>}|null  $opened
     *
     * @throws ValidationException
     */
    private function open(User $user, string $mode, ?Product $product, array $info, string $notes, ?array $opened): AiProductDraft
    {
        $improve = $mode === AiProductDraft::IMPROVE && $product !== null;
        $max = max(1, (int) config('chatbot.ai.product.open_drafts_user', 5));

        $open = AiProductDraft::query()->where('user_id', $user->id)
            ->whereNotIn('status', AiDraftStatus::values([AiDraftStatus::Applied, AiDraftStatus::Expired]))
            ->where('expires_at', '>', now())
            ->count();

        if ($open >= $max) {
            throw ValidationException::withMessages(['draft' => __('admin.ai_wizard.errors.too_many_drafts', ['max' => $max])]);
        }

        $base = $improve ? self::baseInfo($product) : $info;

        if ($improve && is_array($opened['info'] ?? null) && AiDraftProposal::info($opened['info']) !== $base) {
            throw ValidationException::withMessages(['draft' => __('admin.ai_wizard.errors.conflict')]);
        }

        $snapshot = $improve ? (is_array($opened['snapshot'] ?? null) && $opened['snapshot'] !== [] ? $opened['snapshot'] : AiDraftSnapshot::of($product)) : null;

        return AiProductDraft::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'mode' => $improve ? AiProductDraft::IMPROVE : AiProductDraft::CREATE,
            'product_id' => $improve ? $product->id : null,
            'status' => AiDraftStatus::Open,
            'input' => [
                'info' => $info,
                'info_base' => $base,
                'notes' => $notes,
                'images' => $improve ? $this->images->existingEntries($product) : [],
                'next_upload' => 1,
            ],
            'snapshot' => $snapshot,
            'expires_at' => now()->addHours((int) config('chatbot.ai.product.draft_ttl_hours', 24)),
        ]);
    }

    /**
     * The product's step 1 values in the form's string format.
     *
     * @return array{category_id: string, brand_id: string, model: string, sku: string, type: string, sale_mode: string, price: string}
     */
    public static function baseInfo(Product $product): array
    {
        return AiDraftProposal::info([
            'category_id' => (string) $product->category_id,
            'brand_id' => (string) ($product->brand_id ?? ''),
            'model' => (string) $product->model,
            'sku' => $product->sku,
            'type' => $product->type->value,
            'sale_mode' => $product->sale_mode->value,
            'price' => Money::tiyinToSums($product->price),
        ]);
    }
}
