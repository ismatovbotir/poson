<?php

namespace App\Actions\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Support\Slug;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileNameNotAllowed;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileUnacceptableForCollection;

/**
 * Replace, remove or re-label the single image of a category (image) or
 * brand (logo). The alt text {uz, ru, en} is kept in the media custom
 * property "alt"; conversions are queued (plan 03 D10).
 *
 * Call it inside the same transaction as the record's save, so a rejected
 * file rolls the whole save back. The stored file gets a random name with
 * an extension derived from its content, never the client's file name.
 */
class UpdateCatalogImage
{
    /**
     * Content type → stored extension.
     */
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /**
     * @param  array<string, string|null>  $alt  locale => alt text
     *
     * @throws ValidationException
     */
    public function handle(Category|Brand $model, ?UploadedFile $file, array $alt, bool $remove = false, string $field = 'image'): void
    {
        $alt = $this->cleanAlt($alt);

        if ($file !== null) {
            $this->store($model, $file, $alt, $field);

            return;
        }

        $media = $model->getFirstMedia($model->imageCollection());

        if ($media === null) {
            return;
        }

        if ($remove) {
            $model->clearMediaCollection($model->imageCollection());

            return;
        }

        if ($media->getCustomProperty('alt') !== $alt) {
            $media->setCustomProperty('alt', $alt)->save();
        }
    }

    /**
     * @param  array<string, string>  $alt
     *
     * @throws ValidationException
     */
    private function store(Category|Brand $model, UploadedFile $file, array $alt, string $field): void
    {
        $extension = self::EXTENSIONS[(string) $file->getMimeType()] ?? null;

        if ($extension === null) {
            throw ValidationException::withMessages([$field => __('validation.mimes', ['attribute' => __('admin.image_upload.image'), 'values' => 'jpg, png, webp'])]);
        }

        try {
            $model->addMedia($file)
                ->usingName(Slug::make($model->getAttribute('slug') ?? '') ?: $model->imageCollection())
                ->usingFileName(Str::uuid().'.'.$extension)
                ->withCustomProperties(['alt' => $alt])
                ->toMediaCollection($model->imageCollection());
        } catch (FileNameNotAllowed|FileUnacceptableForCollection|FileIsTooBig $exception) {
            report($exception);

            throw ValidationException::withMessages([$field => __('admin.image_upload.rejected')]);
        }
    }

    /**
     * @param  array<string, string|null>  $alt
     * @return array<string, string>
     */
    private function cleanAlt(array $alt): array
    {
        $clean = [];

        foreach (config('app.supported_locales') as $locale) {
            $clean[$locale] = mb_substr(trim((string) ($alt[$locale] ?? '')), 0, 255);
        }

        return $clean;
    }
}
