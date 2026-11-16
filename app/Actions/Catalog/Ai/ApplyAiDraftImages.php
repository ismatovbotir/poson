<?php

namespace App\Actions\Catalog\Ai;

use App\Actions\Catalog\AddProductImages;
use App\Actions\Catalog\UpdateProductImages;
use App\Enums\AiDraftStatus;
use App\Models\AiProductDraft;
use App\Models\Product;
use App\Support\Content\AiProduct\ProductAiDraft;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The image part of an AI draft apply (plan 32 §6, plan change 5, PW-4,
 * PW-22): runs OUTSIDE the product transaction, one image at a time, each
 * marked done in the draft's apply_state so a retry resumes. The id of every
 * medium this apply creates (returned by AddProductImages, never "the
 * newest id") is recorded in apply_state.stored right away, so a resume
 * after a crash reuses it instead of adding the image a second time (L7). Files and media
 * come only from the draft row's server-side list (input.images) and the
 * product's own "images" collection; the client only sends flags per index.
 *
 *  - upload "add": the cleaned (or plain) GD re-encoded copy via AddProductImages;
 *  - upload "replace" (confirmed): added, moved to the old image's position,
 *    the old one deleted (a copy kept in ai-drafts/{uuid}/kept until the TTL);
 *  - media "remove" (confirmed): deleted, copy kept;
 *  - media "keep" + clean (confirmed, L11): replaced in place by its cleaned copy, copy kept;
 *  - alts (create, or improve with "alts" accepted) and the main image.
 *
 * Improve: nothing here runs unless "images" (or "alts") is accepted.
 */
class ApplyAiDraftImages
{
    public function __construct(
        private readonly AddProductImages $add,
        private readonly UpdateProductImages $update,
        private readonly PrepareAiDraftImages $files,
    ) {}

    /**
     * @param  array<int, array<string, string>>  $alts  validated alts by draft image index
     * @return array{added: int, replaced: int, removed: int, alts: int}
     */
    public function handle(AiProductDraft $draft, Product $product, ProductAiDraft $data, array $alts): array
    {
        $improve = $draft->isImprove();
        $entries = array_values((array) ($draft->input['images'] ?? []));
        $state = (array) ($draft->apply_state ?? []);
        $state['done'] = (array) ($state['done'] ?? []);
        $state['stored'] = (array) ($state['stored'] ?? []);
        $counts = (array) ($state['counts'] ?? []) + ['added' => 0, 'replaced' => 0, 'removed' => 0, 'alts' => 0];

        // The first run fixes the plan (flags, confirmations, main, alts); a resume
        // (F3: "finish applying" after a crash) uses it, not the reloaded page state.
        if (! is_array($state['plan'] ?? null)) {
            $state['plan'] = [
                'images' => ! $improve || $data->accepts('images'),
                'alts' => ! $improve || $data->accepts('alts'),
                'main' => $data->main,
                'flags' => $data->images,
                'texts' => $alts,
            ];
            $this->persist($draft, $state);
        }

        $plan = $state['plan'];
        $images = (bool) ($plan['images'] ?? false);
        $withAlts = (bool) ($plan['alts'] ?? false);
        $alts = is_array($plan['texts'] ?? null) ? $plan['texts'] : [];
        $main = is_int($plan['main'] ?? null) && isset($entries[$plan['main']]) ? $plan['main'] : null;

        $order = array_keys($entries);

        if ($main !== null) {
            $order = [$main, ...array_values(array_diff($order, [$main]))];
        }

        foreach ($order as $index) {
            if (array_key_exists((string) $index, $state['done'])) {
                continue;
            }

            $entry = $entries[$index];
            $flags = $this->flags($plan['flags'][$index] ?? null, $entry);
            $mediaId = null;

            if (($entry['source'] ?? null) === 'upload') {
                $mediaId = $images ? $this->upload($draft, $product, $entries, $index, (int) $entry['ref'], $flags, $counts, $state) : null;
            } elseif (($entry['source'] ?? null) === 'media') {
                $mediaId = $this->existing($draft, $product, $index, (int) $entry['ref'], $flags, $images, $counts, $state);
            }

            if ($mediaId !== null && $withAlts && is_array($alts[$index] ?? null)) {
                $this->update->setAlt($product, $mediaId, array_filter($alts[$index], 'is_string') + array_fill_keys(config('app.supported_locales'), ''));
                $counts['alts']++;
            }

            $state['done'][(string) $index] = $mediaId;
            $state['counts'] = $counts;
            $this->persist($draft, $state);
        }

        $done = $state['done'];
        $mainMedia = $main !== null ? ($done[(string) $main] ?? null) : null;

        if ($images && $mainMedia !== null) {
            $this->update->move($product, (int) $mainMedia, 0);
        }

        return $counts;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  array{clean: bool, action: string, replaces: int|null, confirmed: bool}  $flags
     * @param  array<string, int>  $counts
     * @param  array<string, mixed>  $state
     */
    private function upload(AiProductDraft $draft, Product $product, array $entries, int $index, int $number, array $flags, array &$counts, array &$state): ?int
    {
        if (! in_array($flags['action'], ['add', 'replace'], true)) {
            return null;
        }

        $media = $this->media($product);
        $stored = $this->stored($state, $index, $media);
        $replaced = null;

        if ($flags['action'] === 'replace') {
            $target = $flags['replaces'] !== null ? ($entries[$flags['replaces']] ?? null) : null;
            $replaced = $flags['confirmed'] && ($target['source'] ?? null) === 'media' ? $media->firstWhere('id', (int) $target['ref']) : null;

            if ($replaced === null) {
                // Resumed after the swap: the new medium is in place, the old one already gone.
                return $stored;
            }
        }

        if ($stored === null) {
            $path = $this->files->uploadFile($draft, $number, $flags['clean']) ?? $this->files->uploadFile($draft, $number, ! $flags['clean']);

            if ($path === null) {
                return null;
            }

            $stored = $this->store($draft, $product, $path, $index, $state);
            $counts[$replaced !== null ? 'replaced' : 'added']++;
        }

        if ($replaced !== null) {
            $this->swap($draft, $product, $replaced, $stored);
        }

        return $stored;
    }

    /**
     * @param  array{clean: bool, action: string, replaces: int|null, confirmed: bool}  $flags
     * @param  array<string, int>  $counts
     * @param  array<string, mixed>  $state
     */
    private function existing(AiProductDraft $draft, Product $product, int $index, int $id, array $flags, bool $images, array &$counts, array &$state): ?int
    {
        $all = $this->media($product);
        $media = $all->firstWhere('id', $id);
        $stored = $this->stored($state, $index, $all);

        if ($media === null) {
            // Resumed after an in-place clean: the cleaned medium replaced it already.
            return $stored;
        }

        if ($images && $flags['action'] === 'remove' && $flags['confirmed']) {
            $this->files->keepOriginal($draft, $media);
            $this->update->delete($product, $media->id);
            $counts['removed']++;

            return null;
        }

        if ($images && $flags['action'] === 'keep' && $flags['clean'] && $flags['confirmed']) {
            if ($stored === null) {
                $path = $this->files->existingCleanFile($draft, $media->id);

                if ($path === null) {
                    return (int) $media->id;
                }

                $stored = $this->store($draft, $product, $path, $index, $state);
                $counts['replaced']++;
            }

            $this->swap($draft, $product, $media, $stored);

            return $stored;
        }

        return (int) $media->id;
    }

    /**
     * Put $newId where $old was and delete $old (a copy of its original is kept until the TTL).
     */
    private function swap(AiProductDraft $draft, Product $product, Media $old, int $newId): void
    {
        $position = $this->media($product)->search(fn (Media $media): bool => $media->id === $old->id);
        $this->files->keepOriginal($draft, $old);
        $this->update->move($product, $newId, $position === false ? 0 : (int) $position);
        $this->update->delete($product, $old->id);
    }

    /**
     * One image's flags from the stored plan (JSON), typed; the draft entry's defaults when absent.
     *
     * @param  array<string, mixed>  $entry
     * @return array{clean: bool, action: string, replaces: int|null, confirmed: bool}
     */
    private function flags(mixed $flags, array $entry): array
    {
        if (! is_array($flags)) {
            return ['clean' => (bool) ($entry['clean'] ?? false), 'action' => (string) ($entry['action'] ?? 'keep'), 'replaces' => null, 'confirmed' => false];
        }

        return [
            'clean' => (bool) ($flags['clean'] ?? false),
            'action' => is_string($flags['action'] ?? null) ? $flags['action'] : 'keep',
            'replaces' => is_int($flags['replaces'] ?? null) ? $flags['replaces'] : null,
            'confirmed' => ($flags['confirmed'] ?? false) === true,
        ];
    }

    /**
     * Add one file and record the new medium's id at once (resume, L7).
     *
     * @param  array<string, mixed>  $state
     */
    private function store(AiProductDraft $draft, Product $product, string $path, int $index, array &$state): int
    {
        $created = $this->add->handle($product, [new UploadedFile($path, 'image.jpg', 'image/jpeg', null, true)], 'images');
        $mediaId = (int) $created[0]->getKey();

        $state['stored'][(string) $index] = $mediaId;
        $this->persist($draft, $state);

        return $mediaId;
    }

    /**
     * The medium an earlier run of this apply created for the draft image, if it is still on the product.
     *
     * @param  array<string, mixed>  $state
     * @param  Collection<int, Media>  $media
     */
    private function stored(array $state, int $index, Collection $media): ?int
    {
        $id = $state['stored'][(string) $index] ?? null;

        return is_int($id) && $media->contains('id', $id) ? $id : null;
    }

    /**
     * Store the progress (only while the draft is applying; the apply lock is held).
     *
     * @param  array<string, mixed>  $state
     */
    private function persist(AiProductDraft $draft, array $state): void
    {
        AiProductDraft::query()->whereKey($draft->id)->where('status', AiDraftStatus::Applying->value)
            ->update(['apply_state' => json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
    }

    /**
     * @return Collection<int, Media>
     */
    private function media(Product $product): Collection
    {
        return Media::query()
            ->where('model_type', $product->getMorphClass())
            ->where('model_id', $product->id)
            ->where('collection_name', Product::IMAGES)
            ->orderBy('order_column')
            ->get()
            ->values();
    }
}
