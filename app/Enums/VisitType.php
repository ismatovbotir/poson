<?php

namespace App\Enums;

/**
 * Kind of row in site_visits: a page view, or a lead (conversion) recorded
 * by App\Actions\Analytics\RecordConversion.
 */
enum VisitType: string
{
    case PageView = 'page_view';
    case Lead = 'lead';

    public function label(): string
    {
        return __("analytics.enums.visit_type.{$this->value}");
    }
}
