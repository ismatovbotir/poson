<?php

namespace App\Actions\Catalog\Ai;

use App\Models\AiProductDraft;
use App\Models\Product;
use App\Support\Content\AiProduct\ProductAiDraft;
use App\Support\Media\ImageRejected;
use Illuminate\Contracts\Cache\LockTimeoutException;

/**
 * Photo step flags of one draft image (plan 32 §5 step 2, plan change 2,
 * PW-4, PW-22): the client names an image only by its index in the draft
 * row's server-side list; this action reads the entry from the row and, for
 * an existing image, resolves the medium only through the product's own
 * collection (PrepareAiDraftImages). Allowed per source:
 *  - upload: add | replace (replaces = index of an existing image) | remove, clean on/off;
 *  - media (improve): keep | remove, clean on/off (makes the cleaned copy).
 * Replace / remove of an existing image is only applied later when the user
 * confirmed it on the last step. Never while a stage runs. Runs under the
 * per-draft lock on a fresh copy of the row and stores with a conditional
 * UPDATE (M4), so two quick clicks or a stage start never lose an edit.
 */
class UpdateAiDraftImage
{
    public function __construct(private readonly PrepareAiDraftImages $images) {}

    /**
     * @param  array{clean?: bool, action?: string, replaces?: int|null}  $flags
     *
     * @throws ImageRejected
     */
    public function handle(AiProductDraft $draft, ?Product $product, int $index, array $flags): bool
    {
        try {
            $changed = AiDraftLock::run($draft->id, fn (): bool => $this->update($draft, $product, $index, $flags));
        } catch (LockTimeoutException) {
            return false;
        }

        $draft->refresh();

        return $changed;
    }

    /**
     * @param  array{clean?: bool, action?: string, replaces?: int|null}  $flags
     *
     * @throws ImageRejected
     */
    private function update(AiProductDraft $draft, ?Product $product, int $index, array $flags): bool
    {
        $draft->refresh();

        if (in_array($draft->status->value, AiDraftLock::frozen(), true) || AiDraftLock::applyStarted($draft)) {
            return false;
        }

        $input = $draft->input;
        $entry = $input['images'][$index] ?? null;

        if (! is_array($entry)) {
            return false;
        }

        $upload = ($entry['source'] ?? null) === 'upload';
        $action = $flags['action'] ?? $entry['action'];
        $allowed = $upload ? ['add', 'replace', 'remove'] : ['keep', 'remove'];

        if (! is_string($action) || ! in_array($action, $allowed, true) || ! in_array($action, ProductAiDraft::IMAGE_ACTIONS, true)) {
            return false;
        }

        $replaces = null;

        if ($action === 'replace') {
            $replaces = $flags['replaces'] ?? null;
            $target = is_int($replaces) ? ($input['images'][$replaces] ?? null) : null;

            if (! $draft->isImprove() || ($target['source'] ?? null) !== 'media') {
                return false;
            }
        }

        $clean = (bool) ($flags['clean'] ?? $entry['clean']);

        if (! $upload && $clean && ! $entry['clean']) {
            if ($product === null || ! $this->images->cleanExisting($draft, $product, (int) $entry['ref'])) {
                return false;
            }
        }

        $input['images'][$index] = [...$entry, 'action' => $action, 'replaces' => $replaces, 'clean' => $clean];

        return AiDraftLock::writeInput($draft, $input);
    }
}
