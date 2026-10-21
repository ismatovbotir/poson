<?php

namespace App\Actions\Catalog;

use App\Enums\DocumentType;
use App\Enums\Role;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Add or edit a product document (plan 03 D11): either an uploaded file
 * (pdf, zip, exe; ≤ 20 MB) on the private disk under
 * product-documents/{product}/{uuid}.{ext}, or an external https link —
 * exactly one of the two. Replacing the file or switching to a link
 * deletes the old file after the commit.
 *
 * Executables (exe) may only be uploaded by a user with the admin role
 * ($actor). The SHA-256 of an uploaded file is stored, so visitors can check
 * a driver or firmware download.
 */
class SaveProductDocument
{
    /**
     * @param  array{title: array<string, string|null>, type: DocumentType, locale: string|null, url: string|null}  $data
     *
     * @throws ValidationException
     */
    public function handle(Product $product, ?ProductDocument $document, array $data, ?UploadedFile $file = null, ?User $actor = null): ProductDocument
    {
        if ($document !== null && $document->product_id !== $product->id) {
            throw ValidationException::withMessages(['document' => __('admin.products.documents.errors.not_found')]);
        }

        $document ??= new ProductDocument(['product_id' => $product->id]);
        $url = filled($data['url']) ? trim((string) $data['url']) : null;
        $this->check($document, $data, $url, $file, $actor);

        $oldPath = $document->path;
        $checksum = $file !== null ? hash('sha256', (string) $file->get()) : null;
        $newPath = $file !== null ? $this->storeFile($product, $file) : null;

        try {
            DB::transaction(function () use ($product, $document, $data, $url, $file, $newPath, $checksum): void {
                $document->storeTranslations('title', $data['title']);
                $document->fill([
                    'type' => $data['type'],
                    'locale' => in_array($data['locale'], config('app.supported_locales'), true) ? $data['locale'] : null,
                    'sort' => $document->exists ? $document->sort : (int) ProductDocument::query()->where('product_id', $product->id)->max('sort') + 1,
                ]);

                if ($newPath !== null) {
                    $document->fill(['path' => $newPath, 'url' => null, 'original_name' => $this->originalName($file), 'size' => $file->getSize(), 'sha256' => $checksum]);
                } elseif ($url !== null) {
                    $document->fill(['path' => null, 'url' => $url, 'original_name' => null, 'size' => null, 'sha256' => null]);
                }

                $document->save();
            });
        } catch (Throwable $exception) {
            if ($newPath !== null) {
                Storage::disk(ProductDocument::DISK)->delete($newPath);
            }

            throw $exception;
        }

        if ($oldPath !== null && $oldPath !== $document->path) {
            Storage::disk(ProductDocument::DISK)->delete($oldPath);
        }

        return $document;
    }

    /**
     * @param  array{title: array<string, string|null>}  $data
     *
     * @throws ValidationException
     */
    private function check(ProductDocument $document, array $data, ?string $url, ?UploadedFile $file, ?User $actor): void
    {
        $errors = [];

        if (trim((string) ($data['title']['uz'] ?? '')) === '') {
            $errors['title.uz'] = __('validation.required', ['attribute' => __('admin.products.documents.title_field').' (UZ)']);
        }

        if ($file !== null && $url !== null) {
            $errors['url'] = __('admin.products.documents.errors.file_or_url');
        } elseif ($file === null && $url === null && ! $document->exists) {
            $errors['file'] = __('admin.products.documents.errors.file_or_url');
        }

        if ($url !== null && (! str_starts_with(mb_strtolower($url), 'https://') || filter_var($url, FILTER_VALIDATE_URL) === false || mb_strlen($url) > 2048)) {
            $errors['url'] = __('admin.products.documents.errors.url_https');
        }

        if ($file !== null) {
            $extension = mb_strtolower($file->getClientOriginalExtension());

            if (! in_array($extension, ProductDocument::EXTENSIONS, true)) {
                $errors['file'] = __('validation.mimes', ['attribute' => __('admin.products.documents.file'), 'values' => implode(', ', ProductDocument::EXTENSIONS)]);
            } elseif (in_array($extension, ProductDocument::EXECUTABLE_EXTENSIONS, true) && ! ($actor?->hasRole(Role::Admin) ?? false)) {
                $errors['file'] = __('admin.products.documents.errors.executable_admin_only');
            } elseif ($file->getSize() > ProductDocument::MAX_KILOBYTES * 1024) {
                $errors['file'] = __('validation.max.file', ['attribute' => __('admin.products.documents.file'), 'max' => ProductDocument::MAX_KILOBYTES]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function storeFile(Product $product, UploadedFile $file): string
    {
        $extension = mb_strtolower($file->getClientOriginalExtension());

        return $file->storeAs("product-documents/{$product->id}", Str::uuid().'.'.$extension, ProductDocument::DISK);
    }

    /**
     * Client file name for the download, without path parts or control characters.
     */
    private function originalName(UploadedFile $file): string
    {
        $name = (string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', $file->getClientOriginalName());

        return mb_substr($name !== '' ? $name : 'document.'.mb_strtolower($file->getClientOriginalExtension()), 0, 255);
    }
}
