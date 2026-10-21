<?php

namespace App\Actions\Catalog;

use App\Models\AttributeOption;
use Illuminate\Validation\ValidationException;

/**
 * Delete an attribute value, unless product values use it.
 */
class DeleteAttributeOption
{
    /**
     * @throws ValidationException
     */
    public function handle(AttributeOption $option, string $field = 'option'): void
    {
        if ($option->isInUse()) {
            throw ValidationException::withMessages([$field => __('admin.attributes.errors.option_in_use')]);
        }

        $option->delete();
    }
}
