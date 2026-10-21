<?php

namespace App\Actions\Catalog;

use App\Models\Brand;
use Illuminate\Validation\ValidationException;

/**
 * Delete a brand and its logo. Blocked while products use the brand.
 */
class DeleteBrand
{
    /**
     * @throws ValidationException
     */
    public function handle(Brand $brand): void
    {
        if ($brand->hasProducts()) {
            throw ValidationException::withMessages(['brand' => __('admin.brands.errors.has_products')]);
        }

        $brand->delete();
    }
}
