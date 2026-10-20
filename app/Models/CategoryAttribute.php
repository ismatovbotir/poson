<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * category_attribute pivot: per-category flags of a library attribute.
 *
 * @property int $category_id
 * @property int $attribute_id
 * @property bool $is_required
 * @property bool $is_filterable
 * @property int $sort
 */
class CategoryAttribute extends Pivot
{
    protected $table = 'category_attribute';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_filterable' => 'boolean',
            'sort' => 'integer',
        ];
    }
}
