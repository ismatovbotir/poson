<?php

namespace App\Livewire\Site\Careers;

use App\Enums\VacancyDepartment;
use App\Models\Vacancy;
use App\Support\Careers\CareersSwitch;
use App\Support\Site\StructuredData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Careers list (plan 31) at /{locale}/careers: the published vacancies that
 * have not passed valid_through, in admin order (sort, id), as cards; below
 * them (or instead of them, with a short notice) the "open application" block
 * with a link per department. The page is indexable even with no vacancy,
 * because the open application is always possible (owner decision 2026-11-14).
 * Texts are plain and escaped by the view.
 */
class Index extends Component
{
    public function render(StructuredData $structuredData): View
    {
        $locale = app()->getLocale();
        $vacancies = Vacancy::query()->published()->ordered()->get();
        $listUrl = route('site.careers.index', ['locale' => $locale]);
        $breadcrumbs = [
            ['label' => __('site.breadcrumbs.home'), 'url' => route('site.home')],
            ['label' => __('careers.site.title'), 'url' => null],
        ];

        return view('livewire.site.careers.index', [
            'cards' => $vacancies->map(fn (Vacancy $vacancy): array => [
                'id' => $vacancy->id,
                'url' => $vacancy->url($locale),
                'title' => (string) $vacancy->getTranslation('title', $locale, false),
                'summary' => (string) $vacancy->getTranslation('summary', $locale, false),
                'department' => $vacancy->department->label(),
                'location' => $vacancy->location->label(),
                'type' => $vacancy->employment_type->label(),
            ])->all(),
            'departments' => CareersSwitch::openApplicationsEnabled() ? VacancyDepartment::cases() : [],
            'breadcrumbs' => $breadcrumbs,
            'jsonLd' => [
                StructuredData::toScript($structuredData->webPage('CollectionPage', __('careers.site.title'), $listUrl, __('careers.site.seo_description'))),
                StructuredData::toScript($structuredData->breadcrumbs($breadcrumbs, $listUrl)),
            ],
        ])
            ->title(__('careers.site.seo_title'))
            ->layoutData(['description' => __('careers.site.seo_description')]);
    }
}
