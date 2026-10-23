<?php

namespace App\Livewire\Site;

use App\Support\CompanySettings;
use App\Support\Site\FaqContent;
use App\Support\Site\HomeContent;
use App\Support\Site\NewsCards;
use App\Support\Site\StructuredData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Home page (/{locale}): hero, categories, popular products, B2B, why us,
 * services, brands, the latest news (plan 07 7b), the general FAQ (with
 * FAQPage JSON-LD) and the lead form. Sections without content are hidden.
 */
class Home extends Component
{
    public function render(HomeContent $content, CompanySettings $company, StructuredData $structuredData, FaqContent $faqContent): View
    {
        $faq = $faqContent->general();

        return view('livewire.site.home', [
            'heroStats' => $company->heroStats(),
            'categories' => $content->categories(),
            'products' => $content->popularProducts(),
            'services' => $content->services(),
            'brands' => $content->brands(),
            'news' => NewsCards::latest(),
            'organization' => StructuredData::toScript($structuredData->organization()),
            'localBusiness' => StructuredData::toScript($structuredData->localBusiness()),
            'faq' => $faq,
            'faqJson' => $faq !== [] ? StructuredData::toScript($structuredData->faqPage($faq)) : null,
        ])
            ->title(__('site.seo.home.title'))
            ->layoutData(['description' => __('site.seo.home.description')]);
    }
}
