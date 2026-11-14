<?php

namespace App\Livewire\Site\Careers;

use App\Enums\VacancyDepartment;
use App\Support\Careers\ApplicationForm;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Open application to a department (plan 31, owner decision 2026-11-14) at
 * /{locale}/careers/apply/{department}: the application form without a
 * vacancy. An unknown department (or the feature switched off) is a 404. The
 * page is noindex; the form posts to SubmitJobApplicationController.
 */
class Apply extends Component
{
    #[Locked]
    public string $department = '';

    public function mount(string $department): void
    {
        // A switched-off form shows the "closed" notice (ApplicationForm / CareersSwitch), not a 404.
        abort_unless(VacancyDepartment::tryFrom($department) !== null, 404);

        $this->department = $department;
    }

    public function render(): View
    {
        $department = VacancyDepartment::from($this->department);
        $locale = app()->getLocale();
        $breadcrumbs = [
            ['label' => __('site.breadcrumbs.home'), 'url' => route('site.home')],
            ['label' => __('careers.site.title'), 'url' => route('site.careers.index', ['locale' => $locale])],
            ['label' => __('careers.open.title'), 'url' => null],
        ];

        return view('livewire.site.careers.apply', [
            'departmentCase' => $department,
            'breadcrumbs' => $breadcrumbs,
            'form' => ApplicationForm::forDepartment($department),
        ])
            ->title(__('careers.open.page_title', ['department' => $department->label()]))
            ->layoutData(['description' => __('careers.open.intro'), 'robots' => 'noindex,follow']);
    }
}
