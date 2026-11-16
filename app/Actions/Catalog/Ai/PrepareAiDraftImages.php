<?php

namespace App\Actions\Catalog\Ai;

use App\Models\AiProductDraft;
use App\Models\Product;
use App\Support\Media\ImageRejected;
use App\Support\Media\ProductImageCleaner;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * The image files of one AI draft (plan 32 §4, plan changes 1 and 2, PW-1 -
 * PW-4), all on the private local disk under ai-drafts/{uuid}/, all GD
 * re-encoded JPEG with server-chosen names:
 *
 *  - clean/u{n}-plain.jpg and clean/u{n}-clean.jpg for upload n (written as
 *    soon as it arrives; the client file name is never used);
 *  - clean/m{media id}-clean.jpg for an existing image the user asked to clean;
 *  - vision/{k}.jpg, the copies stage A sends (≤ ai_edge, ≤ ai_megapixels),
 *    deleted by the job and the prune; the job never deletes clean/.
 *  - preview/{name}-{mtime}.jpg, the 400 px photo step tiles (N1), deleted
 *    on discard and apply and with the folder by the prune.
 *
 * The draft row keeps the list (input.images): {source: upload|media, ref:
 * upload number or media id, ...}. Existing media are only ever resolved
 * through the product's own "images" collection, never from client input.
 */
class PrepareAiDraftImages
{
    public function __construct(private readonly ProductImageCleaner $cleaner) {}

    /**
     * Store a new upload (one per call: one decode at a time) and add it to
     * the draft. Runs under the per-draft lock on a fresh copy of the row
     * (M4): the 6-photo / 30 MB checks and the append are one step, written
     * with a conditional UPDATE (never while a stage runs or after apply).
     *
     * @return int The image's index in the draft list
     *
     * @throws ImageRejected
     * @throws ValidationException
     */
    public function addUpload(AiProductDraft $draft, UploadedFile $file): int
    {
        try {
            $index = AiDraftLock::run($draft->id, fn (): int => $this->addLocked($draft, $file));
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['upload' => __('admin.ai_wizard.errors.busy')]);
        }

        $draft->refresh();

        return $index;
    }

    /**
     * @throws ImageRejected
     * @throws ValidationException
     */
    private function addLocked(AiProductDraft $draft, UploadedFile $file): int
    {
        $draft->refresh();

        if (in_array($draft->status->value, AiDraftLock::frozen(), true) || AiDraftLock::applyStarted($draft)) {
            throw ValidationException::withMessages(['upload' => __('admin.ai_wizard.errors.state')]);
        }

        $input = $draft->input;
        $max = (int) config('chatbot.ai.product.max_images', 6);
        $totalKb = (int) config('chatbot.ai.product.upload_total_kb', 30720);
        $uploads = count(array_filter((array) ($input['images'] ?? []), fn (array $image): bool => ($image['source'] ?? null) === 'upload' && ($image['action'] ?? '') !== 'remove'));
        $bytes = (int) ($input['upload_bytes'] ?? 0) + (int) $file->getSize();

        if ($uploads >= $max) {
            throw ValidationException::withMessages(['upload' => __('admin.ai_wizard.photos.too_many', ['max' => $max])]);
        }

        if ($bytes > $totalKb * 1024) {
            throw ValidationException::withMessages(['upload' => __('admin.ai_wizard.photos.too_large_total', ['max' => (int) round($totalKb / 1024)])]);
        }

        $number = (int) ($input['next_upload'] ?? 1);
        $plain = $this->path($draft->uuid, "clean/u{$number}-plain.jpg");
        $clean = $this->path($draft->uuid, "clean/u{$number}-clean.jpg");

        try {
            $this->cleaner->plain((string) $file->getRealPath(), $plain);
            $this->cleaner->clean($plain, $clean);
        } catch (Throwable $failed) {
            // ImageRejected and any other failure (GD, disk): no half-written temp files stay behind.
            @unlink($plain);
            @unlink($clean);

            throw $failed;
        }

        $input['images'][] = ['source' => 'upload', 'ref' => $number, 'clean' => true, 'action' => 'add', 'replaces' => null];
        $input['next_upload'] = $number + 1;
        $input['upload_bytes'] = $bytes;

        if (! AiDraftLock::writeInput($draft, $input)) {
            @unlink($plain);
            @unlink($clean);

            throw ValidationException::withMessages(['upload' => __('admin.ai_wizard.errors.state')]);
        }

        return array_key_last($input['images']);
    }

    /**
     * The existing images of the product, as draft entries (keep, not cleaned).
     *
     * @return list<array{source: string, ref: int, clean: bool, action: string, replaces: null}>
     */
    public function existingEntries(Product $product): array
    {
        return $this->media($product)->map(fn (Media $media): array => [
            'source' => 'media', 'ref' => (int) $media->id, 'clean' => false, 'action' => 'keep', 'replaces' => null,
        ])->values()->all();
    }

    /**
     * Make the cleaned copy of an existing image (only a medium of this product).
     *
     * @throws ImageRejected
     */
    public function cleanExisting(AiProductDraft $draft, Product $product, int $mediaId): bool
    {
        $media = $this->media($product)->firstWhere('id', $mediaId);

        if ($media === null) {
            return false;
        }

        $target = $this->path($draft->uuid, "clean/m{$media->id}-clean.jpg");

        if (! is_file($target)) {
            $this->cleaner->clean($media->getPath(), $target);
        }

        return true;
    }

    /**
     * Vision copies of the new uploads still in the draft (at most max_images).
     *
     * @return array{paths: list<string>, map: list<int>} map: draft image index of vision image 1, 2, ...
     *
     * @throws ImageRejected
     */
    public function visionCopies(AiProductDraft $draft): array
    {
        $paths = [];
        $map = [];
        $max = (int) config('chatbot.ai.product.max_images', 6);

        foreach ($draft->input['images'] ?? [] as $index => $image) {
            if (($image['source'] ?? null) !== 'upload' || ($image['action'] ?? '') === 'remove' || count($paths) >= $max) {
                continue;
            }

            $source = $this->uploadFile($draft, (int) $image['ref'], false);

            if ($source === null) {
                continue;
            }

            $target = $this->path($draft->uuid, 'vision/'.(count($paths) + 1).'.jpg');
            $this->cleaner->vision($source, $target);
            $paths[] = $target;
            $map[] = (int) $index;
        }

        return ['paths' => $paths, 'map' => $map];
    }

    /**
     * The derived file of an upload (cleaned or plain), null when missing.
     */
    public function uploadFile(AiProductDraft $draft, int $number, bool $clean): ?string
    {
        $path = $this->path($draft->uuid, "clean/u{$number}-".($clean ? 'clean' : 'plain').'.jpg');

        return is_file($path) ? $path : null;
    }

    public function existingCleanFile(AiProductDraft $draft, int $mediaId): ?string
    {
        $path = $this->path($draft->uuid, "clean/m{$mediaId}-clean.jpg");

        return is_file($path) ? $path : null;
    }

    /**
     * The files of vision copies (for the stage request), in order.
     *
     * @return list<string>
     */
    public function visionFiles(AiProductDraft $draft): array
    {
        $files = [];

        foreach (array_keys($draft->input['vision_map'] ?? []) as $position) {
            $path = $this->path($draft->uuid, 'vision/'.($position + 1).'.jpg');

            if (is_file($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    public function deleteVision(string $uuid): void
    {
        Storage::disk('local')->deleteDirectory(AiProductDraft::folder($uuid).'/vision');
    }

    public function deleteClean(string $uuid): void
    {
        Storage::disk('local')->deleteDirectory(AiProductDraft::folder($uuid).'/clean');
    }

    public function deletePreview(string $uuid): void
    {
        Storage::disk('local')->deleteDirectory(AiProductDraft::folder($uuid).'/preview');
    }

    /**
     * The 400 px tile preview of one of the draft's own derived files, as a
     * JPEG data-URI (N1, PW-3). The JPEG is kept on the private disk at
     * preview/{name}-{mtime}.jpg (removed with the folder on discard, apply
     * and prune), never in the cache store; an older preview of the same
     * file is replaced. Null when the file cannot be read.
     */
    public function preview(AiProductDraft $draft, ?string $source): ?string
    {
        $folder = $this->path($draft->uuid, 'clean');

        if ($source === null || ! is_file($source) || dirname($source) !== $folder) {
            return null;
        }

        $name = pathinfo($source, PATHINFO_FILENAME);
        $target = $this->path($draft->uuid, 'preview/'.$name.'-'.(int) filemtime($source).'.jpg');

        if (! is_file($target)) {
            foreach (glob($this->path($draft->uuid, 'preview/'.$name.'-*.jpg')) ?: [] as $old) {
                @unlink($old);
            }

            if (! $this->cleaner->previewFile($source, $target)) {
                return null;
            }
        }

        $bytes = @file_get_contents($target);

        return is_string($bytes) && $bytes !== '' ? 'data:image/jpeg;base64,'.base64_encode($bytes) : null;
    }

    /**
     * Keep a copy of an original replaced or removed on apply until the draft expires (§14 Q6).
     */
    public function keepOriginal(AiProductDraft $draft, Media $media): void
    {
        $source = $media->getPath();
        $target = $this->path($draft->uuid, "kept/m{$media->id}.".strtolower(pathinfo($source, PATHINFO_EXTENSION) ?: 'jpg'));

        if (is_file($source) && ! is_file($target)) {
            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0750, true);
            }

            copy($source, $target);
        }
    }

    /**
     * Absolute path of a file in the draft folder (server-chosen relative name only).
     */
    public function path(string $uuid, string $relative): string
    {
        return Storage::disk('local')->path(AiProductDraft::folder($uuid).'/'.$relative);
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
            ->get();
    }
}
