<?php

namespace App\Actions\Catalog;

use App\Models\Attribute;
use Illuminate\Validation\ValidationException;

/**
 * Delete a library attribute with its options and category assignments,
 * unless products have values for it.
 */
class DeleteAttribute
{
    /**
     * @throws ValidationException
     */
    public function handle(Attribute $attribute): void
    {
        if ($attribute->hasValues()) {
            throw ValidationException::withMessages(['attribute' => __('admin.attributes.errors.in_use')]);
        }

        $attribute->delete();
    }
}
