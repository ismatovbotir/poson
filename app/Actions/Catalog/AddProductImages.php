<?php

namespace App\Actions\Catalog;

use App\Models\Product;
use App\Support\Slug;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileNameNotAllowed;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileUnacceptableForCollection;

/**
 * Add images to a product (collection "images", appended after the existing
 * ones). All or nothing: one rejected file rolls the whole batch back.
 *
 * Stored with a random uuid name and an extension derived from the content
 * (never the client's file name). Originals stay on the private disk; the
 * WebP conversions are queued (plan 03 D10). Alt text starts empty.
 */
class AddProductImages
{
    /**
     * Content type → stored extension.
     */
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /**
     * @param  list<UploadedFile>  $files
     *
     * @throws ValidationException
     */
    public function handle(Product $product, array $files, string $field = 'uploads'): void
    {
        DB::transaction(function () use ($product, $files, $field): void {
            foreach (array_values($files) as $index => $file) {
                $this->store($product, $file, "{$field}.{$index}");
            }
        });
    }

    /**
     * @throws ValidationException
     */
    private function store(Product $product, UploadedFile $file, string $field): void
    {
        $extension = self::EXTENSIONS[(string) $file->getMimeType()] ?? null;

        if ($extension === null) {
            throw ValidationException::withMessages([$field => __('validation.mimes', ['attribute' => __('admin.image_upload.image'), 'values' => 'jpg, png, webp'])]);
        }

        try {
            $product->addMedia($file)
                ->usingName(Slug::make($product->slug) ?: 'product')
                ->usingFileName(Str::uuid().'.'.$extension)
                ->withCustomProperties(['alt' => array_fill_keys(config('app.supported_locales'), '')])
                ->toMediaCollection(Product::IMAGES);
        } catch (FileNameNotAllowed|FileUnacceptableForCollection|FileIsTooBig $exception) {
            report($exception);

            throw ValidationException::withMessages([$field => __('admin.image_upload.rejected')]);
        }
    }
}
