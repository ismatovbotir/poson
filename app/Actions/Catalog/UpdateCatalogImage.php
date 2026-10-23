<?php

namespace App\Actions\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Page;
use App\Rules\BrandSafeText;
use App\Support\Slug;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileNameNotAllowed;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileUnacceptableForCollection;

/**
 * Replace, remove or re-label the single image of a category (image), a
 * brand (logo), a page or a news post (cover). The alt text {uz, ru, en}
 * is kept in the media custom property "alt" and checked against the brand
 * rule; conversions are queued (plan 03 D10).
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
    public function handle(Category|Brand|Page|NewsPost $model, ?UploadedFile $file, array $alt, bool $remove = false, string $field = 'image'): void
    {
        $alt = $this->cleanAlt($alt, $field);

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
    private function store(Category|Brand|Page|NewsPost $model, UploadedFile $file, array $alt, string $field): void
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
     * Trimmed alt texts (at most 255 characters each). The alt is public
     * text, so the brand rule applies (BrandSafeText): a violation is
     * reported on "{field}_alt.{locale}" (e.g. image_alt.ru).
     *
     * @param  array<string, string|null>  $alt
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    private function cleanAlt(array $alt, string $field): array
    {
        $clean = [];
        $errors = [];

        foreach (config('app.supported_locales') as $locale) {
            $clean[$locale] = mb_substr(trim((string) ($alt[$locale] ?? '')), 0, 255);

            if ($clean[$locale] !== '' && BrandSafeText::violates($clean[$locale])) {
                $errors["{$field}_alt.{$locale}"] = __('validation.brand_safe', ['attribute' => __('admin.image_upload.alt').' ('.strtoupper($locale).')']);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }
}
