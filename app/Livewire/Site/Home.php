<?php

namespace App\Livewire\Site;

use App\Support\CompanySettings;
use App\Support\Site\HomeContent;
use App\Support\Site\StructuredData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Home page (/{locale}): hero, categories, popular products, B2B, why us,
 * services, brands and the lead form. Sections without content are hidden.
 */
class Home extends Component
{
    public function render(HomeContent $content, CompanySettings $company, StructuredData $structuredData): View
    {
        return view('livewire.site.home', [
            'heroStats' => $company->heroStats(),
            'categories' => $content->categories(),
            'products' => $content->popularProducts(),
            'services' => $content->services(),
            'brands' => $content->brands(),
            'organization' => StructuredData::toScript($structuredData->organization()),
            'localBusiness' => StructuredData::toScript($structuredData->localBusiness()),
        ])
            ->title(__('site.seo.home.title'))
            ->layoutData(['description' => __('site.seo.home.description')]);
    }
}
