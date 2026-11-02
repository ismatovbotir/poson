<?php

namespace App\Enums;

/**
 * How the ticked options of a multiselect filter combine: All = the product
 * has every ticked option, Any = at least one.
 */
enum FilterMatch: string
{
    case All = 'all';
    case Any = 'any';

    public function label(): string
    {
        return __("enums.filter_match.{$this->value}");
    }
}
