<?php

namespace App\Support\Ai;

use App\Enums\BannerPlacement;
use App\Livewire\Forms\Admin\Banners\BannerForm;
use App\Models\Banner;
use App\Support\Catalog\CategoryTree;
use App\Support\Catalog\HttpsUrl;
use App\Support\Site\BannerLink;

/**
 * AI context of a banner (plan 12), from the unsaved banner form: the
 * placement (home slider or a category banner, with the English category
 * path), where the button leads (an internal path, or only the host of an
 * external link), and the current title / text / button label per locale.
 * The category is resolved from the cached CategoryTree by ID (never a name
 * sent by the browser); the subject is the saved banner.
 */
final class BannerAiContext
{
    public static function fromForm(BannerForm $form, ?Banner $banner = null): AiContext
    {
        $placement = BannerPlacement::tryFrom($form->placement) ?? BannerPlacement::HomeHero;
        $categoryId = $placement === BannerPlacement::Category && ctype_digit($form->category_id) ? (int) $form->category_id : null;
        $category = $categoryId !== null && CategoryTree::find($categoryId) !== null ? CategoryTree::path($categoryId, 'en') : '';

        $facts = ['Placement' => $placement === BannerPlacement::Category ? 'Banner at the top of a catalog category page' : 'Home page slider'];

        $link = BannerLink::normalize($form->link);

        if (BannerLink::isInternal($link)) {
            $facts['Button leads to'] = (string) $link;
        } elseif (HttpsUrl::isValid($link)) {
            $facts['Button leads to'] = 'external site '.HttpsUrl::host($link);
        }

        $facts += ProductAiContext::localized('Current title', $form->title);
        $facts += ProductAiContext::localized('Current text', $form->text);
        $facts += ProductAiContext::localized('Button label', $form->button_label);

        return AiContext::fromArray([
            'name' => ProductAiContext::first($form->title),
            'category' => $category,
            'facts' => $facts,
            'subject_type' => $banner?->exists ? $banner->getMorphClass() : null,
            'subject_id' => $banner?->exists ? $banner->id : null,
        ]);
    }
}
