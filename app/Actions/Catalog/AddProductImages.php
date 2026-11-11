<?php

namespace App\Actions\Catalog;

use App\Models\Product;
use App\Support\Media\ImageLimits;
use App\Support\Slug;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileNameNotAllowed;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileUnacceptableForCollection;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Add images to a product (collection "images", appended after the existing
 * ones). All or nothing: every file's content type is checked before any is
 * stored, and if storing one fails the rows roll back and the files already
 * written for this batch are removed.
 *
 * Stored with a random uuid name and an extension derived from the content
 * (never the client's file name). Originals stay on the private disk; the
 * WebP conversions run during the upload (no queue worker needed). A file that
 * passes the type check but cannot be converted (truncated, corrupt) fails the
 * whole batch with a localized error and leaves no rows or files behind.
 * Alt text starts empty.
 */
class AddProductImages
{
    /**
     * Content type → stored extension.
     */
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(private Filesystem $mediaFiles) {}

    /**
     * @param  list<UploadedFile>  $files
     *
     * @throws ValidationException
     */
    public function handle(Product $product, array $files, string $field = 'uploads'): void
    {
        $files = array_values($files);
        $extensions = [];

        foreach ($files as $index => $file) {
            $extensions[$index] = self::EXTENSIONS[(string) $file->getMimeType()]
                ?? throw ValidationException::withMessages(["{$field}.{$index}" => __('validation.mimes', ['attribute' => __('admin.image_upload.image'), 'values' => 'jpg, png, webp'])]);

            ImageLimits::assertWithinCap($file, "{$field}.{$index}");
        }

        /** @var list<Media> $added */
        $added = [];
        $existing = $product->media()->where('collection_name', Product::IMAGES)->pluck('id')->all();

        try {
            DB::transaction(function () use ($product, $files, $extensions, $field, $existing, &$added): void {
                foreach ($files as $index => $file) {
                    $added[] = $this->store($product, $file, $extensions[$index], "{$field}.{$index}", $existing, $added);
                }
            });
        } catch (Throwable $exception) {
            foreach ($added as $media) {
                $this->mediaFiles->removeAllFiles($media);
            }

            throw $exception;
        }
    }

    /**
     * @param  list<int>  $existing  media ids present before the batch
     * @param  list<Media>  $added  media stored so far in this batch
     *
     * @throws ValidationException
     */
    private function store(Product $product, UploadedFile $file, string $extension, string $field, array $existing, array $added): Media
    {
        try {
            return $product->addMedia($file)
                ->usingName(Slug::make($product->slug) ?: 'product')
                ->usingFileName(Str::uuid().'.'.$extension)
                ->withCustomProperties(['alt' => array_fill_keys(config('app.supported_locales'), '')])
                ->toMediaCollection(Product::IMAGES);
        } catch (FileNameNotAllowed|FileUnacceptableForCollection|FileIsTooBig $exception) {
            report($exception);

            throw ValidationException::withMessages([$field => __('admin.image_upload.rejected')]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // The conversion failed after the row and original were written: remove the half-made media.
            report($exception);

            $known = [...$existing, ...array_map(fn (Media $media): int => $media->getKey(), $added)];

            $product->media()->where('collection_name', Product::IMAGES)->whereNotIn('id', $known)->get()
                ->each(fn (Media $media) => $this->mediaFiles->removeAllFiles($media));

            throw ValidationException::withMessages([$field => __('admin.image_upload.conversion_failed')]);
        }
    }
}
