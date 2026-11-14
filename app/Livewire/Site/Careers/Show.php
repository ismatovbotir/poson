<?php

namespace App\Livewire\Site\Careers;

use App\Models\Vacancy;
use App\Support\Careers\ApplicationForm;
use App\Support\LocaleUrl;
use App\Support\Site\SeoText;
use App\Support\Site\StructuredData;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Exceptions\HttpResponseException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Vacancy page (plan 31) at /{locale}/careers/{slug}. The slug is resolved by
 * the current locale's column first; a slug of another locale answers 301 to
 * this locale's slug (built from the model, never from input). A draft, an
 * expired or an unknown slug is the same 404 (CR-12). Description paragraphs,
 * requirements and offer lists are plain text, escaped by the view.
 * canonical and hreflang come from the three per-locale URLs (LocaleUrl::useUrls).
 * The application form posts to SubmitJobApplicationController (plan 31 §7).
 */
class Show extends Component
{
    #[Locked]
    public Vacancy $vacancy;

    public function mount(string $slug): void
    {
        $locale = app()->getLocale();
        $query = Vacancy::query()->published();

        $vacancy = (clone $query)->where("slug_{$locale}", $slug)->first();

        if ($vacancy === null) {
            $vacancy = (clone $query)->where(function ($query) use ($slug): void {
                foreach (config('app.supported_locales') as $other) {
                    $query->orWhere("slug_{$other}", $slug);
                }
            })->first();

            abort_if($vacancy === null, 404);

            throw new HttpResponseException(redirect()->to($vacancy->url($locale), 301));
        }

        $this->vacancy = $vacancy;
    }

    public function render(StructuredData $structuredData): View
    {
        // A Livewire update may arrive after the vacancy was unpublished.
        $vacancy = $this->vacancy->fresh();
        abort_unless($vacancy !== null && $vacancy->isPublic(), 404);

        $locale = app()->getLocale();
        $title = (string) $vacancy->getTranslation('title', $locale, false);
        $summary = (string) $vacancy->getTranslation('summary', $locale, false);
        $url = $vacancy->url($locale);

        LocaleUrl::useUrls(collect(config('app.supported_locales'))
            ->mapWithKeys(fn (string $code): array => [$code => $vacancy->url($code)])
            ->all());

        $breadcrumbs = [
            ['label' => __('site.breadcrumbs.home'), 'url' => route('site.home')],
            ['label' => __('careers.site.title'), 'url' => route('site.careers.index', ['locale' => $locale])],
            ['label' => $title, 'url' => null],
        ];

        return view('livewire.site.careers.show', [
            'vacancy' => $vacancy,
            'title' => $title,
            'summary' => $summary,
            'paragraphs' => preg_split('/\n{2,}/', trim((string) $vacancy->getTranslation('description', $locale, false))) ?: [],
            'requirements' => $vacancy->listFor('requirements', $locale),
            'offer' => $vacancy->listFor('offer', $locale),
            'breadcrumbs' => $breadcrumbs,
            'form' => ApplicationForm::forVacancy($vacancy),
            'jsonLd' => [
                StructuredData::toScript($structuredData->jobPosting($vacancy, $url)),
                StructuredData::toScript($structuredData->breadcrumbs($breadcrumbs, $url)),
            ],
        ])
            ->title(SeoText::title($vacancy->getTranslation('meta_title', $locale, false), $title))
            ->layoutData(['description' => SeoText::description($vacancy->getTranslation('meta_description', $locale, false), $summary), 'type' => 'article']);
    }
}
