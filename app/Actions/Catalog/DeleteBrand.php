<?php

namespace App\Actions\Catalog;

use App\Models\Brand;
use App\Support\Media\MediaReplacer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Delete a brand and its logo. Blocked while products use the brand.
 */
class DeleteBrand
{
    public function __construct(private MediaReplacer $replacer) {}

    /**
     * @throws ValidationException
     */
    public function handle(Brand $brand): void
    {
        if ($brand->hasProducts()) {
            throw ValidationException::withMessages(['brand' => __('admin.brands.errors.has_products')]);
        }

        DB::transaction(function () use ($brand): void {
            $this->replacer->clearAll($brand);
            $brand->deletePreservingMedia();
        });
    }
}
