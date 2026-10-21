<?php

namespace App\Support\Catalog;

use App\Models\Attribute;

/**
 * One spec row of a product, as resolved by ProductAttributeResolver.
 *
 * categoryId is the category that assigns the attribute (the product's own
 * category or an ancestor); null for an extra attribute added to this
 * product only. Extras are never required or filters.
 */
final readonly class ResolvedAttribute
{
    public function __construct(
        public Attribute $attribute,
        public ?int $categoryId,
        public bool $required,
        public bool $filterable,
        public bool $skipped,
    ) {}

    public function isExtra(): bool
    {
        return $this->categoryId === null;
    }
}
