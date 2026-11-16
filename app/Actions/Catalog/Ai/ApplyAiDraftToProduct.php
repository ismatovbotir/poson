<?php

namespace App\Actions\Catalog\Ai;

use App\Actions\Catalog\SaveProduct;
use App\Enums\ActivityType;
use App\Enums\AiDraftStatus;
use App\Enums\ProductType;
use App\Models\Activity;
use App\Models\AiProductDraft;
use App\Models\Attribute;
use App\Models\Product;
use App\Models\User;
use App\Support\Catalog\ProductAttributeResolver;
use App\Support\Catalog\ProductFormData;
use App\Support\Content\AiProduct\ProductAiDraft;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Apply an improve draft to its product (plan 32 §6, plan changes 4 - 6,
 * PW-14, PW-20 - PW-22, PW-30). Only ACCEPTED keys are written: text fields
 * ("name", …), "spec:{id}", "images", "alts"; plus the step 1 values the
 * user changed (input.info against input.info_base). Fields the user left
 * unchecked are never touched.
 *
 *  1. one transaction, lock order draft → product: an applying / applied draft
 *     returns its product (idempotent); the fresh product row is locked,
 *     "update" + useType re-checked for the user; the field snapshot taken at
 *     open is recomputed and the apply refused (no write) when an accepted
 *     field, the spec rows (specs accepted) or the image set (image changes)
 *     changed meanwhile — a price sync is not a conflict unless the user
 *     changed the price; ProductFormData::fromProduct + overlay → SaveProduct
 *     with the locked instance (is_active, is_featured, badge and slug as
 *     stored; synced prices protected by SaveProduct); draft → applying;
 *  2. images outside the transaction (ApplyAiDraftImages, resumable);
 *  3. draft → applied, Activity "ai_improve" (keys and counts only), clean/
 *     deleted (kept/ originals stay until the draft TTL).
 */
class ApplyAiDraftToProduct
{
    public function __construct(
        private readonly SaveProduct $save,
        private readonly AiDraftProposal $proposal,
        private readonly ApplyAiDraftImages $images,
        private readonly PrepareAiDraftImages $files,
        private readonly ProductAttributeResolver $resolver,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, AiProductDraft $draft, ProductAiDraft $data): Product
    {
        if (! $draft->isImprove() || $draft->user_id !== $user->id || $draft->product_id === null) {
            throw new AuthorizationException;
        }

        try {
            return AiDraftLock::run($draft->id, fn (): Product => $this->run($user, $draft, $data));
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['draft' => __('admin.ai_wizard.errors.busy')]);
        }
    }

    /**
     * Step 1 keys the user changed since the wizard opened.
     *
     * @return list<string>
     */
    public static function changedInfo(AiProductDraft $draft): array
    {
        $info = AiDraftProposal::info((array) ($draft->input['info'] ?? []));
        $base = AiDraftProposal::info((array) ($draft->input['info_base'] ?? []));

        return array_values(array_keys(array_filter($info, fn (string $value, string $key): bool => $value !== $base[$key], ARRAY_FILTER_USE_BOTH)));
    }

    private function run(User $user, AiProductDraft $draft, ProductAiDraft $data): Product
    {
        $draft->refresh();
        $fields = array_values(array_filter(ProductAiDraft::TEXT_FIELDS, fn (string $field): bool => $data->accepts($field)));
        $texts = $this->proposal->texts($data->texts, $fields);
        $alts = $data->accepts('alts') ? $this->proposal->alts($data->alts, count((array) ($draft->input['images'] ?? []))) : [];
        $changedInfo = self::changedInfo($draft);
        $info = AiDraftProposal::info((array) ($draft->input['info'] ?? []));
        $specIds = AiDraftProposal::acceptedSpecIds($data);
        $imageChanges = $data->accepts('images') && $this->hasImageChanges($draft, $data);
        $specCount = 0;

        $product = DB::transaction(function () use ($user, $draft, $data, $fields, $texts, $changedInfo, $info, $specIds, $imageChanges, &$specCount): Product {
            $locked = AiProductDraft::query()->whereKey($draft->id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();

            // A resumed apply never writes to a product deleted meanwhile (N7).
            if (in_array($locked->status, [AiDraftStatus::Applying, AiDraftStatus::Applied], true)) {
                return Product::query()->find($locked->product_id)
                    ?? throw ValidationException::withMessages(['draft' => __('admin.ai_wizard.errors.state')]);
            }

            if (! in_array($locked->status, [AiDraftStatus::Ready, AiDraftStatus::Truncated], true)) {
                throw ValidationException::withMessages(['draft' => __('admin.ai_wizard.errors.state')]);
            }

            $product = Product::query()->whereKey($locked->product_id)->lockForUpdate()->first();

            if (! AiWizardGate::allowsImprove($user, $product)) {
                throw new AuthorizationException;
            }

            $newType = in_array('type', $changedInfo, true) ? ProductType::tryFrom($info['type']) : null;

            if (in_array('type', $changedInfo, true) && ($newType === null || ! Gate::forUser($user)->allows('useType', [Product::class, $newType]))) {
                throw new AuthorizationException;
            }

            $this->checkConflict($locked, $product, $fields, $changedInfo, $specIds !== [], $imageChanges || $data->accepts('alts'));

            $categoryId = in_array('category_id', $changedInfo, true) ? (int) $info['category_id'] : $product->category_id;
            $state = ProductFormData::fromProduct($product, $this->resolver->forProduct($product, $categoryId));

            foreach ($changedInfo as $key) {
                $state[$key] = $info[$key];
            }

            foreach ($texts as $field => $locales) {
                foreach ($locales as $locale => $text) {
                    $state[$field][$locale] = $text;
                }
            }

            $specs = $this->proposal->specs($data->specs, $specIds, $categoryId);
            $specCount = count($specs);
            $types = Attribute::query()->whereKey(array_keys($specs))->get(['id', 'type'])->mapWithKeys(fn (Attribute $attribute): array => [$attribute->id => $attribute->type])->all();
            $state['specs'] = AiDraftProposal::overlaySpecs($state['specs'], $specs, $types);

            $validator = Validator::make($state, ProductFormData::rules($product->id));
            $validator->validate();

            $saved = $this->save->handle($product, ProductFormData::saveData($state, $product), ProductFormData::specInput($state['specs']));

            AiDraftState::move($locked->id, [AiDraftStatus::Ready, AiDraftStatus::Truncated], AiDraftStatus::Applying, [
                'apply_state' => ['done' => [], 'counts' => [], 'fields' => array_keys($texts), 'info' => $changedInfo, 'specs' => $specCount],
            ]);

            return $saved;
        });

        $draft->refresh();

        if ($draft->status === AiDraftStatus::Applied) {
            return $product;
        }

        $counts = $this->images->handle($draft, $product, $data, $alts);

        if (AiDraftState::move($draft->id, [AiDraftStatus::Applying], AiDraftStatus::Applied, ['applied_at' => now()])) {
            $state = (array) ($draft->fresh()?->apply_state ?? []);
            $written = array_values(array_filter((array) ($state['fields'] ?? []), 'is_string'));
            $infoKeys = array_values(array_filter((array) ($state['info'] ?? []), 'is_string'));
            $specs = (int) ($state['specs'] ?? $specCount);

            Activity::query()->create([
                'subject_type' => $product->getMorphClass(),
                'subject_id' => $product->id,
                'user_id' => $user->id,
                'type' => ActivityType::AiImprove,
                'body' => AiDraftActivity::body($written, $specs, $counts, $infoKeys),
                'meta' => ['draft_id' => $draft->id, 'fields' => $written, 'info' => $infoKeys, 'specs' => $specs, 'images' => $counts],
            ]);

            $this->files->deleteClean($draft->uuid);
            $this->files->deleteVision($draft->uuid);
            $this->files->deletePreview($draft->uuid);
        }

        return $product->fresh() ?? $product;
    }

    /**
     * PW-21: refuse when something the apply would write changed since the wizard opened.
     *
     * @param  list<string>  $fields
     * @param  list<string>  $changedInfo
     *
     * @throws ValidationException
     */
    private function checkConflict(AiProductDraft $draft, Product $product, array $fields, array $changedInfo, bool $specs, bool $images): void
    {
        $changed = AiDraftSnapshot::changed((array) ($draft->snapshot ?? []), AiDraftSnapshot::of($product));
        $watched = [
            ...array_map(fn (string $field): string => "text:{$field}", $fields),
            ...array_map(fn (string $key): string => "info:{$key}", $changedInfo),
        ];

        if ($specs || in_array('category_id', $changedInfo, true)) {
            $watched[] = 'specs';
        }

        if ($images) {
            $watched[] = 'images';
        }

        if (array_intersect($watched, $changed) !== []) {
            throw ValidationException::withMessages(['draft' => __('admin.ai_wizard.errors.conflict')]);
        }
    }

    private function hasImageChanges(AiProductDraft $draft, ProductAiDraft $data): bool
    {
        foreach ((array) ($draft->input['images'] ?? []) as $index => $entry) {
            $flags = $data->images[$index] ?? null;

            if (($entry['source'] ?? null) === 'upload' && in_array($flags['action'] ?? 'add', ['add', 'replace'], true)) {
                return true;
            }

            if (($entry['source'] ?? null) === 'media' && (($flags['action'] ?? 'keep') === 'remove' || ($flags['clean'] ?? false))) {
                return true;
            }
        }

        return $data->main !== null && $data->main !== 0;
    }
}
