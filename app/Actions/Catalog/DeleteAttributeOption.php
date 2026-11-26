<?php

namespace App\Actions\Catalog;

use App\Models\AttributeOption;
use App\Support\Site\HomeCache;
use Illuminate\Validation\ValidationException;

/**
 * Delete an attribute value, unless product values use it. The home / Mini
 * App facet cache is bumped after the commit (plan 45 MA-51).
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

        HomeCache::bump();
    }
}
