<?php

namespace App\Enums;

/**
 * Where a banner is shown (plan 07 7c, K11): the home slider below the
 * static hero, or the top of one category listing (page 1 only, K13).
 */
enum BannerPlacement: string
{
    case HomeHero = 'home_hero';
    case Category = 'category';

    public function label(): string
    {
        return __("enums.banner_placement.{$this->value}");
    }
}
