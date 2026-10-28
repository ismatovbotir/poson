<?php

namespace App\Enums;

/**
 * Kind of row in site_visits: a page view, a lead (conversion) recorded by
 * App\Actions\Analytics\RecordConversion, or a partner click (the "Try"
 * redirect) recorded by App\Actions\Analytics\RecordPartnerClick, or an
 * "Add to cart" (plan 11a) recorded by App\Actions\Analytics\RecordAddToCart.
 */
enum VisitType: string
{
    case PageView = 'page_view';
    case Lead = 'lead';
    case PartnerClick = 'partner_click';
    case AddToCart = 'add_to_cart';

    public function label(): string
    {
        return __("analytics.enums.visit_type.{$this->value}");
    }
}
